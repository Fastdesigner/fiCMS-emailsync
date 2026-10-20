<?php
namespace emailsync {
 final class ProgressStore {
  public static function ratio(array $job): array{return [];}
  public static function summary(array $job): array{return [];}
  public static function get(string $id): array{return [];}
 }
 final class Statistics {public static function get(string $id): array{return [];}}
}
namespace {
 require __DIR__.'/drafts.php';
 require dirname(__DIR__,2).'/src/McpView.php';
 function mcpCall(string $tool,array $args,string $mode='call'):array {
  $site=['onsite'=>true];
  $context=new class($args,$mode) {
   public function __construct(public array $args,public string $mode){}
   public function edits():bool{return ($this->args['data']['view']??'')==='edit';}
  };
  return include dirname(__DIR__,2).'/mcp/'.$tool.'/emailsync.php';
 }
 \emailsync\State::$fail=false;
 $data=['name'=>'MCP draft','report_email'=>'owner@example.invalid','source_username'=>'mcp@example.invalid','destination_username'=>'target@example.invalid','destination_host'=>'target.example.invalid','destination_password'=>'correct'];
 $tests=count(\emailsync\MailboxSync::$tests);
 $created=mcpCall('create',['data'=>$data]);$id=$created['id'];
 check($created['created']&&$created['active']===0&&$created['phase']==='draft'&&count(\emailsync\MailboxSync::$tests)===$tests,'MCP create saves a real inactive configuration without IMAP access');
 $read=mcpCall('get',['id'=>$id,'data'=>['view'=>'edit']]);
 check($read['id']===$id&&!array_key_exists('destination_password',$read['edit'])&&$read['job']['destination']['secret_present']&&in_array('destination_password',$read['write_only'],true)&&!str_contains(json_encode($read),'correct'),'MCP edit exposes exact configuration and presence, never the stored secret or a redacted write-back placeholder');
 $updated=mcpCall('update',['id'=>$id,'data'=>['name'=>'MCP renamed','source_host'=>'imap.example.invalid']]);
 check($updated['job']['name']==='MCP renamed'&&$updated['job']['destination']['secret_present']&&$updated['job']['active']===0,'MCP partial configuration edit retains target credentials and stays inactive');
 $before=\emailsync\JobStore::get($id);
 $mixed=mcpCall('update',['id'=>$id,'data'=>['name'=>'Must not persist','active'=>1]]);
 check(!empty($mixed['error'])&&\emailsync\JobStore::get($id)===$before,'MCP refuses mixed configuration and activation before any write');
 check(!empty(mcpCall('create',['data'=>$data+['active'=>1]])['error']),'MCP create cannot bypass separate activation');
 check(!empty(mcpCall('update',['id'=>$id,'data'=>['active'=>'false']])['error']),'MCP rejects ambiguous runtime booleans');
 check(!empty(mcpCall('update',['id'=>$id,'data'=>['active'=>null]])['error']),'MCP rejects null activation instead of silently pausing');
 \emailsync\JobConfiguration::save(['source_password'=>'correct'],$id);
 $readyBefore=\emailsync\JobStore::get($id);
 check(!empty(mcpCall('update',['id'=>$id,'data'=>['run_next_cron'=>'false']])['error'])&&\emailsync\JobStore::get($id)===$readyBefore,'MCP string false never starts a fully configured job');
 \emailsync\JobConfiguration::save(['source_host'=>'other.example.invalid'],$id);
 $before=\emailsync\JobStore::get($id);
 check(mcpCall('update',['id'=>$id,'data'=>['test_connection'=>'destination']])['result']&&\emailsync\JobStore::get($id)===$before,'MCP tests just the selected stored connection without starting a job');
 $failed=mcpCall('update',['id'=>$id,'data'=>['active'=>1]]);
 check(!$failed['result']&&isset($failed['connections']['source'])&&!\emailsync\JobStore::get($id)['active'],'MCP activation remains blocked while a source password is missing');
 foreach(['get','create','update','delete'] as $tool) check(mcpCall($tool,[],'describe')['scope']===['admin'],'MCP '.$tool.' declares admin-only access');
 // Protected writes use only synthetic secrets; rejected calls preserve both owners exactly.
 function binding(string $id,string $password):array {
  $job=mcpCall('get',['id'=>$id,'data'=>['view'=>'edit']])['job'];
  $identity=[];
  foreach(['source','destination'] as $side) foreach(['id','host','port','security','username'] as $field) $identity[$side][$field]=$job[$side][$field];
  return ['source_password'=>$password,'expected_configuration_revision'=>$job['configuration_revision'],'expected_identity'=>$identity];
 }
 $protected=mcpCall('create',['data'=>$data]);$protectedId=$protected['id'];
 $payload=binding($protectedId,'synthetic-source-canary');
 $beforeJob=\emailsync\JobStore::get($protectedId);$beforeConnections=\imap\ConnectionStore::$connections;
 $bad=$payload;$bad['expected_configuration_revision']='stale';
 check(mcpCall('update',['id'=>$protectedId,'data'=>$bad])===['result'=>false,'error'=>'target_changed']&&\emailsync\JobStore::get($protectedId)===$beforeJob&&\imap\ConnectionStore::$connections===$beforeConnections,'stale protected revision leaves job and every connection unchanged');
 foreach(['source','destination'] as $side) foreach(['id','host','port','security','username'] as $field) {
  $bad=$payload;$bad['expected_identity'][$side][$field]=$field==='port'?143:'different';
  check(mcpCall('update',['id'=>$protectedId,'data'=>$bad])===['result'=>false,'error'=>'target_changed']&&\emailsync\JobStore::get($protectedId)===$beforeJob&&\imap\ConnectionStore::$connections===$beforeConnections,'protected binding checks exact '.$side.' '.$field.' without persisting a secret');
 }
 $invalid=[];
 $bad=$payload;unset($bad['expected_identity']);$invalid[]=$bad;
 $bad=$payload;unset($bad['expected_configuration_revision']);$invalid[]=$bad;
 foreach(['active'=>1,'run_next_cron'=>true,'cutover'=>'confirm','test_connection'=>'source','name'=>'changed','destination_password'=>'other'] as $field=>$value) $invalid[]=$payload+[$field=>$value];
 $bad=$payload;$bad['source_password']='';$invalid[]=$bad;
 $bad=$payload;$bad['expected_identity']['source']['port']='993';$invalid[]=$bad;
 $bad=$payload;$bad['expected_identity']['destination']['extra']='x';$invalid[]=$bad;
 foreach($invalid as $bad) check(mcpCall('update',['id'=>$protectedId,'data'=>$bad])===['result'=>false,'error'=>'invalid_protected_credential_write']&&\emailsync\JobStore::get($protectedId)===$beforeJob&&\imap\ConnectionStore::$connections===$beforeConnections,'invalid protected payload is rejected before any owner changes');
 $testsBefore=count(\emailsync\MailboxSync::$tests);
 $written=mcpCall('update',['id'=>$protectedId,'data'=>$payload]);
 $after=\emailsync\JobStore::get($protectedId);
 check($written===['result'=>true,'type'=>'emailsync','id'=>$protectedId,'status'=>'submitted'],'protected success has exactly the minimal safe receipt');
 check($after['phase']==='draft'&&$after['active']===0&&$after['run_count']===0&&count(\emailsync\MailboxSync::$tests)===$testsBefore,'protected write leaves draft inactive without connection probes or transfers');
 check($after['destination_id']===$beforeJob['destination_id']&&\imap\ConnectionStore::$connections[$after['destination_id']]===$beforeConnections[$beforeJob['destination_id']],'writing source password preserves the entire prefilled destination connection');
 check(\imap\ConnectionStore::credentials($after['source_id'])['password']==='synthetic-source-canary'&&!str_contains(json_encode(mcpCall('get',['id'=>$protectedId,'data'=>['view'=>'edit']])),'synthetic-source-canary'),'native secret-present read never returns the canary');
 $payload=binding($protectedId,'another-synthetic-canary');
 $beforeJob=\emailsync\JobStore::get($protectedId);$beforeConnections=\imap\ConnectionStore::$connections;
 \imap\ConnectionStore::$failSave=true;
 check(mcpCall('update',['id'=>$protectedId,'data'=>$payload])===['result'=>false,'error'=>'credential_write_failed']&&\emailsync\JobStore::get($protectedId)===$beforeJob&&\imap\ConnectionStore::$connections===$beforeConnections,'protected persistence failure has a fixed code and preserves both owners');
 \imap\ConnectionStore::$failSave=false;
 $raceJob=[];$raceConnections=[];$raceSaves=0;
 \emailsync\State::$beforeUpdate=function()use($protectedId,&$raceJob,&$raceConnections,&$raceSaves){
  \emailsync\JobConfiguration::save(['destination_host'=>'race-target.example.invalid'],$protectedId);
  $raceJob=\emailsync\JobStore::get($protectedId);$raceConnections=\imap\ConnectionStore::$connections;$raceSaves=\imap\ConnectionStore::$saves;
 };
 check(mcpCall('update',['id'=>$protectedId,'data'=>$payload])===['result'=>false,'error'=>'target_changed']&&\imap\ConnectionStore::$saves===$raceSaves&&\imap\ConnectionStore::$connections===$raceConnections&&\emailsync\JobStore::get($protectedId)===$raceJob,'CAS rejects a changed target before invoking any ConnectionStore save, even temporarily');
 $payload=binding($protectedId,'synthetic-exception-canary');
 $beforeJob=\emailsync\JobStore::get($protectedId);$beforeConnections=\imap\ConnectionStore::$connections;
 \imap\ConnectionStore::$throwSave='remote error containing synthetic-exception-canary';
 ob_start();$failed=mcpCall('update',['id'=>$protectedId,'data'=>$payload]);$output=ob_get_clean();
 check($failed===['result'=>false,'error'=>'credential_write_failed']&&$output===''&&\emailsync\JobStore::get($protectedId)===$beforeJob&&\imap\ConnectionStore::$connections===$beforeConnections,'foreign exception text never enters output or receipt and both owners remain unchanged');
 \imap\ConnectionStore::$throwSave='';
 foreach([1,-1,2] as $active) {
  \emailsync\JobStore::update($protectedId,fn($job)=>array_merge($job,['active'=>$active,'phase'=>'initial']));
  $payload=binding($protectedId,'synthetic-active-canary');
  $beforeSaves=\imap\ConnectionStore::$saves;
  $beforeJob=\emailsync\JobStore::get($protectedId);$beforeConnections=\imap\ConnectionStore::$connections;
  check(mcpCall('update',['id'=>$protectedId,'data'=>$payload])===['result'=>false,'error'=>'credential_write_failed']&&\emailsync\JobStore::get($protectedId)===$beforeJob&&\imap\ConnectionStore::$connections===$beforeConnections&&\imap\ConnectionStore::$saves===$beforeSaves,'active job is rejected without even a temporary ConnectionStore save');
 }
 foreach(['draft','initial','monitoring','completed'] as $phase) {
  \emailsync\JobStore::update($protectedId,fn($job)=>array_merge($job,['active'=>0,'phase'=>$phase,'baseline_mx'=>[],'current_mx'=>[],'next_run'=>123456,'cutover_detected'=>789,'run_count'=>in_array($phase,['monitoring','completed'],true)?3:0,'completed'=>$phase==='completed'?987654:0]));
  $beforeJob=\emailsync\JobStore::get($protectedId);
  $destination=\imap\ConnectionStore::$connections[$beforeJob['destination_id']];
  $payload=binding($protectedId,'synthetic-inactive-'.$phase);
  check(mcpCall('update',['id'=>$protectedId,'data'=>$payload])['status']==='submitted','protected write supports inactive '.$phase.' jobs');
  $afterJob=\emailsync\JobStore::get($protectedId);
  check(\imap\ConnectionStore::credentials($afterJob['source_id'])['password']==='synthetic-inactive-'.$phase&&\imap\ConnectionStore::$connections[$afterJob['destination_id']]===$destination,'protected source write updates only the intended password in inactive '.$phase.' jobs');
  foreach(['source_id','configuration_revision','updated'] as $field) {unset($beforeJob[$field],$afterJob[$field]);}
  check($afterJob===$beforeJob,'protected write preserves every other configuration and runtime field of inactive '.$phase.' jobs');
 }
 $beforeCompleted=\emailsync\JobStore::get($protectedId);$beforeConnections=\imap\ConnectionStore::$connections;$beforeSaves=\imap\ConnectionStore::$saves;
 check(mcpCall('update',['id'=>$protectedId,'data'=>['name'=>'Must stay completed']])===['result'=>false,'error'=>'job_completed']&&\emailsync\JobStore::get($protectedId)===$beforeCompleted&&\imap\ConnectionStore::$connections===$beforeConnections&&\imap\ConnectionStore::$saves===$beforeSaves,'ordinary completed-job configuration remains blocked without writes');
 $beforeJob=\emailsync\JobStore::get($protectedId);$source=\imap\ConnectionStore::$connections[$beforeJob['source_id']];
 $payload=binding($protectedId,'unused');unset($payload['source_password']);$payload['destination_password']='synthetic-destination-replacement';
 check(mcpCall('update',['id'=>$protectedId,'data'=>$payload])===['result'=>true,'type'=>'emailsync','id'=>$protectedId,'status'=>'submitted']&&\imap\ConnectionStore::$connections[$beforeJob['source_id']]===$source,'protected destination write likewise retains the complete source connection');
 echo "All MCP handler checks passed. No network or persistent state used.\n";
}
