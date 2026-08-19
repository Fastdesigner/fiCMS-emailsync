<?php

if (!$site['onsite']) return;

if ($reports['mode'] == 'meta') {
	$reports['meta'] = ['active'=>1,'selfsend'=>0,'subject'=>'_emailsync_report_subject','schedule'=>['mode'=>'event']];
	return;
}

if (!isset($reports['emailsync_events'])) $reports['emailsync_events'] = \emailsync\JobStore::pendingEvents();

if ($reports['mode'] == 'due') {
	$reports['due'] = !empty($reports['emailsync_events']);
	return;
}

if (!$reports['emailsync_events']) return;

if ($reports['mode'] == 'recipients') {
	$reports['emailsync_recipients'] = [];
	foreach ($reports['recipients'] as $reports['emailsync_email'] => $reports['emailsync_recipient']) {
		foreach ($reports['emailsync_events'] as $reports['emailsync_event']) {
			if ((int) ($reports['emailsync_event']['report_user_id'] ?? 0) !== (int) ($reports['emailsync_recipient']['user']['id'] ?? 0) && strcasecmp((string) ($reports['emailsync_event']['report_email'] ?? ''),(string) $reports['emailsync_email']) !== 0) continue;
			$reports['emailsync_recipients'][$reports['emailsync_email']] = $reports['emailsync_recipient'];
			break;
		}
	}
	$reports['recipients'] = $reports['emailsync_recipients'];
	return;
}

if ($reports['mode'] != 'send') return;

$reports['emailsync_sent'] = [];
foreach ($reports['recipients'] as $reports['emailsync_email'] => $reports['emailsync_recipient']) {
	$reports['emailsync_list'] = [];
	$reports['emailsync_language'] = (string) (($reports['emailsync_recipient']['user']['language'] ?? '') ?: $site['default_language']);
	foreach ($reports['emailsync_events'] as $reports['emailsync_event']) {
		if ((int) ($reports['emailsync_event']['report_user_id'] ?? 0) !== (int) ($reports['emailsync_recipient']['user']['id'] ?? 0) && strcasecmp((string) ($reports['emailsync_event']['report_email'] ?? ''),(string) $reports['emailsync_email']) !== 0) continue;
		$reports['emailsync_summary'] = (array) ($reports['emailsync_event']['summary'] ?? []);
		$reports['emailsync_stats'] = (array) ($reports['emailsync_summary']['stats'] ?? []);
		$reports['emailsync_progress'] = (array) ($reports['emailsync_summary']['progress'] ?? []);
		if (!$reports['emailsync_progress'] && !empty($reports['emailsync_event']['job_id'])) $reports['emailsync_progress'] = \emailsync\ProgressStore::summary(\emailsync\JobStore::get((string) $reports['emailsync_event']['job_id']));
		$reports['emailsync_progress_percent'] = (int) ($reports['emailsync_progress']['total'] ?? 0) > 0 ? min(100,(int) round(((int) ($reports['emailsync_progress']['processed'] ?? 0) / (int) $reports['emailsync_progress']['total']) * 100)) : (!empty($reports['emailsync_summary']['success']) ? 100 : 0);
		$reports['emailsync_list'][] = ['feature'=>'lead','data'=>[
			'titlekey'=>'',
			'title'=>htmlspecialchars((string) ($reports['emailsync_event']['name'] ?? ''),ENT_QUOTES,'UTF-8'),
			'desckey'=>'',
			'text'=>htmlspecialchars(language__get_parsed($reports['emailsync_language'],'_emailsync_event_'.$reports['emailsync_event']['type'],[
				'domain'=>$reports['emailsync_event']['domain'] ?? '',
				'date'=>format__date_relative((int) ($reports['emailsync_event']['created'] ?? 0),'date',$reports['emailsync_language'],true)
			]),ENT_QUOTES,'UTF-8'),
			'hasassess'=>'',
			'assess'=>[]
		]];
		$reports['emailsync_list'][] = ['feature'=>'score','data'=>[
			'titlekey'=>'_emailsync_run_summary',
			'title'=>'',
			'desckey'=>'',
			'value'=>$reports['emailsync_progress_percent'].' %',
			'color'=>'',
			'labelkey'=>'_emailsync_statistics_messages_processed',
			'delta'=>'',
			'metric'=>reports__metrics([
				['key'=>'messages_total','label'=>'_emailsync_statistics_messages_total','value'=>(int) ($reports['emailsync_progress']['total'] ?? 0)],
				['key'=>'messages_processed','label'=>'_emailsync_statistics_messages_processed','value'=>(int) ($reports['emailsync_progress']['processed'] ?? 0)],
				['key'=>'messages_pending','label'=>'_emailsync_statistics_messages_pending','value'=>(int) ($reports['emailsync_progress']['pending'] ?? 0)],
				['key'=>'messages_transferred','label'=>'_emailsync_messages_transferred','value'=>(int) ($reports['emailsync_stats']['messages_transferred'] ?? 0)]
			])
		]];
		$reports['emailsync_sent'][$reports['emailsync_event']['id']] = true;
	}
	if (!$reports['emailsync_list']) continue;
	$reports['items'][$reports['emailsync_email']] = ['list'=>$reports['emailsync_list']];
	$reports['has_values'] = 1;
}

if ($reports['emailsync_sent'] && empty($reports['selfsend'])) \emailsync\JobStore::markEventsNotified(array_keys($reports['emailsync_sent']));
