<?php

namespace emailsync;

final class Runner {
	public const POLICY_VERSION = '2026-10-22';
	public static function runDue(): array {
		$job = JobStore::due(time());
		try {
			if (!$job) return ['success'=>true,'status'=>'idle'];
			if (!\ficms\Runtime::grant(2)) return ['success'=>true,'status'=>'deferred'];
			return self::run((string) $job['id']);
		} finally {
			$pending = 0;
			foreach (JobStore::list() as $stored) if ((int) ($stored['active'] ?? 0) === 1 && (($stored['phase'] ?? '') === 'initial' || ($stored['last_result'] ?? '') === 'partial')) $pending++;
			\ficms\Runtime::request('emailsync',$pending * 8);
		}
	}

	public static function run(string $id): array {
		$job = JobStore::get($id);
		if (!$job || (int) ($job['active'] ?? 0) !== 1 || in_array(($job['phase'] ?? ''),['draft','completed'],true)) return ['success'=>false,'status'=>'not_runnable'];
		$job = Settings::apply($job);

		$currentMx = DnsMonitor::snapshot((string) ($job['domain'] ?? ''));
		if (empty($job['baseline_mx']['fingerprint']) && !empty($currentMx['fingerprint'])) $job['baseline_mx'] = $currentMx;
		$destination = \imap\ConnectionStore::get((string) ($job['destination_id'] ?? ''));
		$job = DnsMonitor::observe($job,$currentMx,(string) ($destination['host'] ?? ''));
		$job['last_run'] = time();
		$job['last_result'] = 'running';
		$job['run_count'] = (int) ($job['run_count'] ?? 0) + 1;
		$job = self::updateRun($id,$job,fn(array $stored): array => array_merge($stored,$job));

		if (!$job) return ['success'=>false,'status'=>'configuration_changed'];

		// A recorded successful final sweep can finish even after the old login was removed.
		if (self::complete($job,(array) ($job['last_summary'] ?? []),time())) return self::finish($id,$job,time(),['success'=>true,'status'=>'completed']);

		$source = \imap\ConnectionStore::credentials((string) ($job['source_id'] ?? ''));
		$destination = \imap\ConnectionStore::credentials((string) ($job['destination_id'] ?? ''));
		$result = MailboxSync::run($job,$source,$destination);
		$finished = (int) ($result['finished'] ?? time());
		$result['synchronized_total'] = max(0,(int) (ProgressStore::get($id)['run']['processed'] ?? 0));
		Statistics::add($id,$result);

		if (empty($result['success']) && ($result['error_side'] ?? '') === 'source' && ($result['error'] ?? '') === 'imap_command_failed_login' && !empty($job['initial_completed']) && !empty($job['cutover_detected']) && !empty($job['current_mx']['fingerprint']) && $job['current_mx']['fingerprint'] === ($job['cutover_fingerprint'] ?? '')) {
			$summary = ProgressStore::summary($job);
			if ((int) ($summary['pending'] ?? 1) === 0) {
				$paused = self::updateRun($id,$job,function(array $stored) use ($finished): array {
					$stored['active'] = 0;
					$stored['next_run'] = 0;
					$stored['last_result'] = 'source_unavailable';
					$stored['last_error'] = '';
					$stored['review_reason'] = 'source_login_unavailable_after_cutover';
					$stored['review_at'] = $finished;
					return $stored;
				});
				return ['success'=>true,'status'=>$paused ? 'source_unavailable' : 'configuration_changed','job'=>$paused];
			}
		}
		if (empty($result['success'])) {
			$firstFailure = (int) ($job['failure_count'] ?? 0) === 0;
			$job = self::updateRun($id,$job,function(array $stored) use ($result,$finished): array {
				$stored['last_result'] = 'failed';
				$stored['last_error'] = (string) ($result['error'] ?? 'unknown_error');
				$stored['failure_count'] = (int) ($stored['failure_count'] ?? 0) + 1;
				$stored['next_run'] = $finished + max(300,(int) ($stored['interval'] ?? 3600));
				$stored['last_summary'] = self::summary($result);
				$stored['stats_total'] = self::totals((array) ($stored['stats_total'] ?? []),$result);
				if ((int) ($result['stats']['messages_discovered'] ?? 0) > 0) $stored['last_source_change'] = $finished;
				return $stored;
			});
			if (!$job) return $result + ['status'=>'configuration_changed'];
			if ($firstFailure) JobStore::event($id,'failed',self::summary($result));
			if (!$job) return $result + ['status'=>'configuration_changed'];
			return $result + ['job'=>$job];
		}
		if (!empty($result['partial'])) {
			$job = self::updateRun($id,$job,function(array $stored) use ($result,$finished): array {
				$stored['last_result'] = 'partial';
				$stored['last_error'] = '';
				$stored['failure_count'] = 0;
				$stored['last_success'] = $finished;
				$stored['next_run'] = $finished;
				$stored['last_summary'] = self::summary($result);
				$stored['stats_total'] = self::totals((array) ($stored['stats_total'] ?? []),$result);
				if ((int) ($result['stats']['messages_discovered'] ?? 0) > 0) $stored['last_source_change'] = $finished;
				return $stored;
			});
			if (!$job) return $result + ['status'=>'configuration_changed'];
			return $result + ['job'=>$job];
		}

		$wasInitial = ($job['phase'] ?? 'initial') === 'initial';
		$job = self::updateRun($id,$job,function(array $stored) use ($result,$finished,$wasInitial): array {
			$discovered = $result['stats']['messages_discovered'] ?? null;
			$stored['last_result'] = 'success';
			$stored['last_error'] = '';
			$stored['failure_count'] = 0;
			$stored['last_success'] = $finished;
			$stored['next_run'] = $finished + max(3600,(int) ($stored['interval'] ?? 3600));
			$stored['last_summary'] = self::summary($result);
			$stored['stats_total'] = self::totals((array) ($stored['stats_total'] ?? []),$result);
			if ($wasInitial) {
				$stored['phase'] = 'monitoring';
				$stored['initial_completed'] = $finished;
				$stored['last_source_change'] = $finished;
			} elseif ($discovered !== null && $discovered > 0) $stored['last_source_change'] = $finished;
			return $stored;
		});

		if (!$job) return $result + ['status'=>'configuration_changed'];
		if ($wasInitial) JobStore::event($id,'initial_complete',self::summary($result));
		if (!self::complete($job,$result,$finished)) return $result + ['job'=>$job];

		return self::finish($id,$job,$finished,$result);
	}

	private static function finish(string $id, array $job, int $finished, array $result): array {
		$job = self::updateRun($id,$job,function(array $stored) use ($finished): array {
			$stored['phase'] = 'completed';
			$stored['active'] = 0;
			$stored['completed'] = $finished;
			$stored['next_run'] = 0;
			$stored['last_result'] = 'success';
			$stored['last_error'] = '';
			return $stored;
		});
		if (!$job) return $result + ['status'=>'configuration_changed'];
		// Keep the user-visible saved connections; deleting a job owns credential cleanup.
		JobStore::event($id,'completed',self::summary($result));
		return $result + ['job'=>$job];
	}

	private static function updateRun(string $id, array $expected, callable $update): array {
		$matched = false;
		$result = JobStore::update($id,function(array $stored) use ($expected,$update,&$matched): array {
			foreach (['source_id','destination_id','configuration_revision'] as $field) if (($stored[$field] ?? '') !== ($expected[$field] ?? '')) return $stored;
			if ((int) ($stored['active'] ?? 0) !== 1 || in_array(($stored['phase'] ?? ''),['draft','completed'],true)) return $stored;
			$matched = true;
			return $update($stored);
		});
		return $matched ? $result : [];
	}

	private static function complete(array $job, array $result, int $finished): bool {
		if (($job['phase'] ?? '') !== 'monitoring' || empty($job['initial_completed']) || empty($job['cutover_detected']) || empty($job['current_mx']['fingerprint']) || $job['current_mx']['fingerprint'] !== ($job['cutover_fingerprint'] ?? '')) return false;
		if (empty($result['success']) || !empty($result['partial'])) return false;
		if (!array_key_exists('messages_discovered',(array) ($result['stats'] ?? [])) || (int) $result['stats']['messages_discovered'] !== 0) return false;
		$ttl = max((int) ($job['fallback_ttl'] ?? 86400),(int) ($job['baseline_mx']['ttl'] ?? 0));
		$cutover = (int) $job['cutover_detected'];
		if ((int) ($result['finished'] ?? 0) < $cutover + $ttl) return false;
		$minimumEnd = $cutover + (int) ($job['minimum_monitoring'] ?? 604800);
		$quietAnchor = max($cutover + $ttl,(int) ($job['last_source_change'] ?? 0));
		$deadline = max($minimumEnd,$quietAnchor + (int) ($job['quiet_period'] ?? 172800));
		return $finished >= $deadline && (int) ($result['finished'] ?? 0) >= $deadline;
	}

	private static function summary(array $result): array {
		return [
			'success'=>!empty($result['success']) ? 1 : 0,
			'exit_code'=>(int) ($result['exit_code'] ?? -1),
			'error'=>(string) ($result['error'] ?? ''),
			'started'=>(int) ($result['started'] ?? 0),
			'finished'=>(int) ($result['finished'] ?? 0),
			'duration'=>(int) ($result['duration'] ?? 0),
			'partial'=>!empty($result['partial']) ? 1 : 0,
			'log'=>(string) ($result['log'] ?? ''),
			'stats'=>(array) ($result['stats'] ?? [])
		];
	}

	private static function accumulate(array $total, array $stats): array {
		foreach (['messages_discovered','messages_synchronized','messages_transferred','messages_skipped','bytes_transferred','errors'] as $key) $total[$key] = max(0,(int) ($total[$key] ?? 0)) + max(0,(int) ($stats[$key] ?? 0));
		return $total;
	}

	private static function totals(array $total, array $result): array {
		$total = self::accumulate($total,(array) ($result['stats'] ?? []));
		$total['messages_synchronized'] = max((int) ($total['messages_synchronized'] ?? 0),(int) ($result['synchronized_total'] ?? 0));
		return $total;
	}
}
