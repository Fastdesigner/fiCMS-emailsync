<?php

if (!$site['onsite']) return false;

if ($context->mode === 'describe') return [
	'purpose'=>'Controls an existing email-sync job after reading it. It can activate or pause the job, schedule it for the next cron, and confirm or reset the observed MX cutover.',
	'args'=>['id'=>'Email-sync job id.','data'=>'Any of: active (0 or 1), run_next_cron (true), cutover ("confirm" or "reset").'],
	'scope'=>['admin']
];

$emailsync_mcp = [
	'id'=>preg_replace('/[^a-z0-9_.-]/i','',trim((string) ($context->args['id'] ?? ''))),
	'data'=>is_array($context->args['data'] ?? null) ? $context->args['data'] : []
];
if ($emailsync_mcp['id'] === '' || !\emailsync\JobStore::get($emailsync_mcp['id'])) return ['error'=>'Email-sync job not found.'];
if (isset($emailsync_mcp['data']['cutover']) && !in_array($emailsync_mcp['data']['cutover'],['confirm','reset'],true)) return ['error'=>'cutover must be confirm or reset.'];

$emailsync_mcp['job'] = \emailsync\JobStore::update($emailsync_mcp['id'],function(array $job) use ($emailsync_mcp): array {
	if (array_key_exists('active',$emailsync_mcp['data']) && ($job['phase'] ?? '') !== 'completed') {
		$job['active'] = (int) $emailsync_mcp['data']['active'] === 1 ? 1 : 0;
		if ($job['active'] === 1) $job['next_run'] = 0;
	}
	if (!empty($emailsync_mcp['data']['run_next_cron']) && ($job['phase'] ?? '') !== 'completed') {
		$job['active'] = 1;
		$job['next_run'] = 0;
	}
	if (($emailsync_mcp['data']['cutover'] ?? '') === 'confirm' && ($job['phase'] ?? '') === 'monitoring') $job['cutover_detected'] = (int) ($_SERVER['now'] ?? time());
	if (($emailsync_mcp['data']['cutover'] ?? '') === 'reset' && ($job['phase'] ?? '') === 'monitoring') {
		$job['cutover_detected'] = 0;
		$job['baseline_mx'] = \emailsync\DnsMonitor::snapshot((string) ($job['domain'] ?? ''));
		$job['current_mx'] = $job['baseline_mx'];
	}
	return $job;
});

return \emailsync\McpView::read('job:'.$emailsync_mcp['id']);
