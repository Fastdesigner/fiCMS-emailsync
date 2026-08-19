<?php

namespace emailsync;

final class Statistics {
	private const DEFAULTS = [
		'runs'=>0,
		'messages_discovered'=>0,
		'messages_synchronized'=>0,
		'messages_transferred'=>0,
		'messages_skipped'=>0,
		'bytes_transferred'=>0,
		'errors'=>0
	];

	public static function add(string $jobId, array $result): bool {
		$stats = (array) ($result['stats'] ?? []);
		$values = [
			'runs'=>1,
			'messages_discovered'=>max(0,(int) ($stats['messages_discovered'] ?? 0)),
			'messages_synchronized'=>max(0,(int) ($stats['messages_synchronized'] ?? $stats['messages_discovered'] ?? 0)),
			'messages_transferred'=>max(0,(int) ($stats['messages_transferred'] ?? 0)),
			'messages_skipped'=>max(0,(int) ($stats['messages_skipped'] ?? 0)),
			'bytes_transferred'=>max(0,(int) ($stats['bytes_transferred'] ?? 0)),
			'errors'=>max(0,(int) ($stats['errors'] ?? 0))
		];
		$timestamp = max(1,(int) ($result['finished'] ?? time()));
		$written = true;
		foreach ($values as $key => $amount) if (!statistics__daily_json_increment(self::file($jobId),$key,self::DEFAULTS,$timestamp,$amount)) $written = false;
		return $written;
	}

	public static function get(string $jobId): array {
		$statistics = State::read('statistics/'.self::id($jobId).'.json');
		$statistics['totals'] = array_merge(self::DEFAULTS,(array) ($statistics['totals'] ?? []));
		$statistics['data'] = (array) ($statistics['data'] ?? []);
		return $statistics;
	}

	public static function delete(string $jobId): bool {
		return State::delete('statistics/'.self::id($jobId).'.json');
	}

	public static function series(string $jobId, int $total, int $runs, int $created, int $now = 0, int $maxPoints = 12): array {
		if ($runs < 1) return [];
		$now = $now > 0 ? $now : time();
		$start = max(1,min($now,$created > 0 ? $created : $now));
		$end = max($start + 1,$now);
		$increments = [];
		$recorded = 0;
		$data = self::get($jobId)['data'];
		ksort($data);
		foreach ($data as $timestamp => $row) {
			$timestamp = min($end,max($start + 1,(int) $timestamp));
			$amount = max(0,(int) ($row['messages_synchronized'] ?? $row['messages_transferred'] ?? 0));
			$increments[$timestamp] = (int) ($increments[$timestamp] ?? 0) + $amount;
			$recorded += $amount;
		}
		$recovered = max(0,$total - $recorded);
		if ($recovered > 0) {
			$timestamp = count($increments) ? array_key_first($increments) : $end;
			$increments[$timestamp] = (int) ($increments[$timestamp] ?? 0) + $recovered;
		}
		ksort($increments);
		$points = [['time'=>$start,'value'=>0]];
		$cumulative = 0;
		foreach ($increments as $timestamp => $amount) {
			$cumulative += $amount;
			$points[] = ['time'=>$timestamp,'value'=>$cumulative];
		}
		if ((int) $points[array_key_last($points)]['time'] === $end) $points[array_key_last($points)]['value'] = $total;
		else $points[] = ['time'=>$end,'value'=>$total];
		$maxPoints = max(2,$maxPoints);
		if (count($points) <= $maxPoints) return $points;
		$sampled = [];
		for ($index = 0; $index < $maxPoints; $index++) $sampled[] = $points[(int) round($index * (count($points) - 1) / ($maxPoints - 1))];
		return $sampled;
	}

	private static function file(string $jobId): string {
		return State::path('statistics/'.self::id($jobId).'.json');
	}

	private static function id(string $id): string {
		$id = preg_replace('/[^a-z0-9_.-]/i','',trim($id));
		if ($id === '') throw new \InvalidArgumentException('emailsync_job_id_invalid');
		return $id;
	}
}
