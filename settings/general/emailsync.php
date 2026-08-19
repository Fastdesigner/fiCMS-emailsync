<?php

if (!$site['onsite'] || !isset($settings['key']) || $html['is_superviser'] != 1) return;

$emailsync = [
	'output'=>['lists'=>[],'result'=>[]],
	'jobs'=>\emailsync\JobStore::list(),
	'plugin_settings'=>\emailsync\Settings::get(),
	'statistics'=>\emailsync\JobStore::statistics(),
	'probe'=>\emailsync\MailboxSync::probe(),
	'items'=>[]
];

if (isset($_POST['settings'],$_POST['type'],$_POST['action']) && $_POST['type'] == $settings['key']) {
	$emailsync['action'] = (string) $_POST['action'];
	$emailsync['id'] = preg_replace('/[^a-z0-9_.-]/i','',(string) ($_POST['id'] ?? ''));

	if (!isset($_POST['handled']) && $emailsync['action'] == 'save_settings') {
		$emailsync['plugin_settings'] = \emailsync\Settings::save([
			'interval'=>max(1,(int) ($_POST['interval_minutes'] ?? 60)) * 60,
			'quiet_period'=>max(1,(int) ($_POST['quiet_hours'] ?? 48)) * 3600,
			'minimum_monitoring'=>max(1,(int) ($_POST['monitoring_days'] ?? 7)) * 86400,
			'fallback_ttl'=>max(1,(int) ($_POST['fallback_ttl_hours'] ?? 24)) * 3600
		]);
		$emailsync['output']['result'] = ['result'=>!empty($emailsync['plugin_settings'])];
		$_POST['handled'] = true;
	}

	if (!isset($_POST['handled']) && $emailsync['action'] == 'test_connection' && in_array(($_POST['side'] ?? ''),['source','destination'],true)) {
		$emailsync['side'] = (string) $_POST['side'];
		$emailsync['job'] = $emailsync['id'] !== '' && $emailsync['id'] !== 'new' ? \emailsync\JobStore::get($emailsync['id']) : [];
		$emailsync['connection_id'] = (string) ($emailsync['job'][$emailsync['side'].'_id'] ?? '');
		$emailsync['stored_credentials'] = $emailsync['connection_id'] !== '' ? \imap\ConnectionStore::credentials($emailsync['connection_id']) : [];
		$emailsync['connection'] = [
			'host'=>$_POST[$emailsync['side'].'_host'] ?? '',
			'port'=>$_POST[$emailsync['side'].'_port'] ?? 993,
			'security'=>$_POST[$emailsync['side'].'_security'] ?? 'ssl',
			'username'=>$_POST[$emailsync['side'].'_username'] ?? '',
			'password'=>trim((string) ($_POST[$emailsync['side'].'_password'] ?? '')) !== '' ? (string) $_POST[$emailsync['side'].'_password'] : (string) ($emailsync['stored_credentials']['password'] ?? ''),
			'auth'=>'password'
		];
		$emailsync['output']['result'] = \emailsync\MailboxSync::test($emailsync['connection']) + ['side'=>$emailsync['side']];
		$_POST['handled'] = true;
	}

	if (!isset($_POST['handled']) && $emailsync['action'] == 'save') {
		$emailsync['job'] = $emailsync['id'] !== '' && $emailsync['id'] !== 'new' ? \emailsync\JobStore::get($emailsync['id']) : [];
		$emailsync['connections'] = [];
		$emailsync['tests'] = [];
		foreach (['source','destination'] as $emailsync['side']) {
			$emailsync['stored'] = !empty($emailsync['job'][$emailsync['side'].'_id']) ? \imap\ConnectionStore::get((string) $emailsync['job'][$emailsync['side'].'_id']) : [];
			$emailsync['stored_credentials'] = !empty($emailsync['stored']['id']) ? \imap\ConnectionStore::credentials((string) $emailsync['stored']['id']) : [];
			$emailsync['connections'][$emailsync['side']] = [
				'id'=>$emailsync['stored']['id'] ?? '',
				'name'=>trim((string) ($_POST['name'] ?? '')).' '.$emailsync['side'],
				'purpose'=>'migration',
				'host'=>$_POST[$emailsync['side'].'_host'] ?? '',
				'port'=>$_POST[$emailsync['side'].'_port'] ?? 993,
				'security'=>$_POST[$emailsync['side'].'_security'] ?? 'ssl',
				'username'=>$_POST[$emailsync['side'].'_username'] ?? '',
				'password'=>trim((string) ($_POST[$emailsync['side'].'_password'] ?? '')) !== '' ? (string) $_POST[$emailsync['side'].'_password'] : (string) ($emailsync['stored_credentials']['password'] ?? ''),
				'auth'=>'password'
			];
			$emailsync['tests'][$emailsync['side']] = \emailsync\MailboxSync::test($emailsync['connections'][$emailsync['side']]);
		}
		$emailsync['domain'] = \emailsync\DnsMonitor::sourceDomain((string) ($_POST['source_username'] ?? ''));
		$emailsync['report_user'] = isset($tables['user']) ? mysqlFetchAssoc("SELECT `id`,`email` FROM ".$tables['user']." WHERE `ac` = 1 AND `email` = '".mysqlEscape(trim((string) ($_POST['report_email'] ?? '')))."' LIMIT 1") : false;
		$emailsync['valid'] = (
			trim((string) ($_POST['name'] ?? '')) !== '' &&
			$emailsync['domain'] !== '' &&
			!empty($emailsync['tests']['source']['result']) &&
			!empty($emailsync['tests']['destination']['result']) &&
			filter_var((string) ($_POST['report_email'] ?? ''),FILTER_VALIDATE_EMAIL) &&
			!empty($emailsync['report_user']['id'])
		);
		if ($emailsync['valid']) {
			foreach (['source','destination'] as $emailsync['side']) {
				$emailsync['password'] = (string) ($emailsync['connections'][$emailsync['side']]['password'] ?? '');
				unset($emailsync['connections'][$emailsync['side']]['password']);
				$emailsync['connections'][$emailsync['side']] = \imap\ConnectionStore::save($emailsync['connections'][$emailsync['side']],$emailsync['password']);
			}
			$emailsync['job'] = \emailsync\JobStore::save([
				'id'=>$emailsync['job']['id'] ?? '',
				'name'=>$_POST['name'] ?? '',
				'domain'=>$emailsync['domain'],
				'source_id'=>$emailsync['connections']['source']['id'],
				'destination_id'=>$emailsync['connections']['destination']['id'],
				'phase'=>$emailsync['job']['phase'] ?? 'initial',
				'active'=>$emailsync['job']['active'] ?? 1,
				'report_user_id'=>(int) $emailsync['report_user']['id'],
				'report_email'=>$_POST['report_email'] ?? ''
			]);
			$emailsync['output']['result'] = ['result'=>true,'id'=>$emailsync['job']['id']];
		} else {
			$emailsync['output']['result'] = [
				'result'=>false,
				'error'=>$emailsync['domain'] === '' ? 'mail_domain_missing' : 'invalid_configuration',
				'connections'=>['source'=>$emailsync['tests']['source']['error'] ?? '','destination'=>$emailsync['tests']['destination']['error'] ?? '']
			];
		}
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
			'destination_password'=>''
		];
		$emailsync['security_options'] = [
			['option'=>'ssl','name'=>'IMAPS (SSL/TLS)','value'=>'ssl'],
			['option'=>'tls','name'=>'IMAP + STARTTLS','value'=>'tls']
		];
		$emailsync['inputs'] = [
			'name'=>['required'=>true],
			'report_email'=>['required'=>true,'type'=>'email'],
			'source_host'=>['required'=>true],
			'source_port'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>65535]],
			'source_security'=>['required'=>true,'type'=>'select','options'=>$emailsync['security_options']],
			'source_username'=>['required'=>true,'type'=>'email'],
			'source_password'=>['type'=>'password','required'=>empty($emailsync['source']['has_secret']),'attributes'=>['autocomplete'=>'new-password']],
			'destination_host'=>['required'=>true],
			'destination_port'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>65535]],
			'destination_security'=>['required'=>true,'type'=>'select','options'=>$emailsync['security_options']],
			'destination_username'=>['required'=>true],
			'destination_password'=>['type'=>'password','required'=>empty($emailsync['destination']['has_secret']),'attributes'=>['autocomplete'=>'new-password']]
		];
		$emailsync['formitems'] = create__form_items($emailsync['inputs'],$emailsync['data'],'emailsync',$user['language']);
		$emailsync['general_items'] = [];
		foreach (['name','report_email'] as $emailsync['field']) $emailsync['general_items'][] = ['id'=>$settings['key'].'-'.$emailsync['field'],'type'=>'form','classes'=>['forms__item'],'form'=>$emailsync['formitems'][$emailsync['field']]];
		$emailsync['form'] = [['id'=>$settings['key'].'-general-fields','tag'=>'div','classes'=>['forms__wrapper'],'items'=>$emailsync['general_items']]];
		foreach (['source','destination'] as $emailsync['side']) {
			$emailsync['connection_items'] = [];
			foreach (['host','port','security','username','password'] as $emailsync['field']) $emailsync['connection_items'][] = ['id'=>$settings['key'].'-'.$emailsync['side'].'-'.$emailsync['field'],'type'=>'form','classes'=>['forms__item'],'form'=>$emailsync['formitems'][$emailsync['side'].'_'.$emailsync['field']]];
			$emailsync['connection_items'][] = ['id'=>$settings['key'].'-'.$emailsync['side'].'-status','tag'=>'font','classes'=>['forms__item'],'attributes'=>['data-emailsync-connection-status'=>$emailsync['side']],'description'=>language__get($user['language'],'_emailsync_connection_pending')];
			$emailsync['dropdown'] = create__dropdown(
				$settings['key'].'-'.$emailsync['side'],
				language__get($user['language'],'_emailsync_tab_'.$emailsync['side']),
				['id'=>$settings['key'].'-'.$emailsync['side'].'-fields','tag'=>'div','classes'=>['forms__wrapper'],'items'=>$emailsync['connection_items']],
				['subtitle'=>language__get($user['language'],'_emailsync_connection_pending'),'notify'=>'warning']
			);
			$emailsync['dropdown']['attributes']['data-emailsync-connection'] = $emailsync['side'];
			$emailsync['dropdown']['attributes']['data-emailsync-job'] = empty($emailsync['job']) ? 'new' : (string) $emailsync['job']['id'];
			$emailsync['dropdown']['attributes']['data-emailsync-has-secret'] = !empty($emailsync[$emailsync['side']]['has_secret']) ? '1' : '0';
			$emailsync['form'][] = $emailsync['dropdown'];
		}
		$emailsync['form'] = [['id'=>$settings['key'].'-job-fields','tag'=>'div','classes'=>['forms__wrapper'],'items'=>$emailsync['form']]];
		$emailsync['output']['lists'] = create__form($settings['form'],$emailsync['form'],empty($emailsync['job']) ? language__get($user['language'],'_emailsync_new') : (string) $emailsync['job']['name'],language__get($user['language'],'_settings_form_save'),['load'=>['action'=>'save','id'=>empty($emailsync['job']) ? 'new' : $emailsync['job']['id']]]);
		$_POST['handled'] = true;
	}
}

foreach (\emailsync\JobStore::list() as $emailsync['job']) {
	$emailsync['job_statistics'] = \emailsync\Statistics::get((string) $emailsync['job']['id']);
	$emailsync['job_graph'] = ['series'=>['messages_transferred'=>[]],'points'=>[]];
	$emailsync['job_recorded'] = max(0,(int) ($emailsync['job_statistics']['totals']['messages_transferred'] ?? 0));
	$emailsync['job_cumulative'] = max(0,(int) ($emailsync['job']['stats_total']['messages_transferred'] ?? 0) - $emailsync['job_recorded']);
	if ($emailsync['job_cumulative'] > 0) $emailsync['job_graph']['points'][] = ['label'=>format__date_relative((int) ($emailsync['job']['created'] ?? $_SERVER['now']),'date',$user['language']),'data'=>['messages_transferred'=>$emailsync['job_cumulative']]];
	if (isset($emailsync['job_statistics']['data']) && is_array($emailsync['job_statistics']['data'])) {
		ksort($emailsync['job_statistics']['data']);
		foreach ($emailsync['job_statistics']['data'] as $emailsync['date'] => $emailsync['row']) {
			$emailsync['job_cumulative'] += max(0,(int) ($emailsync['row']['messages_transferred'] ?? 0));
			$emailsync['job_graph']['points'][] = ['label'=>format__date_relative((int) $emailsync['date'],'date',$user['language']),'data'=>['messages_transferred'=>$emailsync['job_cumulative']]];
		}
	}
	$emailsync['job_statistic_items'] = [];
	if (count($emailsync['job_graph']['points'])) $emailsync['job_statistic_items'][] = [
		'id'=>$settings['key'].'-'.$emailsync['job']['id'].'-graph',
		'type'=>'statistics',
		'chart'=>'graph',
		'attributes'=>['data-span'=>'all','data-label'=>language__get($user['language'],'_emailsync_statistics_progress')],
		'values'=>statistics__format_graph($user['language'],$emailsync['job_graph'],['messages_transferred'=>'_emailsync_statistics_messages_transferred'],['gridLines'=>4,'legend'=>false,'smooth'=>false,'decimals'=>0])
	];
	foreach (['messages_transferred','messages_discovered','messages_skipped','runs','errors'] as $emailsync['stat']) {
		$emailsync['job_value'] = $emailsync['stat'] === 'runs' ? (int) ($emailsync['job']['run_count'] ?? 0) : (int) ($emailsync['job']['stats_total'][$emailsync['stat']] ?? 0);
		$emailsync['job_statistic_items'][] = ['type'=>'statistics','chart'=>'info','values'=>['value'=>max(0,$emailsync['job_value']),'label'=>language__get($user['language'],'_emailsync_statistics_'.$emailsync['stat'])]];
	}
	$emailsync['job_statistic_items'][] = ['type'=>'statistics','chart'=>'info','values'=>['value'=>number_format(((int) ($emailsync['job']['stats_total']['bytes_transferred'] ?? 0)) / 1048576,2,',','.').' MB','label'=>language__get($user['language'],'_emailsync_statistics_bytes_transferred')]];
	$emailsync['details'] = [
		['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-phase','description'=>language__get($user['language'],'_emailsync_phase'),'subtitle'=>language__get($user['language'],'_emailsync_phase_'.$emailsync['job']['phase'])],
		['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-result','description'=>language__get($user['language'],'_emailsync_last_result'),'subtitle'=>language__get($user['language'],'_emailsync_result_'.$emailsync['job']['last_result'])]
	];
	$emailsync['details'][] = ['id'=>$settings['key'].'-'.$emailsync['job']['id'].'-statistics','classes'=>['statistics__wrapper'],'items'=>$emailsync['job_statistic_items']];
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
	$emailsync['items'][] = create__dropdown($settings['key'].'-'.$emailsync['job']['id'],htmlspecialchars((string) $emailsync['job']['name'],ENT_QUOTES,'UTF-8'),create__list($settings['key'].'-'.$emailsync['job']['id'].'-details',$emailsync['details'],['classes'=>['forms__wrapper'],'clear'=>true]),['subtitle'=>htmlspecialchars((string) $emailsync['job']['domain'],ENT_QUOTES,'UTF-8'),'progress'=>\emailsync\ProgressStore::ratio($emailsync['job'])]);
}

$emailsync['items'][] = ['id'=>$settings['key'].'-new','tag'=>'li','description'=>language__get($user['language'],'_emailsync_new'),'classes'=>['system-next'],'actions'=>['load'=>['action'=>'load','id'=>'new','form'=>true]]];
$emailsync['sync_list'] = create__list($settings['key'].'-list',$emailsync['items'],['classes'=>['forms__wrapper'],'clear'=>true,'sort'=>true]);

$emailsync['settings_data'] = [
	'interval_minutes'=>max(1,(int) ($emailsync['plugin_settings']['interval'] / 60)),
	'quiet_hours'=>max(1,(int) ($emailsync['plugin_settings']['quiet_period'] / 3600)),
	'monitoring_days'=>max(1,(int) ($emailsync['plugin_settings']['minimum_monitoring'] / 86400)),
	'fallback_ttl_hours'=>max(1,(int) ($emailsync['plugin_settings']['fallback_ttl'] / 3600))
];
$emailsync['settings_inputs'] = [
	'interval_minutes'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>10080]],
	'quiet_hours'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>720]],
	'monitoring_days'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>90]],
	'fallback_ttl_hours'=>['required'=>true,'type'=>'number','attributes'=>['min'=>1,'max'=>168]]
];
$emailsync['settings_formitems'] = create__form_items($emailsync['settings_inputs'],$emailsync['settings_data'],'emailsync',$user['language']);
$emailsync['settings_items'] = [[
	'id'=>$settings['key'].'-runtime',
	'type'=>'form',
	'classes'=>['forms__item'],
	'attributes'=>['data-notify'=>$emailsync['probe']['available'] ? 'success' : 'error'],
	'form'=>['type'=>'checkbox','option'=>'runtime_available','name'=>language__get($user['language'],$emailsync['probe']['available'] ? '_emailsync_runtime_available' : '_emailsync_runtime_unavailable'),'value'=>$emailsync['probe']['available'] ? 1 : 0,'disabled'=>true]
]];
foreach (['interval_minutes','quiet_hours','monitoring_days','fallback_ttl_hours'] as $emailsync['field']) $emailsync['settings_items'][] = ['id'=>$settings['key'].'-setting-'.$emailsync['field'],'type'=>'form','classes'=>['forms__item'],'form'=>$emailsync['settings_formitems'][$emailsync['field']]];
$emailsync['settings_items'][] = ['id'=>$settings['key'].'-settings-save','tag'=>'button','classes'=>['system-button'],'attributes'=>['type'=>'button'],'description'=>language__get($user['language'],'_settings_form_save'),'actions'=>['load'=>['action'=>'save_settings']]];
$emailsync['settings_form'] = ['id'=>$settings['key'].'-settings-form','tag'=>'form','classes'=>['forms__wrapper'],'items'=>$emailsync['settings_items']];

$emailsync['statistics_items'] = [];
foreach (['jobs','active','initial','monitoring','completed','failed','runs','messages_transferred'] as $emailsync['stat']) $emailsync['statistics_items'][] = ['id'=>$settings['key'].'-statistics-'.$emailsync['stat'],'type'=>'statistics','chart'=>'info','values'=>['value'=>(int) $emailsync['statistics'][$emailsync['stat']],'label'=>language__get($user['language'],'_emailsync_statistics_'.$emailsync['stat'])]];
$emailsync['statistics_items'][] = ['id'=>$settings['key'].'-statistics-bytes','type'=>'statistics','chart'=>'info','values'=>['value'=>number_format(((int) $emailsync['statistics']['bytes_transferred']) / 1048576,2,',','.').' MB','label'=>language__get($user['language'],'_emailsync_statistics_bytes_transferred')]];
$emailsync['statistics_items'][] = ['id'=>$settings['key'].'-statistics-last-run','type'=>'statistics','chart'=>'info','values'=>['value'=>$emailsync['statistics']['last_run'] > 0 ? format__date_relative($emailsync['statistics']['last_run'],'relative',$user['language'],true) : language__get($user['language'],'_emailsync_statistics_never'),'label'=>language__get($user['language'],'_emailsync_statistics_last_run')]];
$emailsync['statistics_list'] = ['id'=>$settings['key'].'-statistics-list','classes'=>['statistics__wrapper'],'items'=>$emailsync['statistics_items']];

$emailsync['tablist'] = [
	'synchronizations'=>language__get($user['language'],'_emailsync_tab_synchronizations'),
	'settings'=>language__get($user['language'],'_emailsync_tab_settings'),
	'statistics'=>language__get($user['language'],'_emailsync_tab_statistics')
];
$emailsync['tabs'] = create__tablist($settings['key'].'-main',$emailsync['tablist'],[
	'synchronizations'=>[$emailsync['sync_list']],
	'settings'=>[$emailsync['settings_form']],
	'statistics'=>[$emailsync['statistics_list']]
],[
	'synchronizations'=>['classes'=>['forms__wrapper']],
	'settings'=>['classes'=>['forms__wrapper']],
	'statistics'=>['classes'=>['forms__wrapper']]
]);
$emailsync['output']['lists'][$settings['key'].'Content'] = ['id'=>$settings['key'].'Content','refresh'=>($_SERVER['now'] + 60),'classes'=>['forms__wrapper'],'items'=>[$emailsync['tabs']['tabs'],$emailsync['tabs']['panels']]];

foreach ($emailsync['output'] as $emailsync['key'] => $emailsync['value']) {
	if (empty($emailsync['value'])) continue;
	if (!isset($settings['output'][$emailsync['key']])) $settings['output'][$emailsync['key']] = [];
	$settings['output'][$emailsync['key']] = array_merge($settings['output'][$emailsync['key']],$emailsync['value']);
}

unset($emailsync);
