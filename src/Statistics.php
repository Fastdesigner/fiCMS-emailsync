<?php

namespace emailsync;

final class Statistics {
	private const DEFAULTS = [
		'runs'=>0,
		'messages_discovered'=>0,
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
			'messages_transferred'=>max(0,(int) ($stats['messages_transferred'] ?? 0)),
			'messages_skipped'=>max(0,(int) ($stats['messages_skipped'] ?? 0)),
			'bytes_transferred'=>max(0,(int) ($stats['bytes_transferred'] ?? 0)),
			'errors'=>max(0,(int) ($stats['errors'] ?? 0))
		];
		$day = strtotime('today',max(1,(int) ($result['finished'] ?? time())));
		$written = true;
		foreach ($values as $key => $amount) if (!statistics__daily_json_increment(self::file($jobId),$key,self::DEFAULTS,$day,$amount)) $written = false;
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

	private static function file(string $jobId): string {
		return State::path('statistics/'.self::id($jobId).'.json');
	}

	private static function id(string $id): string {
		$id = preg_replace('/[^a-z0-9_.-]/i','',trim($id));
		if ($id === '') throw new \InvalidArgumentException('emailsync_job_id_invalid');
		return $id;
	}
}
