<?php

namespace emailsync;

final class JobStore {
	private const JOBS_FILE = 'jobs.json';
	private const EVENTS_FILE = 'events.json';

	public static function list(): array {
		$jobs = State::read(self::JOBS_FILE);
		foreach ($jobs as $id => $job) if (!is_array($job)) unset($jobs[$id]);
		uasort($jobs,function(array $a, array $b): int {
			return [(int) ($a['next_run'] ?? 0),(int) ($a['created'] ?? 0)] <=> [(int) ($b['next_run'] ?? 0),(int) ($b['created'] ?? 0)];
		});
		return $jobs;
	}

	public static function get(string $id): array {
		$jobs = State::read(self::JOBS_FILE);
		return isset($jobs[$id]) && is_array($jobs[$id]) ? $jobs[$id] : [];
	}

	public static function save(array $data, ?array $expectedSnapshot = null, ?callable $prepare = null, bool $initializeBaseline = true): array {
		$id = self::id((string) ($data['id'] ?? ''));
		if ($id === '') $id = 'sync-'.bin2hex(random_bytes(8));
		$current = self::get($id);
		$now = (int) ($_SERVER['now'] ?? time());
		$job = array_merge([
			'id'=>$id,
			'name'=>'',
			'domain'=>'',
			'source_id'=>'',
			'destination_id'=>'',
			'baseline_mx'=>[],
			'current_mx'=>[],
			'phase'=>'initial',
			'active'=>1,
			'interval'=>1200,
			'quiet_period'=>172800,
			'minimum_monitoring'=>604800,
			'fallback_ttl'=>86400,
			'created'=>$now,
			'updated'=>$now,
			'next_run'=>0,
			'last_run'=>0,
			'initial_completed'=>0,
			'cutover_detected'=>0,
			'last_source_change'=>0,
			'last_success'=>0,
			'last_result'=>'pending',
			'last_error'=>'',
			'run_count'=>0,
			'failure_count'=>0,
			'report_user_id'=>0,
			'report_email'=>'',
			'completed'=>0
		],$current,self::normalize($data));
		$job['id'] = $id;
		$job['updated'] = $now;
		if ($initializeBaseline && $job['phase'] !== 'draft' && (!$job['baseline_mx'] || (!empty($current) && ($current['domain'] ?? '') !== $job['domain'])) && $job['domain'] !== '') {
			$job['baseline_mx'] = DnsMonitor::snapshot($job['domain']);
			$job['current_mx'] = $job['baseline_mx'];
			$job['cutover_detected'] = 0;
		}
		$matched = false;
		$result = State::update(self::JOBS_FILE,function(array $jobs) use ($id,&$job,$expectedSnapshot,$prepare,&$matched): array {
			if ($expectedSnapshot !== null && ($jobs[$id] ?? []) !== $expectedSnapshot) return $jobs;
			// Prepare connection writes only after the snapshot matches, while the job lock is held.
			if ($prepare !== null) {
				$prepared = $prepare($job);
				if (!$prepared) return $jobs;
				$job = $prepared;
			}
			$matched = true;
			$jobs[$id] = $job;
			return $jobs;
		});
		return $result === false || !$matched ? [] : $job;
	}

	public static function update(string $id, callable $update): array {
		$updated = [];
		$result = State::update(self::JOBS_FILE,function(array $jobs) use ($id,$update,&$updated): array {
			if (!isset($jobs[$id]) || !is_array($jobs[$id])) return $jobs;
			$updated = $update($jobs[$id]);
			if (!is_array($updated)) $updated = $jobs[$id];
			$updated['id'] = $id;
			$updated['updated'] = (int) ($_SERVER['now'] ?? time());
			$jobs[$id] = $updated;
			return $jobs;
		});
		return $result === false ? [] : $updated;
	}

	public static function due(int $now = 0): array {
		$now = $now > 0 ? $now : (int) ($_SERVER['now'] ?? time());
		$due = [];
		foreach (self::list() as $job) {
			if ((int) ($job['active'] ?? 0) !== 1 || in_array(($job['phase'] ?? ''),['draft','completed'],true)) continue;
			if ((int) ($job['next_run'] ?? 0) > $now) continue;
			$due[] = $job;
		}
		// Initial copies lead; a monitoring job not checked for six hours gets one fair turn.
		usort($due,static function(array $a, array $b) use ($now): int {
			$rank = static fn(array $j): int => ($j['phase'] ?? '') === 'initial' || ($j['last_result'] ?? '') === 'partial' ? 0 : (((int) ($j['last_run'] ?? 0) <= $now - 21600) ? -1 : 1);
			return [$rank($a),(int) ($a['last_run'] ?? 0),(int) ($a['created'] ?? 0)] <=> [$rank($b),(int) ($b['last_run'] ?? 0),(int) ($b['created'] ?? 0)];
		});
		if ($due) return $due[0];
		return [];
	}

	public static function delete(string $id): bool {
		$job = self::get($id);
		if (!$job) return false;
		$result = State::update(self::JOBS_FILE,function(array $jobs) use ($id): array {
			unset($jobs[$id]);
			return $jobs;
		});
		if ($result === false) return false;
		State::update(self::EVENTS_FILE,function(array $events) use ($id): array {
			foreach ($events as $eventId => $event) if (is_array($event) && ($event['job_id'] ?? '') === $id) unset($events[$eventId]);
			return $events;
		});
		\ficms\Files::removeDirectory(State::path('runs/'.$id),true,true);
		ProgressStore::delete($id);
		Statistics::delete($id);
		foreach (['source_id','destination_id'] as $field) if (!empty($job[$field]) && !self::connectionInUse((string) $job[$field])) \imap\ConnectionStore::delete((string) $job[$field]);
		return true;
	}

	public static function event(string $jobId, string $type, array $summary = []): array {
		$job = self::get($jobId);
		if (!$job || !in_array($type,['initial_complete','completed','failed'],true)) return [];
		$summary['progress'] = ProgressStore::summary($job);
		$event = [
			'id'=>'event-'.bin2hex(random_bytes(8)),
			'job_id'=>$jobId,
			'type'=>$type,
			'name'=>(string) ($job['name'] ?? $jobId),
			'domain'=>(string) ($job['domain'] ?? ''),
			'report_user_id'=>(int) ($job['report_user_id'] ?? 0),
			'report_email'=>(string) ($job['report_email'] ?? ''),
			'summary'=>$summary,
			'created'=>(int) ($_SERVER['now'] ?? time()),
			'notified'=>0
		];
		State::update(self::EVENTS_FILE,function(array $events) use ($event): array {
			$events[$event['id']] = $event;
			return $events;
		});
		return $event;
	}

	public static function pendingEvents(): array {
		$events = State::read(self::EVENTS_FILE);
		foreach ($events as $id => $event) if (!is_array($event) || (int) ($event['notified'] ?? 0) !== 0) unset($events[$id]);
		return $events;
	}

	public static function markEventsNotified(array $ids): bool {
		$ids = array_fill_keys(array_values(array_filter(array_map('strval',$ids))),true);
		$result = State::update(self::EVENTS_FILE,function(array $events) use ($ids): array {
			foreach ($ids as $id => $value) if (isset($events[$id]) && is_array($events[$id])) $events[$id]['notified'] = (int) ($_SERVER['now'] ?? time());
			return $events;
		});
		return $result !== false;
	}

	public static function statistics(): array {
		$jobs = self::list();
		$statistics = ['jobs'=>0,'active'=>0,'draft'=>0,'initial'=>0,'monitoring'=>0,'completed'=>0,'failed'=>0,'runs'=>0,'messages_total'=>0,'messages_processed'=>0,'messages_pending'=>0,'messages_historic'=>0,'messages_transferred'=>0,'messages_skipped'=>0,'bytes_transferred'=>0,'last_run'=>0,'daily'=>Statistics::daily($jobs)];
		foreach ($jobs as $job) {
			$progress = ProgressStore::summary($job);
			$statistics['jobs']++;
			$phase = in_array(($job['phase'] ?? ''),['draft','initial','monitoring','completed'],true) ? $job['phase'] : 'initial';
			$statistics[$phase]++;
			if ((int) ($job['active'] ?? 0) === 1 && !in_array($phase,['draft','completed'],true)) $statistics['active']++;
			if (($job['last_result'] ?? '') === 'failed') $statistics['failed']++;
			$statistics['runs'] += max(0,(int) ($job['run_count'] ?? 0));
			foreach (['total','processed','pending','historic'] as $key) $statistics['messages_'.$key] += $progress[$key];
			$statistics['messages_transferred'] += max(0,(int) ($job['stats_total']['messages_transferred'] ?? 0));
			$statistics['messages_skipped'] += max(0,(int) ($job['stats_total']['messages_skipped'] ?? 0));
			$statistics['bytes_transferred'] += max(0,(int) ($job['stats_total']['bytes_transferred'] ?? 0));
			$statistics['last_run'] = max($statistics['last_run'],(int) ($job['last_run'] ?? 0));
		}
		return $statistics;
	}

	private static function normalize(array $data): array {
		$phase = in_array(($data['phase'] ?? ''),['draft','initial','monitoring','completed'],true) ? $data['phase'] : 'initial';
		return [
			'name'=>substr(trim((string) ($data['name'] ?? '')),0,160),
			'domain'=>strtolower(rtrim(trim((string) ($data['domain'] ?? '')),'.')),
			'source_id'=>preg_replace('/[^a-z0-9_.-]/i','',(string) ($data['source_id'] ?? '')),
			'destination_id'=>preg_replace('/[^a-z0-9_.-]/i','',(string) ($data['destination_id'] ?? '')),
			'phase'=>$phase,
			'configuration_revision'=>(string) ($data['configuration_revision'] ?? ''),
			'active'=>(int) ($data['active'] ?? 1) === 1 ? 1 : 0,
			'interval'=>max(60,(int) ($data['interval'] ?? 1200)),
			'quiet_period'=>max(3600,(int) ($data['quiet_period'] ?? 172800)),
			'minimum_monitoring'=>max(86400,(int) ($data['minimum_monitoring'] ?? 604800)),
			'fallback_ttl'=>max(300,(int) ($data['fallback_ttl'] ?? 86400)),
			'report_user_id'=>max(0,(int) ($data['report_user_id'] ?? 0)),
			'report_email'=>substr(trim((string) ($data['report_email'] ?? '')),0,320)
		];
	}

	private static function connectionInUse(string $connectionId): bool {
		foreach (self::list() as $job) if (($job['source_id'] ?? '') === $connectionId || ($job['destination_id'] ?? '') === $connectionId) return true;
		return false;
	}

	private static function id(string $id): string {
		return preg_replace('/[^a-z0-9_.-]/i','',trim($id));
	}
}
