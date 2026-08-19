<?php

namespace emailsync;

use RuntimeException;
use Throwable;

final class MailboxSync {
	private const SLICE_SECONDS = 5;

	public static function probe(): array {
		$available = class_exists(\imap\Client::class) && \imap\Client::available() && function_exists('sodium_crypto_secretbox');
		return [
			'available'=>$available ? 1 : 0,
			'checked'=>(int) ($_SERVER['now'] ?? time()),
			'version'=>'Pure PHP IMAP/TLS',
			'error'=>$available ? '' : 'imap_transport_unavailable'
		];
	}

	public static function test(array $connection): array {
		$client = null;
		try {
			$host = substr(strtolower(rtrim(trim((string) ($connection['host'] ?? '')),'.')),0,253);
			if (!filter_var($host,FILTER_VALIDATE_IP) && !filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) throw new RuntimeException('imap_host_invalid');
			$connection = [
				'host'=>$host,
				'port'=>max(1,min(65535,(int) ($connection['port'] ?? 993))),
				'security'=>($connection['security'] ?? '') === 'tls' ? 'tls' : 'ssl',
				'username'=>substr(trim((string) ($connection['username'] ?? '')),0,320),
				'password'=>(string) ($connection['password'] ?? ''),
				'auth'=>'password'
			];
			$client = new \imap\Client($connection);
			$client->connect();
			$folders = $client->folders();
			return ['result'=>true,'folders'=>count($folders)];
		} catch (Throwable $exception) {
			return ['result'=>false,'error'=>substr($exception->getMessage() ?: 'imap_connection_failed',0,160)];
		} finally {
			if ($client) $client->close();
		}
	}

	public static function run(array $job, array $source, array $destination): array {
		$started = time();
		$id = preg_replace('/[^a-z0-9_.-]/i','',(string) ($job['id'] ?? ''));
		$logName = 'runs/'.$id.'/'.date('Ymd-His',$started).'-'.bin2hex(random_bytes(3)).'.log';
		$result = [
			'success'=>false,
			'exit_code'=>1,
			'error'=>'',
			'started'=>$started,
			'finished'=>0,
			'duration'=>0,
			'partial'=>0,
			'log'=>State::path($logName),
			'stats'=>self::stats()
		];
		$clients = [];

		try {
			if ($id === '') throw new RuntimeException('job_invalid');
			ProgressStore::start($id);
			$clients = [new \imap\Client($source),new \imap\Client($destination)];
			foreach ($clients as $client) $client->connect();
			$result['partial'] = self::transfer($id,$clients[0],$clients[1],$result['stats']) ? 0 : 1;
			$result['success'] = true;
			$result['exit_code'] = 0;
		} catch (Throwable $exception) {
			$result['error'] = substr($exception->getMessage() ?: 'imap_sync_failed',0,160);
			$result['stats']['errors'] = 1;
		} finally {
			foreach (array_reverse($clients) as $client) $client->close();
			if ($id !== '') ProgressStore::finish($id,!empty($result['success']),empty($result['partial']));
		}

		$result['finished'] = time();
		$result['duration'] = max(0,$result['finished'] - $result['started']);
		self::log($logName,$result);
		return $result;
	}

	private static function transfer(string $jobId, \imap\Client $source, \imap\Client $destination, array &$stats): bool {
		$progress = ProgressStore::get($jobId);
		$sourceFolders = $source->folders();
		$destinationFolders = $destination->folders();
		if (!$sourceFolders) throw new RuntimeException('source_folders_missing');
		$destinationNames = [];
		foreach ($destinationFolders as $folder) $destinationNames[strtolower((string) $folder['name'])] = (string) $folder['name'];
		$destinationDelimiter = (string) ($destinationFolders[0]['delimiter'] ?? '/');
		$destinationPrefix = self::destinationPrefix($destinationFolders,$destinationDelimiter);
		$destinationSpecial = self::specialFolders($destinationFolders);
		usort($sourceFolders,fn(array $first, array $second): int => [strcasecmp((string) $first['name'],'INBOX') !== 0,(string) $first['name']] <=> [strcasecmp((string) $second['name'],'INBOX') !== 0,(string) $second['name']]);

		$plan = [];
		$total = 0;
		foreach ($sourceFolders as $folder) {
			$sourceName = (string) $folder['name'];
			$destinationName = self::specialDestination((array) ($folder['attributes'] ?? []),$destinationSpecial);
			if ($destinationName === '') $destinationName = self::destinationName($sourceName,(string) ($folder['delimiter'] ?? '/'),$destinationDelimiter,$destinationPrefix);
			$sourceStatus = $source->select($sourceName,true);
			$stored = ProgressStore::folder($progress,$sourceName);
			if (!empty($stored['uidvalidity']) && (int) $stored['uidvalidity'] !== $sourceStatus['uidvalidity']) throw new RuntimeException('source_uidvalidity_changed');
			$lastUid = (int) ($stored['last_uid'] ?? 0);
			$uids = $source->uidsAfter($lastUid);
			$stats['messages_source'] += (int) $sourceStatus['exists'];
			$total += count($uids);
			$plan[] = ['source'=>$sourceName,'destination'=>$destinationName,'status'=>$sourceStatus,'stored'=>$stored,'uids'=>$uids];
		}
		$processed = max(0,$stats['messages_source'] - $total);
		ProgressStore::plan($jobId,$stats['messages_source'],$processed);

		$sliceProcessed = 0;
		helper__system_load('emailsync_transfer');
		foreach ($plan as $folder) {
			$sourceName = $folder['source'];
			$destinationName = $folder['destination'];
			$destinationKey = strtolower($destinationName);
			if (!isset($destinationNames[$destinationKey])) {
				$destination->create($destinationName);
				$destinationNames[$destinationKey] = $destinationName;
			}
			$source->select($sourceName,true);
			$sourceStatus = $folder['status'];
			$stored = $folder['stored'];
			$uids = $folder['uids'];
			$destinationStatus = $destination->select($destinationNames[$destinationKey]);
			$stats['messages_destination'] += (int) $destinationStatus['exists'];

			if (!$stored) {
				$progress = ProgressStore::checkpoint($jobId,$sourceName,(int) $sourceStatus['uidvalidity'],0,0,$processed);
				$stored = ProgressStore::folder($progress,$sourceName);
			}

			foreach ($uids as $uid) {
				if ($sliceProcessed > 0 && !helper__system_load_check('emailsync_transfer',self::SLICE_SECONDS)) return false;
				$stats['messages_discovered']++;
				$message = $source->fetch($uid);
				try {
					$hash = \imap\Client::hash($message['stream']);
					$messageId = self::messageId($message['stream']);
					$candidates = $messageId === '' ? [] : $destination->uidsByHeader('Message-ID',$messageId);
					if (!$candidates) $candidates = $destination->uidsBySize((int) $message['size']);
					if (self::exists($destination,$candidates,(int) $message['size'],$hash)) {
						$stats['messages_skipped']++;
						$destinationUid = 0;
					} else {
						$destinationUid = $destination->append($destinationNames[$destinationKey],$message['stream'],(int) $message['size'],(array) $message['flags'],(string) $message['internaldate']);
						$stats['messages_transferred']++;
						$stats['bytes_transferred'] += (int) $message['size'];
					}
				} finally {
					fclose($message['stream']);
				}
				$processed++;
				$sliceProcessed++;
				$progress = ProgressStore::checkpoint($jobId,$sourceName,(int) $sourceStatus['uidvalidity'],$uid,$destinationUid,$processed);
				$stats['messages_synchronized']++;
			}
			$destination->subscribe($destinationNames[$destinationKey]);
		}
		return true;
	}

	private static function exists(\imap\Client $destination, array $uids, int $size, string $hash): bool {
		foreach ($uids as $uid) {
			$candidate = $destination->fetch((int) $uid);
			try {
				if ((int) $candidate['size'] === $size && hash_equals($hash,\imap\Client::hash($candidate['stream']))) return true;
			} finally {
				fclose($candidate['stream']);
			}
		}
		return false;
	}

	private static function messageId($stream): string {
		rewind($stream);
		$headers = '';
		while (!feof($stream) && strlen($headers) < 262144) {
			$line = fgets($stream);
			if ($line === false || $line === "\r\n" || $line === "\n") break;
			$headers .= $line;
		}
		rewind($stream);
		$headers = preg_replace("/\r?\n[\t ]+/",' ',$headers);
		return preg_match('/^Message-ID:\s*(.+)$/im',(string) $headers,$match) ? substr(trim($match[1]),0,998) : '';
	}

	private static function destinationName(string $source, string $sourceDelimiter, string $destinationDelimiter, string $destinationPrefix = ''): string {
		if (strcasecmp($source,'INBOX') === 0) return 'INBOX';
		$name = $sourceDelimiter === '' || $sourceDelimiter === $destinationDelimiter ? $source : implode($destinationDelimiter,explode($sourceDelimiter,$source));
		return $destinationPrefix !== '' && !str_starts_with(strtolower($name),strtolower($destinationPrefix)) ? $destinationPrefix.$name : $name;
	}

	private static function destinationPrefix(array $folders, string $delimiter): string {
		if ($delimiter === '') return '';
		$prefix = 'INBOX'.$delimiter;
		$found = false;
		foreach ($folders as $folder) {
			$name = (string) ($folder['name'] ?? '');
			if (strcasecmp($name,'INBOX') === 0) continue;
			$found = true;
			if (!str_starts_with(strtolower($name),strtolower($prefix))) return '';
		}
		return $found ? $prefix : '';
	}

	private static function specialFolders(array $folders): array {
		$special = [];
		foreach ($folders as $folder) foreach ((array) ($folder['attributes'] ?? []) as $attribute) {
			$attribute = strtolower((string) $attribute);
			if (!in_array($attribute,['\\archive','\\drafts','\\junk','\\sent','\\trash'],true) || isset($special[$attribute])) continue;
			$special[$attribute] = (string) ($folder['name'] ?? '');
		}
		return $special;
	}

	private static function specialDestination(array $attributes, array $special): string {
		foreach ($attributes as $attribute) if (isset($special[strtolower((string) $attribute)])) return $special[strtolower((string) $attribute)];
		return '';
	}

	private static function stats(): array {
		return [
			'messages_discovered'=>0,
			'messages_synchronized'=>0,
			'messages_transferred'=>0,
			'messages_skipped'=>0,
			'messages_source'=>0,
			'messages_destination'=>0,
			'bytes_transferred'=>0,
			'errors'=>0
		];
	}

	private static function log(string $name, array $result): void {
		$lines = [
			'fiCMS Email Sync',
			'Status: '.(!empty($result['success']) ? 'success' : 'failed'),
			'Error: '.((string) ($result['error'] ?? '') ?: 'none'),
			'Duration: '.(int) ($result['duration'] ?? 0),
			'New source messages: '.(int) ($result['stats']['messages_discovered'] ?? 0),
			'Messages transferred: '.(int) ($result['stats']['messages_transferred'] ?? 0),
			'Messages skipped: '.(int) ($result['stats']['messages_skipped'] ?? 0),
			'Bytes transferred: '.(int) ($result['stats']['bytes_transferred'] ?? 0)
		];
		State::write($name,implode(PHP_EOL,$lines).PHP_EOL,true);
	}
}
