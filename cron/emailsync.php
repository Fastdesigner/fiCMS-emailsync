<?php

if (!$site['onsite']) return;

$emailsync = ['result'=>[]];
set_time_limit(0);
$emailsync['result'] = \emailsync\Runner::runDue();
if (($emailsync['result']['status'] ?? '') !== 'idle') helper__files_log([
	'Email sync '.(($emailsync['result']['success'] ?? false) ? 'completed' : 'failed').': '.($emailsync['result']['job']['id'] ?? 'unknown'),
	!empty($emailsync['result']['error']) ? 'Email sync error: '.$emailsync['result']['error'] : ''
]);

unset($emailsync);
