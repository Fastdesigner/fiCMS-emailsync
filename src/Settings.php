<?php

namespace emailsync;

final class Settings {
	private const FILE = 'settings.json';

	public static function get(): array {
		return self::normalize(State::read(self::FILE));
	}

	public static function save(array $data): array {
		$settings = self::normalize($data);
		return State::write(self::FILE,$settings,true) ? $settings : [];
	}

	public static function apply(array $job): array {
		return array_merge($job,self::get());
	}

	private static function normalize(array $data): array {
		return [
			'interval'=>max(60,(int) ($data['interval'] ?? 1200)),
			'quiet_period'=>max(3600,(int) ($data['quiet_period'] ?? 172800)),
			'minimum_monitoring'=>max(86400,(int) ($data['minimum_monitoring'] ?? 604800)),
			'fallback_ttl'=>max(300,(int) ($data['fallback_ttl'] ?? 86400))
		];
	}
}
