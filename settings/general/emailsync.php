<?php

if (!$site['onsite'] || !isset($settings['key']) || $html['is_superviser'] != 1) return;

$emailsync = [
	'output'=>['lists'=>[],'result'=>[]],
	'jobs'=>\emailsync\JobStore::list(),
	'probe'=>\emailsync\MailboxSync::probe(),
	'items'=>[]
];

if (isset($_POST['settings'],$_POST['type'],$_POST['action']) && $_POST['type'] == $settings['key']) {
	$emailsync['action'] = (string) $_POST['action'];
	$emailsync['id'] = preg_replace('/[^a-z0-9_.-]/i','',(string) ($_POST['id'] ?? ''));

	if (!isset($_POST['handled']) && $emailsync['action'] == 'save') {
		$emailsync['job'] = $emailsync['id'] !== '' && $emailsync['id'] !== 'new' ? \emailsync\JobStore::get($emailsync['id']) : [];
		$emailsync['source'] = !empty($emailsync['job']['source_id']) ? \imap\ConnectionStore::get((string) $emailsync['job']['source_id']) : [];
		$emailsync['destination'] = !empty($emailsync['job']['destination_id']) ? \imap\ConnectionStore::get((string) $emailsync['job']['destination_id']) : [];
		$emailsync['source_data'] = [
			'id'=>$emailsync['source']['id'] ?? '',
			'name'=>trim((string) ($_POST['name'] ?? '')).' source',
			'purpose'=>'migration',
			'host'=>$_POST['source_host'] ?? '',
			'port'=>$_POST['source_port'] ?? 993,
			'security'=>$_POST['source_security'] ?? 'ssl',
			'username'=>$_POST['source_username'] ?? '',
			'auth'=>'password'
		];
		$emailsync['destination_data'] = [
			'id'=>$emailsync['destination']['id'] ?? '',
			'name'=>trim((string) ($_POST['name'] ?? '')).' destination',
			'purpose'=>'migration',
			'host'=>$_POST['destination_host'] ?? '',
			'port'=>$_POST['destination_port'] ?? 993,
			'security'=>$_POST['destination_security'] ?? 'ssl',
			'username'=>$_POST['destination_username'] ?? '',
			'auth'=>'password'
		];
		$emailsync['domain_snapshot'] = \emailsync\DnsMonitor::snapshot((string) ($_POST['domain'] ?? ''));
		$emailsync['report_user'] = isset($tables['user']) ? mysqlFetchAssoc("SELECT `id`,`email` FROM ".$tables['user']." WHERE `ac` = 1 AND `email` = '".mysqlEscape(trim((string) ($_POST['report_email'] ?? '')))."' LIMIT 1") : false;
		$emailsync['valid'] = (
			trim((string) ($_POST['name'] ?? '')) !== '' &&
			\imap\ConnectionStore::valid($emailsync['source_data'],!empty($_POST['source_password']) || !empty($emailsync['source']['has_secret'])) &&
			\imap\ConnectionStore::valid($emailsync['destination_data'],!empty($_POST['destination_password']) || !empty($emailsync['destination']['has_secret'])) &&
			!empty($emailsync['domain_snapshot']['domain']) &&
			filter_var((string) ($_POST['report_email'] ?? ''),FILTER_VALIDATE_EMAIL) &&
			!empty($emailsync['report_user']['id'])
		);
		if ($emailsync['valid']) {
			$emailsync['source'] = \imap\ConnectionStore::save($emailsync['source_data'],(string) ($_POST['source_password'] ?? ''));
			$emailsync['destination'] = \imap\ConnectionStore::save($emailsync['destination_data'],(string) ($_POST['destination_password'] ?? ''));
			$emailsync['job'] = \emailsync\JobStore::save([
				'id'=>$emailsync['job']['id'] ?? '',
				'name'=>$_POST['name'] ?? '',
				'domain'=>$_POST['domain'] ?? '',
				'source_id'=>$emailsync['source']['id'],
				'destination_id'=>$emailsync['destination']['id'],
				'expected_mx'=>$_POST['expected_mx'] ?? '',
				'phase'=>$emailsync['job']['phase'] ?? 'initial',
				'active'=>$emailsync['job']['active'] ?? 1,
				'interval'=>max(1,(int) ($_POST['interval_minutes'] ?? 60)) * 60,
				'quiet_period'=>max(1,(int) ($_POST['quiet_hours'] ?? 48)) * 3600,
				'minimum_monitoring'=>max(1,(int) ($_POST['monitoring_days'] ?? 7)) * 86400,
				'fallback_ttl'=>max(1,(int) ($_POST['fallback_ttl_hours'] ?? 24)) * 3600,
				'report_user_id'=>(int) $emailsync['report_user']['id'],
				'report_email'=>$_POST['report_email'] ?? ''
			]);
			$emailsync['output']['result'] = ['result'=>true,'id'=>$emailsync['job']['id']];
		} else $emailsync['output']['result'] = ['result'=>false,'error'=>'invalid_configuration'];
		$_POST['handled'] = true;
	}

	if (!isset($_POST['handled']) && in_array($emailsync['action'],['schedule','pause','resume','cutover','reset_cutover'],true) && $emailsync['id'] !== '') {
		$emailsync['job'] = \emailsync\JobStore::update($emailsync['id'],function(array $job) use ($emailsync): array {
			if ($emailsync['action'] == 'pause') $job['active'] = 0;
			if ($emailsync['action'] == 'resume') $job['active'] = 1;
			if ($emailsync['action'] == 'schedule') {
				$job['active'] = 1;
				$job['next_run'] = 0;
			}
			if ($emailsync['action'] == 'cutover' && ($job['phase'] ?? '') === 'monitoring') $job['cutover_detected'] = (int) ($_SERVER['now'] ?? time());
			if ($emailsync['action'] == 'reset_cutover' && ($job['phase'] ?? '') === 'monitoring') {
				$job['cutover_detected'] = 0;
				$job['baseline_mx'] = \emailsync\DnsMonitor::snapshot((string) ($job['domain'] ?? ''));
				$job['current_mx'] = $job['baseline_mx'];
			}
			return $job;
		});
		$emailsync['output']['result'] = ['result'=>!empty($emailsync['job'])];
		$_POST['handled'] = true;
	}

	if (!isset($_POST['handled']) && $emailsync['action'] == 'delete' && $emailsync['id'] !== '') {
		$emailsync['output']['result'] = ['result'=>\emailsync\JobStore::delete($emailsync['id'])];
		$_POST['handled'] = true;
	}

	if (!isset($_POST['handled']) && $emailsync['action'] == 'load') {
		$emailsync['job'] = $emailsync['id'] !== '' && $emailsync['id'] !== 'new' ? \emailsync\JobStore::get($emailsync['id']) : [];
		$emailsync['source'] = !empty($emailsync['job']['source_id']) ? \imap\ConnectionStore::get((string) $emailsync['job']['source_id']) : [];
		$emailsync['destination'] = !empty($emailsync['job']['destination_id']) ? \imap\ConnectionStore::get((string) $emailsync['job']['destination_id']) : [];
		$emailsync['data'] = [
			'name'=>$emailsync['job']['name'] ?? '',
			'domain'=>$emailsync['job']['domain'] ?? '',
			'report_email'=>$emailsync['job']['report_email'] ?? ($user['email'] ?? ''),
			'source_host'=>$emailsync['source']['host'] ?? '',
			'source_port'=>$emailsync['source']['port'] ?? 993,
			'source_security'=>$emailsync['source']['security'] ?? 'ssl',
			'source_username'=>$emailsync['source']['username'] ?? '',
			'source_password'=>'',
			'destination_host'=>$emailsync['destination']['host'] ?? '',
			'destination_port'=>$emailsync['destination']['port'] ?? 993,
			'destination_security'=>$emailsync['destination']['security'] ?? 'ssl',
			'destination_username'=>$emailsync['destination']['username'] ?? '',
			'destination_password'=>'',
			'expected_mx'=>implode(PHP_EOL,(array) ($emailsync['job']['expected_mx'] ?? [])),
			'interval_minutes'=>max(1,(int) (($emailsync['job']['interval'] ?? 3600) / 60)),
			'quiet_hours'=>max(1,(int) (($emailsync['job']['quiet_period'] ?? 172800) / 3600)),
			'monitoring_days'=>max(1,(int) (($emailsync['job']['minimum_monitoring'] ?? 604800) / 86400)),
			'fallback_ttl_hours'=>max(1,(int) (($emailsync['job']['fallback_ttl'] ?? 86400) / 3600))
		];
		$emailsync['security_options'] = [
			['option'=>'ssl','name'=>'IMAPS (SSL/TLS)','value'=>'ssl'],
			['option'=>'tls','name'=>'IMAP + STARTTLS','value'=>'tls']
		];
		$emailsync['inputs'] = [
			'name'=>['required'=>true],
			'domain'=>['required'=>true],
			'report_email'=>['required'=>true,'type'=>'email'],
			'source_host'=>['required'=>true],
			'source_port'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>65535]],
			'source_security'=>['required'=>true,'type'=>'select','options'=>$emailsync['security_options']],
			'source_username'=>['required'=>true],
			'source_password'=>['type'=>'password','required'=>empty($emailsync['source']['has_secret']),'attributes'=>['autocomplete'=>'new-password']],
			'destination_host'=>['required'=>true],
			'destination_port'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>65535]],
			'destination_security'=>['required'=>true,'type'=>'select','options'=>$emailsync['security_options']],
			'destination_username'=>['required'=>true],
			'destination_password'=>['type'=>'password','required'=>empty($emailsync['destination']['has_secret']),'attributes'=>['autocomplete'=>'new-password']],
			'expected_mx'=>['type'=>'textarea','attributes'=>['rows'=>4]],
			'interval_minutes'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>10080]],
			'quiet_hours'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>720]],
			'monitoring_days'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>90]],
			'fallback_ttl_hours'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>168]]
		];
		$emailsync['formitems'] = create__form_items($emailsync['inputs'],$emailsync['data'],'emailsync',$user['language']);
		$emailsync['tabs_entries'] = ['general'=>[],'source'=>[],'destination'=>[],'monitoring'=>[]];
		foreach (['name','domain','report_email'] as $emailsync['field']) $emailsync['tabs_entries']['general'][] = ['id'=>$settings['key'].'-'.$emailsync['field'],'type'=>'form','classes'=>['forms__item'],'form'=>$emailsync['formitems'][$emailsync['field']]];
		foreach (['source_host','source_port','source_security','source_username','source_password'] as $emailsync['field']) $emailsync['tabs_entries']['source'][] = ['id'=>$settings['key'].'-'.$emailsync['field'],'type'=>'form','classes'=>['forms__item'],'form'=>$emailsync['formitems'][$emailsync['field']]];
		foreach (['destination_host','destination_port','destination_security','destination_username','destination_password'] as $emailsync['field']) $emailsync['tabs_entries']['destination'][] = ['id'=>$settings['key'].'-'.$emailsync['field'],'type'=>'form','classes'=>['forms__item'],'form'=>$emailsync['formitems'][$emailsync['field']]];
		foreach (['expected_mx','interval_minutes','quiet_hours','monitoring_days','fallback_ttl_hours'] as $emailsync['field']) $emailsync['tabs_entries']['monitoring'][] = ['id'=>$settings['key'].'-'.$emailsync['field'],'type'=>'form','classes'=>['forms__item'],'form'=>$emailsync['formitems'][$emailsync['field']]];
		$emailsync['tablist'] = [
			'general'=>language__get($user['language'],'_emailsync_tab_general'),
			'source'=>language__get($user['language'],'_emailsync_tab_source'),
			'destination'=>language__get($user['language'],'_emailsync_tab_destination'),
			'monitoring'=>language__get($user['language'],'_emailsync_tab_monitoring')
		];
		$emailsync['tabs'] = create__tablist($settings['key'].'-form-tabs',$emailsync['tablist'],$emailsync['tabs_entries']);
		$emailsync['form'] = [['id'=>$settings['key'].'-form-tabs-wrapper','classes'=>['forms__wrapper'],'items'=>[$emailsync['tabs']['tabs'],$emailsync['tabs']['panels']]]];
		$emailsync['output']['lists'] = create__form($settings['form'],$emailsync['form'],empty($emailsync['job']) ? language__get($user['language'],'_emailsync_new') : (string) $emailsync['job']['name'],language__get($user['language'],'_settings_form_save'),['load'=>['action'=>'save','id'=>empty($emailsync['job']) ? 'new' : $emailsync['job']['id']]]);
		$_POST['handled'] = true;
	}
}

$emailsync['probe_item'] = [
	'id'=>$settings['key'].'-runtime',
	'tag'=>'font',
	'classes'=>['forms__item'],
	'description'=>language__get($user['language'],$emailsync['probe']['available'] ? '_emailsync_runtime_ready' : '_emailsync_runtime_missing'),
	'subtitle'=>$emailsync['probe']['available'] ? $emailsync['probe']['version'] : language__get($user['language'],'_emailsync_runtime_missing_info')
];
$emailsync['items'][] = $emailsync['probe_item'];

foreach (\emailsync\JobStore::list() as $emailsync['job']) {
	$emailsync['details'] = [
		['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-phase','description'=>language__get($user['language'],'_emailsync_phase'),'subtitle'=>language__get($user['language'],'_emailsync_phase_'.$emailsync['job']['phase'])],
		['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-result','description'=>language__get($user['language'],'_emailsync_last_result'),'subtitle'=>language__get($user['language'],'_emailsync_result_'.$emailsync['job']['last_result'])]
	];
	if (!empty($emailsync['job']['last_run'])) $emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-last-run','description'=>language__get($user['language'],'_emailsync_last_run'),'subtitle'=>format__date_relative((int) $emailsync['job']['last_run'],'relative',$user['language'],true)];
	if (!empty($emailsync['job']['cutover_detected'])) $emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-cutover','description'=>language__get($user['language'],'_emailsync_cutover'),'subtitle'=>format__date_relative((int) $emailsync['job']['cutover_detected'],'relative',$user['language'],true)];
	if (!empty($emailsync['job']['baseline_mx']['targets'])) $emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-baseline-mx','description'=>language__get($user['language'],'_emailsync_baseline_mx'),'subtitle'=>htmlspecialchars(implode(', ',(array) $emailsync['job']['baseline_mx']['targets']),ENT_QUOTES,'UTF-8')];
	$emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-edit','tag'=>'button','classes'=>['system-button'],'attributes'=>['type'=>'button'],'description'=>language__get($user['language'],'_emailsync_edit'),'actions'=>['load'=>['action'=>'load','id'=>$emailsync['job']['id'],'form'=>true]]];
	if (($emailsync['job']['phase'] ?? '') !== 'completed') {
		$emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-schedule','tag'=>'button','classes'=>['system-button'],'attributes'=>['type'=>'button'],'description'=>language__get($user['language'],'_emailsync_schedule'),'actions'=>['load'=>['action'=>'schedule','id'=>$emailsync['job']['id']]]];
		$emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-active','tag'=>'button','classes'=>['system-button'],'attributes'=>['type'=>'button'],'description'=>language__get($user['language'],(int) ($emailsync['job']['active'] ?? 0) === 1 ? '_emailsync_pause' : '_emailsync_resume'),'actions'=>['load'=>['action'=>(int) ($emailsync['job']['active'] ?? 0) === 1 ? 'pause' : 'resume','id'=>$emailsync['job']['id']]]];
		if (($emailsync['job']['phase'] ?? '') === 'monitoring') $emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-cutover-action','tag'=>'button','classes'=>['system-button'],'attributes'=>['type'=>'button','data-confirmation'=>language__get($user['language'],empty($emailsync['job']['cutover_detected']) ? '_emailsync_cutover_confirm' : '_emailsync_reset_cutover_confirm')],'description'=>language__get($user['language'],empty($emailsync['job']['cutover_detected']) ? '_emailsync_cutover_manual' : '_emailsync_reset_cutover'),'actions'=>['load'=>['action'=>empty($emailsync['job']['cutover_detected']) ? 'cutover' : 'reset_cutover','id'=>$emailsync['job']['id']]]];
	}
	$emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-delete','tag'=>'button','classes'=>['system-button'],'attributes'=>['type'=>'button','data-confirmation'=>language__get($user['language'],'_ui_confirm_delete')],'description'=>language__get($user['language'],'_emailsync_delete'),'actions'=>['load'=>['action'=>'delete','id'=>$emailsync['job']['id']]]];
	$emailsync['items'][] = create__dropdown($settings['key'].'-'.$emailsync['job']['id'],htmlspecialchars((string) $emailsync['job']['name'],ENT_QUOTES,'UTF-8'),create__list($settings['key'].'-'.$emailsync['job']['id'].'-details',$emailsync['details'],['clear'=>true]),['subtitle'=>htmlspecialchars((string) $emailsync['job']['domain'],ENT_QUOTES,'UTF-8')]);
}

$emailsync['items'][] = ['id'=>$settings['key'].'-new','tag'=>'li','description'=>language__get($user['language'],'_emailsync_new'),'actions'=>['load'=>['action'=>'load','id'=>'new','form'=>true]]];
$emailsync['output']['lists'][$settings['key'].'Content'] = ['id'=>$settings['key'].'Content','refresh'=>($_SERVER['now'] + 60),'items'=>[create__list($settings['key'].'-list',$emailsync['items'],['clear'=>true,'sort'=>true])]];

foreach ($emailsync['output'] as $emailsync['key'] => $emailsync['value']) {
	if (empty($emailsync['value'])) continue;
	if (!isset($settings['output'][$emailsync['key']])) $settings['output'][$emailsync['key']] = [];
	$settings['output'][$emailsync['key']] = array_merge($settings['output'][$emailsync['key']],$emailsync['value']);
}

unset($emailsync);
