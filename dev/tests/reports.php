<?php
require __DIR__.'/scheduling.php';
require (getenv('FICMS_CORE') ?: dirname(__DIR__,3).'/fiCMS').'/include/classes/reports/Reports.php';
function language__get_parsed($language,$key,$values){return $key;}
function format__date_relative(...$args){return 'fixture date';}
resetFixture(['x'=>job('x',['report_user_id'=>1,'report_email'=>'owner@example.invalid'])]);
foreach(['initial_complete','completed'] as $type) \emailsync\JobStore::event('x',$type,['success'=>true]);
$site=['onsite'=>true,'default_language'=>'de'];
$reports=['mode'=>'meta'];include dirname(__DIR__,2).'/reports/emailsync.php';$meta=$reports['meta'];
$reports['mode']='due';include dirname(__DIR__,2).'/reports/emailsync.php';check($reports['due'],'initial and completed events make report due');
$reports['recipients']=['owner@example.invalid'=>['user'=>['id'=>1,'language'=>'de']],'customer@example.invalid'=>['user'=>['id'=>1,'language'=>'de']]];
$reports['mode']='recipients';include dirname(__DIR__,2).'/reports/emailsync.php';
check(array_keys($reports['recipients'])===['owner@example.invalid'],'explicit report email prevents delivery to another address with the same user id');
$reports['mode']='send';include dirname(__DIR__,2).'/reports/emailsync.php';
$item=$reports['items']['owner@example.invalid'];
check(count($item['emailsync_event_ids'])===2&&count(\emailsync\JobStore::pendingEvents())===2,'both event types are rendered without premature acknowledgement');
foreach(['failed','uncertain'] as $state){$meta['complete']($item,[], $state);check(count(\emailsync\JobStore::pendingEvents())===2,"$state preserves events for review or retry");}
$meta['complete']($item,[],'sent');check(!\emailsync\JobStore::pendingEvents(),'successful transport acknowledges exactly the included events');
\emailsync\JobStore::event('x','completed',['success'=>true]);$pending=\emailsync\JobStore::pendingEvents();$meta['complete'](['emailsync_event_ids'=>array_keys($pending)],[],'queued');check(!\emailsync\JobStore::pendingEvents(),'durable mail queue acceptance acknowledges the included event');
echo "Report recipient and delivery callback checks passed.\n";
