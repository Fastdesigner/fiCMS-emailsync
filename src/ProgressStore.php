<?php

namespace emailsync;

final class ProgressStore {
	public static function get(string $jobId): array {
		return State::read('progress/'.self::id($jobId).'.json');
	}

	public static function start(string $jobId): array {
		$updated = State::update('progress/'.self::id($jobId).'.json',function(array $progress): array {
			$progress['run'] = [
				'started'=>(int) ($_SERVER['now'] ?? time()),
				'finished'=>0,
				'total'=>0,
				'processed'=>0,
				'success'=>0
			];
			return $progress;
		});
		if (!is_array($updated)) throw new \RuntimeException('progress_write_failed');
		return $updated;
	}

	public static function plan(string $jobId, int $total): array {
		$updated = State::update('progress/'.self::id($jobId).'.json',function(array $progress) use ($total): array {
			if (!isset($progress['run']) || !is_array($progress['run'])) $progress['run'] = [];
			$progress['run']['total'] = max(0,$total);
			$progress['run']['processed'] = min(max(0,(int) ($progress['run']['processed'] ?? 0)),$progress['run']['total']);
			return $progress;
		});
		if (!is_array($updated)) throw new \RuntimeException('progress_write_failed');
		return $updated;
	}

	public static function checkpoint(string $jobId, string $mailbox, int $uidValidity, int $uid, int $destinationUid = 0, int $processed = 0): array {
		$key = hash('sha256',$mailbox);
		$updated = State::update('progress/'.self::id($jobId).'.json',function(array $progress) use ($key,$mailbox,$uidValidity,$uid,$destinationUid,$processed): array {
			if (!isset($progress['folders']) || !is_array($progress['folders'])) $progress['folders'] = [];
			$progress['folders'][$key] = [
				'mailbox'=>$mailbox,
				'uidvalidity'=>$uidValidity,
				'last_uid'=>$uid,
				'destination_uid'=>$destinationUid,
				'updated'=>(int) ($_SERVER['now'] ?? time())
			];
			if (!isset($progress['run']) || !is_array($progress['run'])) $progress['run'] = [];
			$progress['run']['processed'] = max(0,$processed);
			return $progress;
		});
		if (!is_array($updated)) throw new \RuntimeException('progress_write_failed');
		return $updated;
	}

	public static function folder(array $progress, string $mailbox): array {
		$folder = $progress['folders'][hash('sha256',$mailbox)] ?? [];
		return is_array($folder) ? $folder : [];
	}

	public static function finish(string $jobId, bool $success): array {
		$updated = State::update('progress/'.self::id($jobId).'.json',function(array $progress) use ($success): array {
			if (!isset($progress['run']) || !is_array($progress['run'])) $progress['run'] = [];
			$progress['run']['finished'] = (int) ($_SERVER['now'] ?? time());
			$progress['run']['success'] = $success ? 1 : 0;
			if ($success) $progress['run']['processed'] = max(0,(int) ($progress['run']['total'] ?? 0));
			return $progress;
		});
		if (!is_array($updated)) throw new \RuntimeException('progress_write_failed');
		return $updated;
	}

	public static function ratio(array $job): float {
		if (($job['phase'] ?? '') === 'completed') return 1;
		$run = (array) (self::get((string) ($job['id'] ?? ''))['run'] ?? []);
		$total = max(0,(int) ($run['total'] ?? 0));
		if ($total > 0) return min(1,max(0,(int) ($run['processed'] ?? 0)) / $total);
		if (($job['phase'] ?? '') === 'monitoring' || !empty($run['success'])) return 1;
		return 0;
	}

	public static function delete(string $jobId): bool {
		return State::delete('progress/'.self::id($jobId).'.json');
	}

	private static function id(string $id): string {
		$id = preg_replace('/[^a-z0-9_.-]/i','',trim($id));
		if ($id === '') throw new \InvalidArgumentException('emailsync_job_id_invalid');
		return $id;
	}
}
