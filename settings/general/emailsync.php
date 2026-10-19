<?php

if (!$site['onsite'] || !isset($settings['key']) || $html['is_superviser'] != 1) return;

$emailsync = [
	'output'=>['result'=>[]],
	'plugin_settings'=>\emailsync\Settings::get(),
	'probe'=>\emailsync\MailboxSync::probe()
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
		$emailsync['output']['result'] = \emailsync\JobConfiguration::testConnection($_POST,$emailsync['id'],(string) $_POST['side']) + ['side'=>(string) $_POST['side']];
		$_POST['handled'] = true;
	}

	if (!isset($_POST['handled']) && $emailsync['action'] == 'save') {
		$emailsync['output']['result'] = \emailsync\JobConfiguration::save($_POST,$emailsync['id'] !== 'new' ? $emailsync['id'] : '');
		$_POST['handled'] = true;
	}

	if (!isset($_POST['handled']) && $emailsync['action'] == 'ac' && $emailsync['id'] !== '') {
		$emailsync['output']['result'] = \emailsync\JobConfiguration::setActive($emailsync['id'],(int) ($_POST['value'] ?? 0) === 1);
		if (empty($emailsync['output']['result']['result'])) $emailsync['activation_error'] = [$emailsync['id']=>$emailsync['output']['result']];
		$_POST['handled'] = true;
	}

	if (!isset($_POST['handled']) && in_array($emailsync['action'],['cutover','reset_cutover'],true) && $emailsync['id'] !== '') {
		$emailsync['job'] = \emailsync\JobStore::update($emailsync['id'],function(array $job) use ($emailsync): array {
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
		// Gespeicherte Passwörter sieht nur der Supervisor in diesem Formular (Schutz am Dateianfang); MCP und Protokolle bleiben redigiert.
		$emailsync['credentials'] = [
			'source'=>!empty($emailsync['source']['has_secret']) ? \imap\ConnectionStore::credentials((string) $emailsync['job']['source_id']) : [],
			'destination'=>!empty($emailsync['destination']['has_secret']) ? \imap\ConnectionStore::credentials((string) $emailsync['job']['destination_id']) : []
		];
		$emailsync['data'] = [
			'name'=>$emailsync['job']['name'] ?? '',
			'report_email'=>$emailsync['job']['report_email'] ?? ($user['email'] ?? ''),
			'source_host'=>$emailsync['source']['host'] ?? '',
			'source_port'=>$emailsync['source']['port'] ?? 993,
			'source_security'=>$emailsync['source']['security'] ?? 'ssl',
			'source_username'=>$emailsync['source']['username'] ?? '',
			'source_password'=>(string) ($emailsync['credentials']['source']['password'] ?? ''),
			'destination_host'=>$emailsync['destination']['host'] ?? '',
			'destination_port'=>$emailsync['destination']['port'] ?? 993,
			'destination_security'=>$emailsync['destination']['security'] ?? 'ssl',
			'destination_username'=>$emailsync['destination']['username'] ?? '',
			'destination_password'=>(string) ($emailsync['credentials']['destination']['password'] ?? '')
		];

		$emailsync['form_ui'] = new \ficms\Ui($settings['key'],'emailsync',$user['language']);
		$emailsync['form_ui']->list($settings['form'].'-headline',['headline'=>empty($emailsync['job']) ? language__get($user['language'],'_emailsync_new') : (string) $emailsync['job']['name']]);
		$emailsync['body'] = $emailsync['form_ui']->list($settings['form'].'-body',['clear'=>true]);
		$emailsync['general'] = $emailsync['body'];
		if (!empty($emailsync['job'])) {
			$emailsync['job_tabs'] = $emailsync['body']->tabs('job-tabs');
			$emailsync['general'] = $emailsync['job_tabs']->tab('general',['labels'=>'emailsync_tab']);
		}
		$emailsync['general']->field('name','input',$emailsync['data']['name'],['call'=>false,'attrs'=>['required'=>'required']]);
		$emailsync['general']->field('report_email','input',$emailsync['data']['report_email'],['call'=>false,'attrs'=>['required'=>'required','inputmode'=>'email']]);
		$emailsync['general']->text('activation-info',language__get($user['language'],empty($emailsync['job']) || ($emailsync['job']['phase'] ?? '') === 'draft' ? '_emailsync_draft_info' : '_emailsync_change_info'));
		foreach (['source','destination'] as $emailsync['side']) {
			$emailsync['has_secret'] = !empty($emailsync[$emailsync['side']]['has_secret']);
			$emailsync['state'] = $emailsync['has_secret'] ? 'success' : 'warning';
			$emailsync['state_text'] = language__get($user['language'],$emailsync['has_secret'] ? '_emailsync_connection_saved' : '_emailsync_connection_pending');
			$emailsync['attrs'] = [
				'data-emailsync-connection'=>$emailsync['side'],
				'data-emailsync-job'=>empty($emailsync['job']) ? 'new' : (string) $emailsync['job']['id'],
				'data-emailsync-has-secret'=>$emailsync['has_secret'] ? '1' : '0'
			];
			foreach (['pending','checking','ready','failed'] as $emailsync['message']) $emailsync['attrs']['data-emailsync-text-'.$emailsync['message']] = language__get($user['language'],'_emailsync_connection_'.$emailsync['message']);
			$emailsync['connection'] = $emailsync['general']->dropdown($emailsync['side'],[
				'label'=>language__get($user['language'],'_emailsync_tab_'.$emailsync['side']),
				'subtitle'=>$emailsync['state_text'],
				'notify'=>$emailsync['state'],
				'attrs'=>$emailsync['attrs']
			]);
			$emailsync['connection']->field($emailsync['side'].'_host','input',$emailsync['data'][$emailsync['side'].'_host'],['call'=>false]);
			$emailsync['connection']->field($emailsync['side'].'_port','number',$emailsync['data'][$emailsync['side'].'_port'],['call'=>false,'attrs'=>['required'=>'required','min'=>1,'max'=>65535]]);
			$emailsync['connection']->field($emailsync['side'].'_security','select',$emailsync['data'][$emailsync['side'].'_security'],['call'=>false,'options'=>['ssl'=>'IMAPS (SSL/TLS)','tls'=>'IMAP + STARTTLS']]);
			$emailsync['connection']->field($emailsync['side'].'_username','input',$emailsync['data'][$emailsync['side'].'_username'],['call'=>false,'attrs'=>$emailsync['side'] === 'source' ? ['required'=>'required','inputmode'=>'email'] : []]);
			$emailsync['connection']->field($emailsync['side'].'_password','password',$emailsync['data'][$emailsync['side'].'_password'],['call'=>false,'attrs'=>$emailsync['has_secret'] && $emailsync['data'][$emailsync['side'].'_password'] === '' ? ['autocomplete'=>'new-password','placeholder'=>language__get($user['language'],'_emailsync_password_keep')] : ['autocomplete'=>'new-password']]);
			$emailsync['connection']->text($emailsync['side'].'-status',$emailsync['state_text'],['attrs'=>['data-emailsync-connection-status'=>$emailsync['side'],'data-notify'=>$emailsync['state']]]);
		}

		if (!empty($emailsync['job'])) {
			$emailsync['job_progress'] = \emailsync\ProgressStore::summary($emailsync['job']);
			$emailsync['status'] = $emailsync['job_tabs']->tab('status',['labels'=>'emailsync_tab'])->listing('status',['clear'=>true]);
			$emailsync['status']->item('status-phase',['label'=>language__get($user['language'],'_emailsync_phase'),'subtitle'=>language__get($user['language'],'_emailsync_phase_'.$emailsync['job']['phase'])]);
			$emailsync['status']->item('status-result',['label'=>language__get($user['language'],'_emailsync_last_result'),'subtitle'=>language__get($user['language'],'_emailsync_result_'.$emailsync['job']['last_result'])]+(($emailsync['job']['last_result'] ?? '') === 'failed' && (string) ($emailsync['job']['last_error'] ?? '') !== '' ? ['hint'=>(string) $emailsync['job']['last_error'],'attrs'=>['data-notify'=>'error']] : []));
			foreach (['total','processed','pending'] as $emailsync['counter']) $emailsync['status']->item('status-'.$emailsync['counter'],['label'=>language__get($user['language'],'_emailsync_statistics_messages_'.$emailsync['counter']),'subtitle'=>$emailsync['job_progress'][$emailsync['counter']]]);
			$emailsync['next_run'] = ($emailsync['job']['phase'] ?? '') === 'completed' ? language__get($user['language'],'_emailsync_phase_completed') : ((int) ($emailsync['job']['active'] ?? 0) !== 1 ? language__get($user['language'],'_emailsync_inactive') : ((int) ($emailsync['job']['next_run'] ?? 0) <= (int) ($_SERVER['now'] ?? time()) ? language__get($user['language'],'_emailsync_next_cron') : format__date_relative((int) $emailsync['job']['next_run'],'relative',$user['language'],true)));
			$emailsync['status']->item('status-next-run',['label'=>language__get($user['language'],'_emailsync_next_run'),'subtitle'=>$emailsync['next_run']]);
			if (!empty($emailsync['job']['last_run'])) $emailsync['status']->item('status-last-run',['label'=>language__get($user['language'],'_emailsync_last_run'),'subtitle'=>format__date_relative((int) $emailsync['job']['last_run'],'relative',$user['language'],true)]);
			if (!empty($emailsync['job']['cutover_detected'])) $emailsync['status']->item('status-cutover',['label'=>language__get($user['language'],'_emailsync_cutover'),'subtitle'=>format__date_relative((int) $emailsync['job']['cutover_detected'],'relative',$user['language'],true)]);
			if (!empty($emailsync['job']['baseline_mx']['targets'])) $emailsync['status']->item('status-baseline-mx',['label'=>language__get($user['language'],'_emailsync_baseline_mx'),'subtitle'=>htmlspecialchars(implode(', ',(array) $emailsync['job']['baseline_mx']['targets']),ENT_QUOTES,'UTF-8')]);
			if (($emailsync['job']['phase'] ?? '') === 'monitoring') $emailsync['status']->button('status-cutover-action',[
				'label'=>language__get($user['language'],empty($emailsync['job']['cutover_detected']) ? '_emailsync_cutover_manual' : '_emailsync_reset_cutover'),
				'action'=>empty($emailsync['job']['cutover_detected']) ? 'cutover' : 'reset_cutover',
				'aid'=>$emailsync['job']['id'],
				'confirm'=>language__get($user['language'],empty($emailsync['job']['cutover_detected']) ? '_emailsync_cutover_confirm' : '_emailsync_reset_cutover_confirm')
			]);

			$emailsync['job_graph'] = ['series'=>['messages_processed'=>['value'=>$emailsync['job_progress']['processed']]],'points'=>[]];
			foreach (\emailsync\Statistics::series((string) $emailsync['job']['id'],$emailsync['job_progress']['processed'],max(0,(int) ($emailsync['job']['run_count'] ?? 0)),(int) ($emailsync['job']['created'] ?? 0),(int) ($_SERVER['now'] ?? time())) as $emailsync['point']) $emailsync['job_graph']['points'][] = ['label'=>format__date_relative((int) $emailsync['point']['time'],'relative',$user['language'],true),'data'=>['messages_processed'=>(int) $emailsync['point']['value']]];
			$emailsync['job_charts'] = $emailsync['job_tabs']->tab('statistics',['labels'=>'emailsync_tab'])->listing('job-statistics',['kind'=>'statistics']);
			if (count($emailsync['job_graph']['points'])) $emailsync['job_charts']->statistics('job-graph','graph',statistics__format_graph($user['language'],$emailsync['job_graph'],['messages_processed'=>'_emailsync_statistics_messages_processed'],['gridLines'=>4,'legend'=>false,'smooth'=>false,'decimals'=>0]),['attrs'=>['data-span'=>'all','data-label'=>language__get($user['language'],'_emailsync_statistics_progress')]]);
			foreach (['messages_total','messages_processed','messages_pending','messages_transferred','messages_skipped','runs','errors'] as $emailsync['stat']) {
				$emailsync['job_value'] = isset($emailsync['job_progress'][substr($emailsync['stat'],9)]) ? $emailsync['job_progress'][substr($emailsync['stat'],9)] : ($emailsync['stat'] === 'runs' ? (int) ($emailsync['job']['run_count'] ?? 0) : (int) ($emailsync['job']['stats_total'][$emailsync['stat']] ?? 0));
				$emailsync['job_charts']->statistics('job-'.$emailsync['stat'],'info',['value'=>max(0,$emailsync['job_value']),'label'=>language__get($user['language'],'_emailsync_statistics_'.$emailsync['stat'])]);
			}
			if ($emailsync['job_progress']['historic'] > 0) $emailsync['job_charts']->statistics('job-messages-historic','info',['value'=>$emailsync['job_progress']['historic'],'label'=>language__get($user['language'],'_emailsync_statistics_messages_historic')]);
			$emailsync['job_charts']->statistics('job-bytes','info',['value'=>number_format(((int) ($emailsync['job']['stats_total']['bytes_transferred'] ?? 0)) / 1048576,2,',','.').' MB','label'=>language__get($user['language'],'_emailsync_statistics_bytes_transferred')]);
		}

		$emailsync['form_ui']->list($settings['form'].'-submit-wrapper',['clear'=>true])->button('submit',['label'=>language__get($user['language'],'_settings_form_save'),'action'=>'save','aid'=>empty($emailsync['job']) ? 'new' : $emailsync['job']['id']]);
		$emailsync['form_ui']->emit($settings);
		$_POST['handled'] = true;
	}
}

$emailsync['jobs'] = \emailsync\JobStore::list();
$emailsync['statistics'] = \emailsync\JobStore::statistics();
$emailsync['ui'] = new \ficms\Ui($settings['key'],'emailsync',$user['language']);
$emailsync['ui']->refresh($_SERVER['now'] + 60);

$emailsync['list'] = $emailsync['ui']->tab('synchronizations',['labels'=>'emailsync_tab'])->listing('list',['clear'=>true]);
foreach ($emailsync['jobs'] as $emailsync['job']) {
	$emailsync['job_progress'] = \emailsync\ProgressStore::summary($emailsync['job']);
	// Fehler einer Synchronisation (eben abgelehnte Aktivierung oder letzter fehlgeschlagener Lauf) markieren die Zeile
	// mit einem kurzen Zustand; die Gründe stehen hinter dem Hinweis am Eintrag statt mehrzeilig im Fluss.
	$emailsync['item_error'] = $emailsync['activation_error'][(string) $emailsync['job']['id']] ?? [];
	$emailsync['item_error_text'] = [];
	$emailsync['item_state'] = ($emailsync['job']['phase'] ?? '') === 'draft' ? '_emailsync_phase_draft' : '';
	if (!empty($emailsync['item_error'])) {
		$emailsync['item_state'] = '_emailsync_activation_failed';
		foreach (['source','destination'] as $emailsync['side']) if (!empty($emailsync['item_error']['connections'][$emailsync['side']])) {
			$emailsync['side_error'] = (string) $emailsync['item_error']['connections'][$emailsync['side']];
			$emailsync['item_error_text'][] = language__get($user['language'],'_emailsync_tab_'.$emailsync['side']).': '.($emailsync['side_error'] === 'imap_credentials_missing' ? language__get($user['language'],'_emailsync_connection_pending') : $emailsync['side_error']);
		}
		if (!count($emailsync['item_error_text'])) $emailsync['item_error_text'][] = (string) ($emailsync['item_error']['error'] ?? 'invalid_configuration');
	} elseif (($emailsync['job']['last_result'] ?? '') === 'failed' && (string) ($emailsync['job']['last_error'] ?? '') !== '') {
		$emailsync['item_state'] = '_emailsync_result_failed';
		$emailsync['item_error_text'][] = (string) $emailsync['job']['last_error'];
	}
	$emailsync['list']->item('job-'.$emailsync['job']['id'],[
		'label'=>(string) $emailsync['job']['name'],
		'subtitle'=>htmlspecialchars(($emailsync['item_state'] !== '' ? language__get($user['language'],$emailsync['item_state']).' · ' : '').(string) $emailsync['job']['domain'].($emailsync['job_progress']['total'] > 0 ? ' · '.$emailsync['job_progress']['processed'].' / '.$emailsync['job_progress']['total'].' '.language__get($user['language'],'_emailsync_statistics_emails') : ''),ENT_QUOTES,'UTF-8'),
		'progress'=>\emailsync\ProgressStore::ratio($emailsync['job']),
		'hint'=>count($emailsync['item_error_text']) ? implode(' · ',$emailsync['item_error_text']) : null,
		'attrs'=>count($emailsync['item_error_text']) ? ['data-notify'=>'error'] : [],
		'load'=>['id'=>$emailsync['job']['id'],'form'=>true],
		'toggle'=>['id'=>$emailsync['job']['id'],'action'=>'ac','call'=>'emailsync__activation','name'=>$settings['key'].'-'.$emailsync['job']['id'],'checked'=>(int) ($emailsync['job']['active'] ?? 0) === 1,'disabled'=>($emailsync['job']['phase'] ?? '') === 'completed'],
		'delete'=>['id'=>$emailsync['job']['id']]
	]);
}
if (!empty($emailsync['probe']['available'])) $emailsync['list']->item('new',['label'=>language__get($user['language'],'_emailsync_new'),'load'=>['id'=>'new','form'=>true]]);

$emailsync['runtime_state'] = !empty($emailsync['probe']['available']) ? 'available' : 'unavailable';
$emailsync['settings_tab'] = $emailsync['ui']->tab('settings',['labels'=>'emailsync_tab']);
$emailsync['runtime'] = $emailsync['settings_tab']->dropdown('runtime',[
	'label'=>language__get($user['language'],'_emailsync_runtime_title'),
	'subtitle'=>language__get_parsed($user['language'],'_emailsync_runtime_checked',['value'=>format__date_relative((int) $emailsync['probe']['checked'],'relative',$user['language'],true)]),
	'independent'=>true
]);
$emailsync['runtime']->text('runtime-status',language__get($user['language'],'_emailsync_runtime_status_'.$emailsync['runtime_state']));
$emailsync['runtime']->text('runtime-info',language__get($user['language'],'_emailsync_runtime_info_'.$emailsync['runtime_state']));

$emailsync['settings_tab']->form('settings-form',['id'=>$settings['key'].'-settings-form','label'=>false,'name'=>$settings['key'].'-settings','call'=>false]);
$emailsync['settings_body'] = $emailsync['ui']->slot($settings['key'].'-settings-form-body',['clear'=>true]);
$emailsync['settings_fields'] = [
	'interval_minutes'=>['interval',60,10080],
	'quiet_hours'=>['quiet_period',3600,720],
	'monitoring_days'=>['minimum_monitoring',86400,90],
	'fallback_ttl_hours'=>['fallback_ttl',3600,168]
];
foreach ($emailsync['settings_fields'] as $emailsync['field'] => $emailsync['setting']) $emailsync['settings_body']->field($emailsync['field'],'number',max(1,(int) ($emailsync['plugin_settings'][$emailsync['setting'][0]] / $emailsync['setting'][1])),['call'=>false,'attrs'=>['required'=>'required','min'=>1,'max'=>$emailsync['setting'][2]]]);
$emailsync['ui']->slot($settings['key'].'-settings-form-submit-wrapper',['clear'=>true])->button('settings-save',['label'=>language__get($user['language'],'_settings_form_save'),'action'=>'save_settings']);

$emailsync['charts'] = $emailsync['ui']->tab('statistics',['labels'=>'emailsync_tab'])->listing('statistics-list',['kind'=>'statistics']);
$emailsync['statistics_graph'] = ['series'=>['messages_processed'=>[]],'points'=>[]];
foreach ($emailsync['statistics']['daily'] as $emailsync['day'] => $emailsync['messages']) $emailsync['statistics_graph']['points'][] = ['label'=>format__date_relative($emailsync['day'],'date',$user['language']),'data'=>['messages_processed'=>$emailsync['messages']]];
$emailsync['statistics_graph']['points'] = statistics__limit_points($emailsync['statistics_graph']['points'],20);
if (count($emailsync['statistics_graph']['points'])) $emailsync['charts']->statistics('statistics-graph','graph',statistics__format_graph($user['language'],$emailsync['statistics_graph'],['messages_processed'=>'_emailsync_statistics_messages_processed'],['gridLines'=>4,'legend'=>false,'decimals'=>0]),['attrs'=>['data-span'=>'all','data-label'=>language__get($user['language'],'_emailsync_statistics_daily')]]);
foreach (array_filter(['jobs','active','draft','initial','monitoring','completed','failed','runs','messages_total','messages_processed','messages_pending','messages_transferred','messages_skipped'],fn($stat) => isset($emailsync['statistics'][$stat])) as $emailsync['stat']) $emailsync['charts']->statistics('statistics-'.$emailsync['stat'],'info',['value'=>(int) $emailsync['statistics'][$emailsync['stat']],'label'=>language__get($user['language'],'_emailsync_statistics_'.$emailsync['stat'])]);
if ($emailsync['statistics']['messages_historic'] > 0) $emailsync['charts']->statistics('statistics-messages-historic','info',['value'=>$emailsync['statistics']['messages_historic'],'label'=>language__get($user['language'],'_emailsync_statistics_messages_historic')]);
$emailsync['charts']->statistics('statistics-bytes','info',['value'=>number_format(((int) $emailsync['statistics']['bytes_transferred']) / 1048576,2,',','.').' MB','label'=>language__get($user['language'],'_emailsync_statistics_bytes_transferred')]);
$emailsync['charts']->statistics('statistics-last-run','info',['value'=>$emailsync['statistics']['last_run'] > 0 ? format__date_relative($emailsync['statistics']['last_run'],'relative',$user['language'],true) : language__get($user['language'],'_emailsync_statistics_never'),'label'=>language__get($user['language'],'_emailsync_statistics_last_run')]);

$emailsync['ui']->emit($settings);

foreach ($emailsync['output'] as $emailsync['key'] => $emailsync['value']) {
	if (empty($emailsync['value'])) continue;
	if (!isset($settings['output'][$emailsync['key']])) $settings['output'][$emailsync['key']] = [];
	$settings['output'][$emailsync['key']] = array_merge($settings['output'][$emailsync['key']],$emailsync['value']);
}

unset($emailsync);
