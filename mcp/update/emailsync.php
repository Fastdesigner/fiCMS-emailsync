<?php

if (!$site['onsite']) return false;

if ($context->mode === 'describe') return [
	'purpose'=>'Edits an existing email-sync configuration or controls its runtime after get with view edit. Configuration changes never start a transfer; changed connections pause the job. Stored passwords are retained when omitted or blank, and never returned.',
	'args'=>['id'=>'Email-sync job id, or "settings".','data'=>'Job configuration: any subset of name, report_email, source_host/port/security/username/password and destination_host/port/security/username/password. Separately, one runtime command: active (0 or 1), run_next_cron (true), cutover (confirm or reset), or test_connection (source or destination; tests only the stored connection, never copies mail). Do not mix configuration and commands. For settings: interval_minutes, quiet_hours, monitoring_days, fallback_ttl_hours.'],
	'scope'=>['admin']
];

$emailsync_mcp = [
	'id'=>preg_replace('/[^a-z0-9_.-]/i','',trim((string) ($context->args['id'] ?? ''))),
	'data'=>is_array($context->args['data'] ?? null) ? $context->args['data'] : []
];
if ($emailsync_mcp['id'] === 'settings') {
	$emailsync_mcp['settings'] = \emailsync\Settings::get();
	foreach (['interval_minutes'=>['interval',60],'quiet_hours'=>['quiet_period',3600],'monitoring_days'=>['minimum_monitoring',86400],'fallback_ttl_hours'=>['fallback_ttl',3600]] as $emailsync_mcp['field'] => $emailsync_mcp['mapping']) if (isset($emailsync_mcp['data'][$emailsync_mcp['field']])) $emailsync_mcp['settings'][$emailsync_mcp['mapping'][0]] = max(1,(int) $emailsync_mcp['data'][$emailsync_mcp['field']]) * $emailsync_mcp['mapping'][1];
	$emailsync_mcp['settings'] = \emailsync\Settings::save($emailsync_mcp['settings']);
	return $emailsync_mcp['settings'] ? ['type'=>'emailsync','settings'=>$emailsync_mcp['settings']] : ['error'=>'Email-sync settings could not be saved.'];
}
if ($emailsync_mcp['id'] === '' || !\emailsync\JobStore::get($emailsync_mcp['id'])) return ['error'=>'Email-sync job not found.'];
$emailsync_mcp['configuration'] = array_intersect_key($emailsync_mcp['data'],array_flip(\emailsync\JobConfiguration::fields()));
$emailsync_mcp['commands'] = array_intersect_key($emailsync_mcp['data'],array_flip(['active','run_next_cron','cutover','test_connection']));
if (count($emailsync_mcp['configuration']) + count($emailsync_mcp['commands']) !== count($emailsync_mcp['data'])) return ['error'=>'unknown_configuration_field'];
if (($emailsync_mcp['configuration'] && $emailsync_mcp['commands']) || count($emailsync_mcp['commands']) > 1) return ['error'=>'send_configuration_and_one_runtime_command_separately'];
if ($emailsync_mcp['configuration']) {
	$emailsync_mcp['saved'] = \emailsync\JobConfiguration::save($emailsync_mcp['configuration'],$emailsync_mcp['id']);
	return empty($emailsync_mcp['saved']['result']) ? $emailsync_mcp['saved'] : \emailsync\McpView::read($emailsync_mcp['id']);
}
if (isset($emailsync_mcp['data']['test_connection'])) {
	if (!in_array($emailsync_mcp['data']['test_connection'],['source','destination'],true)) return ['error'=>'invalid_connection_side'];
	$emailsync_mcp['edit'] = \emailsync\McpView::read($emailsync_mcp['id'],true)['edit'];
	$emailsync_mcp['tested'] = \emailsync\JobConfiguration::testConnection($emailsync_mcp['edit'],$emailsync_mcp['id'],$emailsync_mcp['data']['test_connection']);
	return empty($emailsync_mcp['tested']['result']) ? $emailsync_mcp['tested'] : ['result'=>true,'type'=>'emailsync','id'=>$emailsync_mcp['id'],'status'=>'connection_verified','scope'=>['side'=>$emailsync_mcp['data']['test_connection'],'folders'=>(int) ($emailsync_mcp['tested']['folders'] ?? 0)]];
}
if (array_key_exists('active',$emailsync_mcp['data']) && !in_array($emailsync_mcp['data']['active'],[0,1],true)) return ['error'=>'active_must_be_0_or_1'];
if (array_key_exists('run_next_cron',$emailsync_mcp['data']) && !is_bool($emailsync_mcp['data']['run_next_cron'])) return ['error'=>'run_next_cron_must_be_boolean'];
if (isset($emailsync_mcp['data']['cutover']) && !in_array($emailsync_mcp['data']['cutover'],['confirm','reset'],true)) return ['error'=>'cutover must be confirm or reset.'];

if (array_key_exists('active',$emailsync_mcp['data']) || !empty($emailsync_mcp['data']['run_next_cron'])) {
	$emailsync_mcp['activation'] = \emailsync\JobConfiguration::setActive($emailsync_mcp['id'],!empty($emailsync_mcp['data']['run_next_cron']) || (int) $emailsync_mcp['data']['active'] === 1);
	if (empty($emailsync_mcp['activation']['result'])) return $emailsync_mcp['activation'];
}

$emailsync_mcp['job'] = \emailsync\JobStore::update($emailsync_mcp['id'],function(array $job) use ($emailsync_mcp): array {
	if (($emailsync_mcp['data']['cutover'] ?? '') === 'confirm' && ($job['phase'] ?? '') === 'monitoring') $job['cutover_detected'] = (int) ($_SERVER['now'] ?? time());
	if (($emailsync_mcp['data']['cutover'] ?? '') === 'reset' && ($job['phase'] ?? '') === 'monitoring') {
		$job['cutover_detected'] = 0;
		$job['baseline_mx'] = \emailsync\DnsMonitor::snapshot((string) ($job['domain'] ?? ''));
		$job['current_mx'] = $job['baseline_mx'];
	}
	return $job;
});

return \emailsync\McpView::read('job:'.$emailsync_mcp['id']);
