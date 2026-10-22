<?php

namespace emailsync;

final class DnsMonitor {
	public static function sourceDomain(string $username): string {
		$username = trim($username);
		if (strpos($username,'@') === false) return '';
		return self::domain(substr($username,strrpos($username,'@') + 1));
	}

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

	public static function cutover(array $baseline, array $current): bool {
		if (empty($current['fingerprint']) || ($current['fingerprint'] ?? '') === ($baseline['fingerprint'] ?? '')) return false;
		return true;
	}

	public static function observe(array $job, array $current, string $destinationHost): array {
		$previous = (array) ($job['current_mx'] ?? []);
		$job['current_mx'] = $current;
		// Empty DNS answers are uncertainty, not a cutover or a reason to discard evidence.
		if (empty($current['fingerprint'])) return $job;
		$expected = !empty($job['cutover_manual'])
			? ($current['fingerprint'] === ($job['cutover_fingerprint'] ?? ''))
			: self::matchesDestination($current,$destinationHost) && self::cutover((array) ($job['baseline_mx'] ?? []),$current);
		if (!$expected) {
			$job['cutover_detected'] = 0;
			$job['cutover_fingerprint'] = '';
			$job['cutover_manual'] = false;
			return $job;
		}
		if (empty($job['cutover_detected']) || (!empty($job['cutover_fingerprint']) && $job['cutover_fingerprint'] !== $current['fingerprint']) || (empty($job['cutover_fingerprint']) && ($previous['fingerprint'] ?? '') !== $current['fingerprint'])) $job['cutover_detected'] = time();
		$job['cutover_fingerprint'] = $current['fingerprint'];
		return $job;
	}

	public static function matchesDestination(array $current, string $host): bool {
		$targets = array_values(array_unique(array_map([self::class,'host'],(array) ($current['targets'] ?? []))));
		return $targets !== [] && $targets === [self::host($host)] && $targets[0] !== '';
	}

	public static function confirm(array $job, bool $reset): array {
		if (($job['phase'] ?? '') !== 'monitoring') return $job;
		$current = self::snapshot((string) ($job['domain'] ?? ''));
		if (!$reset && empty($current['fingerprint'])) return $job;
		$job['current_mx'] = $current;
		$job['cutover_detected'] = $reset ? 0 : time();
		$job['cutover_fingerprint'] = $reset ? '' : $current['fingerprint'];
		$job['cutover_manual'] = !$reset;
		if ($reset) $job['baseline_mx'] = $current;
		return $job;
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
