<?php

namespace emailsync;

final class McpView {
	public static function read(string $id, bool $edit = false): array {
		$id = trim($id);
		if ($id === '' || $id === 'summary') return [
			'type'=>'emailsync',
			'runtime'=>MailboxSync::probe(),
			'settings'=>Settings::get(),
			'statistics'=>JobStore::statistics(),
			'jobs'=>array_values(array_map(fn(array $job): array => self::job($job,false),JobStore::list()))
		];
		if ($id === 'settings') {
			$result = ['type'=>'emailsync','id'=>'settings','settings'=>Settings::get()];
			if ($edit) foreach (['interval_minutes'=>['interval',60],'quiet_hours'=>['quiet_period',3600],'monitoring_days'=>['minimum_monitoring',86400],'fallback_ttl_hours'=>['fallback_ttl',3600]] as $field => $mapping) $result['edit'][$field] = $result['settings'][$mapping[0]] / $mapping[1];
			return $result;
		}
		if ($id === 'jobs') return ['type'=>'emailsync','jobs'=>array_values(array_map(fn(array $job): array => self::job($job,false),JobStore::list()))];
		if (str_starts_with($id,'folders:')) return self::folders(substr($id,8));
		if (str_starts_with($id,'job:')) $id = substr($id,4);
		$id = preg_replace('/[^a-z0-9_.-]/i','',$id);
		$job = $id !== '' ? JobStore::get($id) : [];
		if (!$job) return ['error'=>'No email sync job found for '.$id.'.'];
		$result = ['type'=>'emailsync','id'=>$id,'job'=>self::job($job,true)];
		if ($edit) {
			$result['edit'] = ['name'=>$job['name'],'report_email'=>$job['report_email']];
			foreach (['source','destination'] as $side) {
				$connection = \imap\ConnectionStore::get((string) ($job[$side.'_id'] ?? ''));
				foreach (['host'=>'','port'=>993,'security'=>'ssl','username'=>''] as $field => $default) $result['edit'][$side.'_'.$field] = $connection[$field] ?? $default;
			}
			$result['write_only'] = ['source_password','destination_password'];
			$result['controls'] = ['active'=>(int) $job['active'],'run_next_cron'=>false,'cutover'=>['confirm','reset'],'test_connection'=>['source','destination']];
		}
		return $result;
	}

	private static function job(array $job, bool $details): array {
		$view = [
			'id'=>(string) ($job['id'] ?? ''),
			'worker_version'=>Runner::POLICY_VERSION,
			'name'=>(string) ($job['name'] ?? ''),
			'domain'=>(string) ($job['domain'] ?? ''),
			'phase'=>(string) ($job['phase'] ?? ''),
			'active'=>(int) ($job['active'] ?? 0),
			'next_run'=>(int) ($job['next_run'] ?? 0),
			'last_run'=>(int) ($job['last_run'] ?? 0),
			'last_result'=>(string) ($job['last_result'] ?? ''),
			'last_error'=>(string) ($job['last_error'] ?? ''),
			'run_count'=>(int) ($job['run_count'] ?? 0),
			'failure_count'=>(int) ($job['failure_count'] ?? 0),
			'progress'=>ProgressStore::ratio($job),
			'progress_summary'=>ProgressStore::summary($job),
			'stats_total'=>(array) ($job['stats_total'] ?? [])
		];
		if (!$details) return $view;
		$view = array_merge($job,$view,[
			'source'=>self::connection((string) ($job['source_id'] ?? '')),
			'destination'=>self::connection((string) ($job['destination_id'] ?? '')),
			'progress_state'=>ProgressStore::get((string) $job['id']),
			'statistics'=>Statistics::get((string) $job['id'])
		]);
		$view['last_log'] = '';
		if (!empty($job['last_summary']['log'])) {
			$log = \ficms\Files::read((string) $job['last_summary']['log']);
			if (is_string($log)) $view['last_log'] = substr($log,0,20000);
		}
		return $view;
	}

	private static function connection(string $id): array {
		$connection = \imap\ConnectionStore::get($id);
		if (!$connection) return [];
		$connection['secret_present'] = !empty($connection['has_secret']);
		unset($connection['has_secret']);
		return $connection;
	}

	private static function folders(string $id): array {
		$id = preg_replace('/[^a-z0-9_.-]/i','',$id);
		$job = $id !== '' ? JobStore::get($id) : [];
		if (!$job) return ['error'=>'No email sync job found for '.$id.'.'];
		$clients = [];
		try {
			foreach (['source','destination'] as $side) {
				$clients[$side] = new \imap\Client(\imap\ConnectionStore::credentials((string) ($job[$side.'_id'] ?? '')));
				$clients[$side]->connect();
			}
			return ['type'=>'emailsync','job_id'=>$id,'source'=>$clients['source']->folders(),'destination'=>$clients['destination']->folders()];
		} catch (\Throwable $exception) {
			return ['error'=>substr($exception->getMessage() ?: 'imap_folder_probe_failed',0,160)];
		} finally {
			foreach (array_reverse($clients) as $client) $client->close();
		}
	}
}
