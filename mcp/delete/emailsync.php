<?php

if (!$site['onsite']) return false;

if ($context->mode === 'describe') return [
	'purpose'=>'Permanently deletes an email-sync job, its progress and statistics, and credentials that are no longer referenced by another job.',
	'args'=>['id'=>'Email-sync job id.'],
	'scope'=>['admin']
];

$emailsync_mcp = ['id'=>preg_replace('/[^a-z0-9_.-]/i','',trim((string) ($context->args['id'] ?? '')))];
if ($emailsync_mcp['id'] === '' || !\emailsync\JobStore::get($emailsync_mcp['id'])) return ['error'=>'Email-sync job not found.'];
return ['type'=>'emailsync','id'=>$emailsync_mcp['id'],'deleted'=>\emailsync\JobStore::delete($emailsync_mcp['id'])];
