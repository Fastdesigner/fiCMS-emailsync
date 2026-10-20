<?php

namespace emailsync;

final class JobConfiguration {
	public static function fields(): array {
		$fields = ['name','report_email'];
		foreach (['source','destination'] as $side) foreach (['host','port','security','username','password'] as $field) $fields[] = $side.'_'.$field;
		return $fields;
	}

	public static function testConnection(array $data, string $id, string $side): array {
		if (!in_array($side,['source','destination'],true)) return ['result'=>false,'error'=>'invalid_configuration'];
		$job = $id !== '' && $id !== 'new' ? JobStore::get($id) : [];
		$stored = !empty($job[$side.'_id']) ? \imap\ConnectionStore::get((string) $job[$side.'_id']) : [];
		$connection = [];
		foreach (['host'=>'','port'=>993,'security'=>'ssl','username'=>''] as $field => $default) $connection[$field] = $data[$side.'_'.$field] ?? $default;
		$connection['password'] = (string) ($data[$side.'_password'] ?? '');
		$identityChanged = false;
		foreach (['host','port','security','username'] as $field) if ((string) ($stored[$field] ?? '') !== (string) $connection[$field]) $identityChanged = true;
		if ($identityChanged && $connection['password'] !== '' && hash_equals((string) (\imap\ConnectionStore::credentials((string) ($stored['id'] ?? ''))['password'] ?? ''),$connection['password'])) return ['result'=>false,'error'=>'imap_credentials_missing'];
		if ($connection['password'] === '') {
			foreach (['host','port','security','username'] as $field) if ((string) ($stored[$field] ?? '') !== (string) $connection[$field]) return ['result'=>false,'error'=>'imap_credentials_missing'];
			$connection['password'] = (string) (\imap\ConnectionStore::credentials((string) ($stored['id'] ?? ''))['password'] ?? '');
		}
		if ($connection['password'] === '') return ['result'=>false,'error'=>'imap_credentials_missing'];
		return MailboxSync::test($connection);
	}

	public static function saveProtectedCredential(array $data, string $id): array {
		$fields = array_keys($data);
		$passwords = array_values(array_intersect($fields,['source_password','destination_password']));
		if (count($fields) !== 3 || count($passwords) !== 1
			|| !is_string($data[$passwords[0]]) || $data[$passwords[0]] === ''
			|| !is_string($data['expected_configuration_revision'] ?? null) || $data['expected_configuration_revision'] === ''
			|| !is_array($data['expected_identity'] ?? null) || count($data['expected_identity']) !== 2) return ['result'=>false,'error'=>'invalid_protected_credential_write'];
		foreach (['source','destination'] as $side) {
			$identity = $data['expected_identity'][$side] ?? null;
			if (!is_array($identity) || count($identity) !== 5 || !is_int($identity['port'] ?? null)) return ['result'=>false,'error'=>'invalid_protected_credential_write'];
			foreach (['id','host','security','username'] as $field) if (!is_string($identity[$field] ?? null)) return ['result'=>false,'error'=>'invalid_protected_credential_write'];
		}
		// The global fiCMS handler records trace arguments. Keep it outside this secret scope.
		set_error_handler(static function(int $severity): bool {
			if (!(error_reporting() & $severity)) return false;
			$ignoreArgs = ini_set('zend.exception_ignore_args','1');
			try {
				$error = new \RuntimeException('credential_write_failed');
			} finally {
				if ($ignoreArgs !== false) ini_set('zend.exception_ignore_args',$ignoreArgs);
			}
			throw $error;
		});
		try {
			$result = self::save([$passwords[0]=>$data[$passwords[0]]],$id,$data);
			if (empty($result['result'])) return ['result'=>false,'error'=>($result['error'] ?? '') === 'target_changed' ? 'target_changed' : 'credential_write_failed'];
			return ['result'=>true,'type'=>'emailsync','id'=>$id,'status'=>'submitted'];
		} catch (\Throwable $exception) {
			return ['result'=>false,'error'=>'credential_write_failed'];
		} finally {
			restore_error_handler();
		}
	}

	private static function matchesIdentity(array $current, array $expected): bool {
		if (($current['configuration_revision'] ?? '') !== $expected['expected_configuration_revision']) return false;
		foreach (['source','destination'] as $side) {
			$stored = \imap\ConnectionStore::get((string) ($current[$side.'_id'] ?? ''));
			foreach (['id','host','port','security','username'] as $field) if (($stored[$field] ?? null) !== $expected['expected_identity'][$side][$field]) return false;
		}
		return true;
	}

	public static function save(array $data, string $id = '', ?array $expected = null): array {
		$current = $id !== '' ? JobStore::get($id) : [];
		if ($id !== '' && !$current) return ['result'=>false,'error'=>'job_not_found'];
		if ($expected !== null && !self::matchesIdentity($current,$expected)) return ['result'=>false,'error'=>'target_changed'];
		if ($expected !== null && !empty($current['active'])) return ['result'=>false,'error'=>'credential_write_failed'];
		if ($expected === null && ($current['phase'] ?? '') === 'completed') return ['result'=>false,'error'=>'job_completed'];
		$name = trim((string) ($data['name'] ?? $current['name'] ?? ''));
		$email = trim((string) ($data['report_email'] ?? $current['report_email'] ?? ''));
		if ($name === '' || !filter_var($email,FILTER_VALIDATE_EMAIL)) return ['result'=>false,'error'=>'invalid_configuration'];
		$tables = $GLOBALS['tables'] ?? [];
		$recipient = isset($tables['user']) ? mysqlFetchAssoc("SELECT `id` FROM ".$tables['user']." WHERE `ac` = 1 AND `email` = '".mysqlEscape($email)."' LIMIT 1") : false;
		if (empty($recipient['id'])) return ['result'=>false,'error'=>'report_user_missing'];

		$connections = [];
		$previousIds = [];
		$changed = !$current;
		foreach (['source','destination'] as $side) {
			$stored = !empty($current[$side.'_id']) ? \imap\ConnectionStore::get((string) $current[$side.'_id']) : [];
			$previousIds[$side] = (string) ($stored['id'] ?? '');
			$connection = ['id'=>$stored['id'] ?? '', 'name'=>$name.' '.$side, 'purpose'=>'migration', 'auth'=>'password'];
			foreach (['host'=>'','port'=>993,'security'=>'ssl','username'=>''] as $field => $default) {
				$connection[$field] = $data[$side.'_'.$field] ?? $stored[$field] ?? $default;
				if ($field !== 'port') $connection[$field] = trim((string) $connection[$field]);
			}
			$connection['host'] = strtolower(rtrim($connection['host'],'.'));
			$connection['port'] = (int) $connection['port'];
			if (($connection['host'] !== '' && !filter_var($connection['host'],FILTER_VALIDATE_IP) && !filter_var($connection['host'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))
				|| $connection['port'] < 1 || $connection['port'] > 65535 || !in_array($connection['security'],['ssl','tls'],true)) return ['result'=>false,'error'=>'invalid_configuration'];
			$identityChanged = false;
			foreach (['host','port','security','username'] as $field) if ((string) ($stored[$field] ?? '') !== (string) $connection[$field]) $identityChanged = true;
			if ($identityChanged && (int) ($current['run_count'] ?? 0) > 0) return ['result'=>false,'error'=>'mailbox_identity_locked'];
			$password = (string) ($data[$side.'_password'] ?? '');
			// A revealed value resubmitted unchanged is never a new credential, even after editing the server.
			if ($password !== '' && hash_equals((string) (\imap\ConnectionStore::credentials((string) ($stored['id'] ?? ''))['password'] ?? ''),$password)) $password = '';
			if ($identityChanged || $password !== '') $connection['id'] = '';
			$changed = $changed || $identityChanged || $password !== '';
			$connections[$side] = ['metadata'=>$connection,'password'=>$password,'stored'=>$stored];
		}
		$domain = DnsMonitor::sourceDomain($connections['source']['metadata']['username']);
		if ($domain === '') return ['result'=>false,'error'=>'mail_domain_missing'];
		$created = [];
		$writeError = 'job_write_failed';
		$phase = (string) ($current['phase'] ?? 'draft');
		if ($expected === null && $changed && $phase === 'initial') $phase = 'draft';
		$job = [];
		try {
			$job = JobStore::save(array_merge($current,[
				'id'=>$current['id'] ?? '', 'name'=>$name, 'domain'=>$domain,
				'source_id'=>$current['source_id'] ?? '', 'destination_id'=>$current['destination_id'] ?? '',
				'phase'=>$phase, 'active'=>$changed ? 0 : (int) ($current['active'] ?? 0),
				'configuration_revision'=>bin2hex(random_bytes(16)),
				'report_user_id'=>(int) $recipient['id'], 'report_email'=>$email
			]),$current ?: null,function(array $job) use ($current,$expected,$connections,&$created,&$writeError): array {
				try {
					if ($expected !== null && !self::matchesIdentity($current,$expected)) { $writeError = 'target_changed'; return []; }
					$writeError = 'job_write_failed';
					foreach ($connections as $side => $connection) {
						if ($connection['metadata']['id'] !== '') {
							$connections[$side] = $connection['stored'];
							continue;
						}
						$connections[$side] = \imap\ConnectionStore::save($connection['metadata'],$connection['password']);
						$created[] = (string) ($connections[$side]['id'] ?? '');
						$persisted = \imap\ConnectionStore::credentials(end($created));
						$valid = !empty($persisted['id']);
						foreach (['host','port','security','username'] as $field) if ((string) ($persisted[$field] ?? '') !== (string) $connection['metadata'][$field]) $valid = false;
						if (!hash_equals($connection['password'],(string) ($persisted['password'] ?? ''))) $valid = false;
						if (!$valid) {
							$writeError = 'connection_write_failed';
							return [];
						}
					}
					if ($expected !== null) $job = array_merge($current,['configuration_revision'=>$job['configuration_revision'],'updated'=>$job['updated']]);
					foreach (['source','destination'] as $side) $job[$side.'_id'] = $connections[$side]['id'];
					return $job;
				} catch (\Throwable $exception) {
					$writeError = 'connection_write_failed';
					return [];
				}
			},$expected === null);
		} finally {
			// Also runs if persistence throws after the prepare callback created a secret.
			if (!$job) self::removeConnections($created);
		}
		if (!$job) {
			if ($expected !== null && JobStore::get($id) !== $current) $writeError = 'target_changed';
			return ['result'=>false,'error'=>$writeError];
		}
		foreach ($previousIds as $side => $previousId) {
			if ($previousId === '' || $previousId === $job[$side.'_id']) continue;
			$inUse = false;
			foreach (JobStore::list() as $stored) if (($stored['source_id'] ?? '') === $previousId || ($stored['destination_id'] ?? '') === $previousId) $inUse = true;
			if (!$inUse) \imap\ConnectionStore::delete($previousId);
		}
		return ['result'=>true,'id'=>$job['id'],'draft'=>$job['phase'] === 'draft'];
	}

	public static function setActive(string $id, bool $active): array {
		$job = JobStore::get($id);
		if (!$job) return ['result'=>false,'error'=>'job_not_found'];
		if (($job['phase'] ?? '') === 'completed') return ['result'=>false,'error'=>'job_completed'];
		if ($active) {
			$errors = [];
			foreach (['source','destination'] as $side) {
				$connection = \imap\ConnectionStore::credentials((string) ($job[$side.'_id'] ?? ''));
				if (empty($connection['host']) || empty($connection['username']) || empty($connection['password'])) {
					$errors[$side] = 'imap_credentials_missing';
					continue;
				}
				$result = MailboxSync::test($connection);
				if (empty($result['result'])) $errors[$side] = $result['error'] ?? 'imap_connection_failed';
			}
			if ($errors) return ['result'=>false,'error'=>'connections_not_ready','connections'=>$errors];
		}
		$applied = false;
		$updated = JobStore::update($id,function(array $stored) use ($active,$job,&$applied): array {
			if ($active) {
				foreach (['source_id','destination_id','configuration_revision'] as $field) if (($stored[$field] ?? '') !== ($job[$field] ?? '')) return $stored;
				if (($stored['phase'] ?? '') === 'completed') return $stored;
			}
			$applied = true;
			$stored['configuration_revision'] = bin2hex(random_bytes(16));
			$stored['active'] = $active ? 1 : 0;
			if ($active) {
				if (($stored['phase'] ?? '') === 'draft') $stored['phase'] = 'initial';
				$stored['next_run'] = 0;
			}
			return $stored;
		});
		if (!$updated) return ['result'=>false,'error'=>'job_write_failed'];
		if (!$applied) return ['result'=>false,'error'=>'configuration_changed'];
		if ($active) \ficms\Runtime::check('emailsync_worker',(int) ($_SERVER['now'] ?? time()),true,'seconds');
		return ['result'=>true,'id'=>$id];
	}
	private static function removeConnections(array $ids): void {
		foreach ($ids as $id) {
			if ($id === '') continue;
			$inUse = false;
			foreach (JobStore::list() as $job) if (($job['source_id'] ?? '') === $id || ($job['destination_id'] ?? '') === $id) $inUse = true;
			if (!$inUse) \imap\ConnectionStore::delete($id);
		}
	}
}
