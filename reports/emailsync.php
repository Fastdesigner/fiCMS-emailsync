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
			'value'=>(string) ($reports['emailsync_stats']['messages_transferred'] ?? 0),
			'color'=>'',
			'labelkey'=>'_emailsync_messages_transferred',
			'delta'=>'',
			'metric'=>reports__metrics([
				['key'=>'messages_discovered','label'=>'_emailsync_messages_discovered','value'=>(int) ($reports['emailsync_stats']['messages_discovered'] ?? 0)],
				['key'=>'messages_skipped','label'=>'_emailsync_messages_skipped','value'=>(int) ($reports['emailsync_stats']['messages_skipped'] ?? 0)],
				['key'=>'duration','label'=>'_emailsync_duration_seconds','value'=>(int) ($reports['emailsync_summary']['duration'] ?? 0)],
				['key'=>'exit_code','label'=>'_emailsync_exit_code','value'=>(int) ($reports['emailsync_summary']['exit_code'] ?? -1)]
			])
		]];
		$reports['emailsync_sent'][$reports['emailsync_event']['id']] = true;
	}
	if (!$reports['emailsync_list']) continue;
	$reports['items'][$reports['emailsync_email']] = ['list'=>$reports['emailsync_list']];
	$reports['has_values'] = 1;
}

if ($reports['emailsync_sent'] && empty($reports['selfsend'])) \emailsync\JobStore::markEventsNotified(array_keys($reports['emailsync_sent']));
