<?php

namespace emailsync;

final class JobConfiguration {
	public static function fields(): array {
		$fields = ['name','report_email'];
		foreach (['source','destination'] as $side) foreach (['host','port','security','username','password'] as $field) $fields[] = $side.'_'.$field;
		return $fields;
	}

	public static function testConnection(array $data, string $id, string $side): array {
		if (!in_array($side,['source','destination'],true)) return ['result'=>false,'error'=>'invalid_configuration'];
		$job = $id !== '' && $id !== 'new' ? JobStore::get($id) : [];
		$stored = !empty($job[$side.'_id']) ? \imap\ConnectionStore::get((string) $job[$side.'_id']) : [];
		$connection = [];
		foreach (['host'=>'','port'=>993,'security'=>'ssl','username'=>''] as $field => $default) $connection[$field] = $data[$side.'_'.$field] ?? $default;
		$connection['password'] = (string) ($data[$side.'_password'] ?? '');
		$identityChanged = false;
		foreach (['host','port','security','username'] as $field) if ((string) ($stored[$field] ?? '') !== (string) $connection[$field]) $identityChanged = true;
		if ($identityChanged && $connection['password'] !== '' && hash_equals((string) (\imap\ConnectionStore::credentials((string) ($stored['id'] ?? ''))['password'] ?? ''),$connection['password'])) return ['result'=>false,'error'=>'imap_credentials_missing'];
		if ($connection['password'] === '') {
			foreach (['host','port','security','username'] as $field) if ((string) ($stored[$field] ?? '') !== (string) $connection[$field]) return ['result'=>false,'error'=>'imap_credentials_missing'];
			$connection['password'] = (string) (\imap\ConnectionStore::credentials((string) ($stored['id'] ?? ''))['password'] ?? '');
		}
		if ($connection['password'] === '') return ['result'=>false,'error'=>'imap_credentials_missing'];
		return MailboxSync::test($connection);
	}

	public static function save(array $data, string $id = ''): array {
		$current = $id !== '' ? JobStore::get($id) : [];
		if ($id !== '' && !$current) return ['result'=>false,'error'=>'job_not_found'];
		if (($current['phase'] ?? '') === 'completed') return ['result'=>false,'error'=>'job_completed'];
		$name = trim((string) ($data['name'] ?? $current['name'] ?? ''));
		$email = trim((string) ($data['report_email'] ?? $current['report_email'] ?? ''));
		if ($name === '' || !filter_var($email,FILTER_VALIDATE_EMAIL)) return ['result'=>false,'error'=>'invalid_configuration'];
		$tables = $GLOBALS['tables'] ?? [];
		$recipient = isset($tables['user']) ? mysqlFetchAssoc("SELECT `id` FROM ".$tables['user']." WHERE `ac` = 1 AND `email` = '".mysqlEscape($email)."' LIMIT 1") : false;
		if (empty($recipient['id'])) return ['result'=>false,'error'=>'report_user_missing'];

		$connections = [];
		$previousIds = [];
		$changed = !$current;
		foreach (['source','destination'] as $side) {
			$stored = !empty($current[$side.'_id']) ? \imap\ConnectionStore::get((string) $current[$side.'_id']) : [];
			$previousIds[$side] = (string) ($stored['id'] ?? '');
			$connection = ['id'=>$stored['id'] ?? '', 'name'=>$name.' '.$side, 'purpose'=>'migration', 'auth'=>'password'];
			foreach (['host'=>'','port'=>993,'security'=>'ssl','username'=>''] as $field => $default) {
				$connection[$field] = $data[$side.'_'.$field] ?? $stored[$field] ?? $default;
				if ($field !== 'port') $connection[$field] = trim((string) $connection[$field]);
			}
			$connection['host'] = strtolower(rtrim($connection['host'],'.'));
			$connection['port'] = (int) $connection['port'];
			if (($connection['host'] !== '' && !filter_var($connection['host'],FILTER_VALIDATE_IP) && !filter_var($connection['host'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))
				|| $connection['port'] < 1 || $connection['port'] > 65535 || !in_array($connection['security'],['ssl','tls'],true)) return ['result'=>false,'error'=>'invalid_configuration'];
			$identityChanged = false;
			foreach (['host','port','security','username'] as $field) if ((string) ($stored[$field] ?? '') !== (string) $connection[$field]) $identityChanged = true;
			if ($identityChanged && (int) ($current['run_count'] ?? 0) > 0) return ['result'=>false,'error'=>'mailbox_identity_locked'];
			$password = (string) ($data[$side.'_password'] ?? '');
			// A revealed value resubmitted unchanged is never a new credential, even after editing the server.
			if ($password !== '' && hash_equals((string) (\imap\ConnectionStore::credentials((string) ($stored['id'] ?? ''))['password'] ?? ''),$password)) $password = '';
			if ($identityChanged || $password !== '') $connection['id'] = '';
			$changed = $changed || $identityChanged || $password !== '';
			$connections[$side] = ['metadata'=>$connection,'password'=>$password,'stored'=>$stored];
		}
		$domain = DnsMonitor::sourceDomain($connections['source']['metadata']['username']);
		if ($domain === '') return ['result'=>false,'error'=>'mail_domain_missing'];
		$created = [];
		foreach ($connections as $side => $connection) {
			if ($connection['metadata']['id'] !== '') {
				$connections[$side] = $connection['stored'];
				continue;
			}
			$connections[$side] = \imap\ConnectionStore::save($connection['metadata'],$connection['password']);
			$created[] = (string) ($connections[$side]['id'] ?? '');
			$persisted = \imap\ConnectionStore::credentials(end($created));
			$valid = !empty($persisted['id']);
			foreach (['host','port','security','username'] as $field) if ((string) ($persisted[$field] ?? '') !== (string) $connection['metadata'][$field]) $valid = false;
			if (!hash_equals($connection['password'],(string) ($persisted['password'] ?? ''))) $valid = false;
			if (!$valid) {
				self::removeConnections($created);
				return ['result'=>false,'error'=>'connection_write_failed'];
			}
		}
		$phase = (string) ($current['phase'] ?? 'draft');
		if ($changed && $phase === 'initial') $phase = 'draft';
		$job = JobStore::save(array_merge($current,[
			'id'=>$current['id'] ?? '', 'name'=>$name, 'domain'=>$domain,
			'source_id'=>$connections['source']['id'], 'destination_id'=>$connections['destination']['id'],
			'phase'=>$phase, 'active'=>$changed ? 0 : (int) ($current['active'] ?? 0),
			'configuration_revision'=>bin2hex(random_bytes(16)),
			'report_user_id'=>(int) $recipient['id'], 'report_email'=>$email
		]),$current ?: null);
		if (!$job) {
			self::removeConnections($created);
			return ['result'=>false,'error'=>'job_write_failed'];
		}
		foreach ($previousIds as $side => $previousId) {
			if ($previousId === '' || $previousId === $job[$side.'_id']) continue;
			$inUse = false;
			foreach (JobStore::list() as $stored) if (($stored['source_id'] ?? '') === $previousId || ($stored['destination_id'] ?? '') === $previousId) $inUse = true;
			if (!$inUse) \imap\ConnectionStore::delete($previousId);
		}
		return ['result'=>true,'id'=>$job['id'],'draft'=>$job['phase'] === 'draft'];
	}

	public static function setActive(string $id, bool $active): array {
		$job = JobStore::get($id);
		if (!$job) return ['result'=>false,'error'=>'job_not_found'];
		if (($job['phase'] ?? '') === 'completed') return ['result'=>false,'error'=>'job_completed'];
		if ($active) {
			$errors = [];
			foreach (['source','destination'] as $side) {
				$connection = \imap\ConnectionStore::credentials((string) ($job[$side.'_id'] ?? ''));
				if (empty($connection['host']) || empty($connection['username']) || empty($connection['password'])) {
					$errors[$side] = 'imap_credentials_missing';
					continue;
				}
				$result = MailboxSync::test($connection);
				if (empty($result['result'])) $errors[$side] = $result['error'] ?? 'imap_connection_failed';
			}
			if ($errors) return ['result'=>false,'error'=>'connections_not_ready','connections'=>$errors];
		}
		$applied = false;
		$updated = JobStore::update($id,function(array $stored) use ($active,$job,&$applied): array {
			if ($active) {
				foreach (['source_id','destination_id','configuration_revision'] as $field) if (($stored[$field] ?? '') !== ($job[$field] ?? '')) return $stored;
				if (($stored['phase'] ?? '') === 'completed') return $stored;
			}
			$applied = true;
			$stored['configuration_revision'] = bin2hex(random_bytes(16));
			$stored['active'] = $active ? 1 : 0;
			if ($active) {
				if (($stored['phase'] ?? '') === 'draft') $stored['phase'] = 'initial';
				$stored['next_run'] = 0;
			}
			return $stored;
		});
		if (!$updated) return ['result'=>false,'error'=>'job_write_failed'];
		if (!$applied) return ['result'=>false,'error'=>'configuration_changed'];
		if ($active) \ficms\Runtime::check('emailsync_worker',(int) ($_SERVER['now'] ?? time()),true,'seconds');
		return ['result'=>true,'id'=>$id];
	}
	private static function removeConnections(array $ids): void {
		foreach ($ids as $id) if ($id !== '') \imap\ConnectionStore::delete($id);
	}
}
