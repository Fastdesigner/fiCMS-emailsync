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

	public static function save(array $data): array {
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
			'expected_mx'=>[],
			'baseline_mx'=>[],
			'current_mx'=>[],
			'phase'=>'initial',
			'active'=>1,
			'interval'=>3600,
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
		if ((!$job['baseline_mx'] || (!empty($current) && ($current['domain'] ?? '') !== $job['domain'])) && $job['domain'] !== '') {
			$job['baseline_mx'] = DnsMonitor::snapshot($job['domain']);
			$job['current_mx'] = $job['baseline_mx'];
			$job['cutover_detected'] = 0;
		}
		State::update(self::JOBS_FILE,function(array $jobs) use ($id,$job): array {
			$jobs[$id] = $job;
			return $jobs;
		});
		return $job;
	}

	public static function update(string $id, callable $update): array {
		$updated = [];
		State::update(self::JOBS_FILE,function(array $jobs) use ($id,$update,&$updated): array {
			if (!isset($jobs[$id]) || !is_array($jobs[$id])) return $jobs;
			$updated = $update($jobs[$id]);
			if (!is_array($updated)) $updated = $jobs[$id];
			$updated['id'] = $id;
			$updated['updated'] = (int) ($_SERVER['now'] ?? time());
			$jobs[$id] = $updated;
			return $jobs;
		});
		return $updated;
	}

	public static function due(int $now = 0): array {
		$now = $now > 0 ? $now : (int) ($_SERVER['now'] ?? time());
		foreach (self::list() as $job) {
			if ((int) ($job['active'] ?? 0) !== 1 || ($job['phase'] ?? '') === 'completed') continue;
			if ((int) ($job['next_run'] ?? 0) > $now) continue;
			return $job;
		}
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
		$statistics = ['jobs'=>0,'active'=>0,'initial'=>0,'monitoring'=>0,'completed'=>0,'failed'=>0,'runs'=>0,'messages_transferred'=>0,'bytes_transferred'=>0,'last_run'=>0];
		foreach (self::list() as $job) {
			$statistics['jobs']++;
			$phase = in_array(($job['phase'] ?? ''),['initial','monitoring','completed'],true) ? $job['phase'] : 'initial';
			$statistics[$phase]++;
			if ((int) ($job['active'] ?? 0) === 1 && $phase !== 'completed') $statistics['active']++;
			if (($job['last_result'] ?? '') === 'failed') $statistics['failed']++;
			$statistics['runs'] += max(0,(int) ($job['run_count'] ?? 0));
			$statistics['messages_transferred'] += max(0,(int) ($job['stats_total']['messages_transferred'] ?? 0));
			$statistics['bytes_transferred'] += max(0,(int) ($job['stats_total']['bytes_transferred'] ?? 0));
			$statistics['last_run'] = max($statistics['last_run'],(int) ($job['last_run'] ?? 0));
		}
		return $statistics;
	}

	private static function normalize(array $data): array {
		$phase = in_array(($data['phase'] ?? ''),['initial','monitoring','completed'],true) ? $data['phase'] : 'initial';
		return [
			'name'=>substr(trim((string) ($data['name'] ?? '')),0,160),
			'domain'=>strtolower(rtrim(trim((string) ($data['domain'] ?? '')),'.')),
			'source_id'=>preg_replace('/[^a-z0-9_.-]/i','',(string) ($data['source_id'] ?? '')),
			'destination_id'=>preg_replace('/[^a-z0-9_.-]/i','',(string) ($data['destination_id'] ?? '')),
			'expected_mx'=>DnsMonitor::parseExpected($data['expected_mx'] ?? []),
			'phase'=>$phase,
			'active'=>(int) ($data['active'] ?? 1) === 1 ? 1 : 0,
			'interval'=>max(60,(int) ($data['interval'] ?? 3600)),
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
