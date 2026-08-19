<?php

namespace emailsync;

final class DnsMonitor {
	public static function snapshot(string $domain): array {
		$domain = self::domain($domain);
		if ($domain === '') return ['domain'=>'','records'=>[],'targets'=>[],'fingerprint'=>'','ttl'=>0,'checked'=>(int) ($_SERVER['now'] ?? time())];
		$records = [];
		foreach ((array) dns_get_record($domain,DNS_MX) as $record) {
			$target = self::host((string) ($record['target'] ?? ''));
			if ($target === '') continue;
			$records[] = ['priority'=>(int) ($record['pri'] ?? 0),'target'=>$target,'ttl'=>max(0,(int) ($record['ttl'] ?? 0))];
		}
		usort($records,function(array $a, array $b): int {
			return [$a['priority'],$a['target']] <=> [$b['priority'],$b['target']];
		});
		$targets = [];
		$ttl = 0;
		foreach ($records as $record) {
			$targets[] = $record['target'];
			$ttl = max($ttl,$record['ttl']);
		}
		$fingerprintRecords = [];
		foreach ($records as $record) $fingerprintRecords[] = ['priority'=>$record['priority'],'target'=>$record['target']];
		return [
			'domain'=>$domain,
			'records'=>$records,
			'targets'=>array_values(array_unique($targets)),
			'fingerprint'=>$records ? hash('sha256',json_encode($fingerprintRecords,JSON_UNESCAPED_SLASHES)) : '',
			'ttl'=>$ttl,
			'checked'=>(int) ($_SERVER['now'] ?? time())
		];
	}

	public static function cutover(array $baseline, array $current, array $expected = []): bool {
		if (empty($current['fingerprint']) || ($current['fingerprint'] ?? '') === ($baseline['fingerprint'] ?? '')) return false;
		$expected = self::targets($expected);
		if (!$expected) return true;
		$currentTargets = self::targets((array) ($current['targets'] ?? []));
		foreach ($expected as $target) if (!in_array($target,$currentTargets,true)) return false;
		return true;
	}

	public static function parseExpected($value): array {
		$lines = is_array($value) ? $value : preg_split('/[\r\n,;]+/',(string) $value);
		$targets = [];
		foreach ((array) $lines as $line) {
			$parts = preg_split('/\s+/',trim((string) $line));
			$target = self::host((string) end($parts));
			if ($target !== '') $targets[] = $target;
		}
		return self::targets($targets);
	}

	private static function targets(array $targets): array {
		$result = [];
		foreach ($targets as $target) {
			$target = self::host((string) $target);
			if ($target !== '') $result[$target] = $target;
		}
		ksort($result,SORT_NATURAL | SORT_FLAG_CASE);
		return array_values($result);
	}

	private static function domain(string $domain): string {
		$domain = strtolower(rtrim(trim($domain," .\t\n\r\0\x0B"),'.'));
		if (function_exists('idn_to_ascii')) $domain = idn_to_ascii($domain,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46) ?: '';
		return filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) ? $domain : '';
	}

	private static function host(string $host): string {
		$host = strtolower(rtrim(trim($host),'.'));
		return filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) ? $host : '';
	}
}
