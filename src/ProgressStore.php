<?php

namespace emailsync;

final class ProgressStore {
	public static function get(string $jobId): array {
		return State::read('progress/'.self::id($jobId).'.json');
	}

	public static function checkpoint(string $jobId, string $mailbox, int $uidValidity, int $uid, int $destinationUid = 0): array {
		$key = hash('sha256',$mailbox);
		$updated = State::update('progress/'.self::id($jobId).'.json',function(array $progress) use ($key,$mailbox,$uidValidity,$uid,$destinationUid): array {
			if (!isset($progress['folders']) || !is_array($progress['folders'])) $progress['folders'] = [];
			$progress['folders'][$key] = [
				'mailbox'=>$mailbox,
				'uidvalidity'=>$uidValidity,
				'last_uid'=>$uid,
				'destination_uid'=>$destinationUid,
				'updated'=>(int) ($_SERVER['now'] ?? time())
			];
			return $progress;
		});
		if (!is_array($updated)) throw new \RuntimeException('progress_write_failed');
		return $updated;
	}

	public static function folder(array $progress, string $mailbox): array {
		$folder = $progress['folders'][hash('sha256',$mailbox)] ?? [];
		return is_array($folder) ? $folder : [];
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
