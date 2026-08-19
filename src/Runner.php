<?php

namespace emailsync;

final class Runner {
	public static function runDue(): array {
		$job = JobStore::due(time());
		return $job ? self::run((string) $job['id']) : ['success'=>true,'status'=>'idle'];
	}

	public static function run(string $id): array {
		$job = JobStore::get($id);
		if (!$job || (int) ($job['active'] ?? 0) !== 1 || ($job['phase'] ?? '') === 'completed') return ['success'=>false,'status'=>'not_runnable'];

		$currentMx = DnsMonitor::snapshot((string) ($job['domain'] ?? ''));
		if (empty($job['baseline_mx']['fingerprint']) && !empty($currentMx['fingerprint'])) $job['baseline_mx'] = $currentMx;
		$cutover = DnsMonitor::cutover((array) ($job['baseline_mx'] ?? []),$currentMx,(array) ($job['expected_mx'] ?? []));
		if ($cutover && empty($job['cutover_detected'])) $job['cutover_detected'] = time();
		$job['current_mx'] = $currentMx;
		$job['last_run'] = time();
		$job['last_result'] = 'running';
		$job['run_count'] = (int) ($job['run_count'] ?? 0) + 1;
		JobStore::update($id,fn(array $stored): array => array_merge($stored,$job));

		$source = \imap\ConnectionStore::credentials((string) ($job['source_id'] ?? ''));
		$destination = \imap\ConnectionStore::credentials((string) ($job['destination_id'] ?? ''));
		$result = MailboxSync::run($job,$source,$destination);
		$finished = (int) ($result['finished'] ?? time());

		if (empty($result['success'])) {
			$firstFailure = (int) ($job['failure_count'] ?? 0) === 0;
			$job = JobStore::update($id,function(array $stored) use ($result,$finished): array {
				$stored['last_result'] = 'failed';
				$stored['last_error'] = (string) ($result['error'] ?? 'unknown_error');
				$stored['failure_count'] = (int) ($stored['failure_count'] ?? 0) + 1;
				$stored['next_run'] = $finished + max(300,(int) ($stored['interval'] ?? 3600));
				$stored['last_summary'] = self::summary($result);
				if ((int) ($result['stats']['messages_discovered'] ?? 0) > 0) $stored['last_source_change'] = $finished;
				return $stored;
			});
			if ($firstFailure) JobStore::event($id,'failed',self::summary($result));
			return $result + ['job'=>$job];
		}

		$wasInitial = ($job['phase'] ?? 'initial') === 'initial';
		$job = JobStore::update($id,function(array $stored) use ($result,$finished,$wasInitial): array {
			$discovered = $result['stats']['messages_discovered'] ?? null;
			$stored['last_result'] = 'success';
			$stored['last_error'] = '';
			$stored['failure_count'] = 0;
			$stored['last_success'] = $finished;
			$stored['next_run'] = $finished + max(60,(int) ($stored['interval'] ?? 3600));
			$stored['last_summary'] = self::summary($result);
			if ($wasInitial) {
				$stored['phase'] = 'monitoring';
				$stored['initial_completed'] = $finished;
				$stored['last_source_change'] = $finished;
			} elseif ($discovered !== null && $discovered > 0) $stored['last_source_change'] = $finished;
			return $stored;
		});

		if ($wasInitial) JobStore::event($id,'initial_complete',self::summary($result));
		if (!self::complete($job,$result,$finished)) return $result + ['job'=>$job];

		$job = JobStore::update($id,function(array $stored) use ($finished): array {
			$stored['phase'] = 'completed';
			$stored['active'] = 0;
			$stored['completed'] = $finished;
			$stored['next_run'] = 0;
			return $stored;
		});
		\imap\ConnectionStore::purgeSecret((string) ($job['source_id'] ?? ''));
		\imap\ConnectionStore::purgeSecret((string) ($job['destination_id'] ?? ''));
		JobStore::event($id,'completed',self::summary($result));
		return $result + ['job'=>$job];
	}

	private static function complete(array $job, array $result, int $finished): bool {
		if (($job['phase'] ?? '') !== 'monitoring' || empty($job['cutover_detected'])) return false;
		if (!array_key_exists('messages_discovered',(array) ($result['stats'] ?? [])) || (int) $result['stats']['messages_discovered'] !== 0) return false;
		$ttl = max((int) ($job['fallback_ttl'] ?? 86400),(int) ($job['baseline_mx']['ttl'] ?? 0));
		$cutover = (int) $job['cutover_detected'];
		$minimumEnd = $cutover + (int) ($job['minimum_monitoring'] ?? 604800);
		$quietAnchor = max($cutover + $ttl,(int) ($job['last_source_change'] ?? 0));
		return $finished >= max($minimumEnd,$quietAnchor + (int) ($job['quiet_period'] ?? 172800));
	}

	private static function summary(array $result): array {
		return [
			'success'=>!empty($result['success']) ? 1 : 0,
			'exit_code'=>(int) ($result['exit_code'] ?? -1),
			'error'=>(string) ($result['error'] ?? ''),
			'started'=>(int) ($result['started'] ?? 0),
			'finished'=>(int) ($result['finished'] ?? 0),
			'duration'=>(int) ($result['duration'] ?? 0),
			'log'=>(string) ($result['log'] ?? ''),
			'stats'=>(array) ($result['stats'] ?? [])
		];
	}
}
