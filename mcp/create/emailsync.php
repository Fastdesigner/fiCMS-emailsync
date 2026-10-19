<?php

if (!$site['onsite']) return false;

if ($context->mode === 'describe') return [
	'purpose'=>'Creates an inactive email-sync draft through the same configuration service as Settings. Saving never connects to IMAP or copies mail. Several source jobs may point to the same destination mailbox; each retains its own progress.',
	'args'=>['data'=>'Required: name, report_email (an active CMS user), source_username (email address). Optional source_host, source_port (993), source_security (ssl or tls), source_password, destination_host, destination_port (993), destination_security (ssl or tls), destination_username, destination_password. Missing passwords may be added later in Settings. Passwords are never returned. Activation is a separate update after both connections are ready.'],
	'scope'=>['admin']
];

$emailsync_mcp = ['data'=>(array) ($context->args['data'] ?? [])];
if (array_diff(array_keys($emailsync_mcp['data']),\emailsync\JobConfiguration::fields())) return ['error'=>'unknown_configuration_field'];
$emailsync_mcp['saved'] = \emailsync\JobConfiguration::save($emailsync_mcp['data']);
if (empty($emailsync_mcp['saved']['result'])) return $emailsync_mcp['saved'];
return ['type'=>'emailsync','id'=>$emailsync_mcp['saved']['id'],'created'=>true,'active'=>0,'phase'=>'draft'];
