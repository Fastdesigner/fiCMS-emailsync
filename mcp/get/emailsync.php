<?php

if (!$site['onsite']) return false;

if ($context->mode === 'describe') return [
	'purpose'=>'Reads email-sync runtime readiness, jobs, connection metadata without secrets, progress, statistics and the latest run error. Use summary for an overview, jobs for the compact list, job:<id> for diagnostics, or folders:<id> to compare live IMAP folder structures.',
	'args'=>['id'=>'"summary", "jobs", "job:<id>", or "folders:<id>".'],
	'scope'=>['admin']
];

return \emailsync\McpView::read(trim((string) ($context->args['id'] ?? 'summary')));
