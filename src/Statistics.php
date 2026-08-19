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

	public static function add(string $jobId, array $result, int $retentionHours = 96): bool {
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
		$hour = (int) (floor(max(1,(int) ($result['finished'] ?? time())) / 3600) * 3600);
		$written = true;
		foreach ($values as $key => $amount) if (!statistics__daily_json_increment(self::file($jobId),$key,self::DEFAULTS,$hour,$amount)) $written = false;
		$cutoff = $hour - max(2,$retentionHours) * 3600;
		if (State::update('statistics/'.self::id($jobId).'.json',function(array $statistics) use ($cutoff): array {
			foreach ((array) ($statistics['data'] ?? []) as $timestamp => $row) if ((int) $timestamp < $cutoff) unset($statistics['data'][$timestamp]);
			return $statistics;
		}) === false) $written = false;
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

	public static function series(string $jobId, int $total, int $runs, int $windowHours, int $now = 0): array {
		if ($runs < 1) return [];
		$windowHours = max(2,$windowHours);
		$step = max(3600,(int) ceil($windowHours / 48) * 3600);
		$now = $now > 0 ? $now : time();
		$end = (int) (floor($now / $step) * $step);
		$start = $end - (int) ceil(($windowHours * 3600) / $step) * $step;
		$grouped = [];
		$windowTotal = 0;
		foreach (self::get($jobId)['data'] as $timestamp => $row) {
			$timestamp = (int) $timestamp;
			if ($timestamp < $start || $timestamp > $now) continue;
			$amount = max(0,(int) ($row['messages_synchronized'] ?? $row['messages_transferred'] ?? 0));
			$bucket = max($start,(int) (floor($timestamp / $step) * $step));
			$grouped[$bucket] = (int) ($grouped[$bucket] ?? 0) + $amount;
			$windowTotal += $amount;
		}
		$points = [];
		$cumulative = max(0,$total - $windowTotal);
		for ($timestamp = $start; $timestamp <= $end; $timestamp += $step) {
			$cumulative += (int) ($grouped[$timestamp] ?? 0);
			$points[] = ['time'=>$timestamp,'value'=>$cumulative];
		}
		return $points;
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
