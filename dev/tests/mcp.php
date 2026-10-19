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
 check($read['id']===$id&&$read['edit']['destination_password']===''&&$read['job']['destination']['has_secret']&&!str_contains(json_encode($read),'correct'),'MCP edit exposes exact configuration and presence, never the stored secret');
 $updated=mcpCall('update',['id'=>$id,'data'=>['name'=>'MCP renamed','source_host'=>'imap.example.invalid']]);
 check($updated['job']['name']==='MCP renamed'&&$updated['job']['destination']['has_secret']&&$updated['job']['active']===0,'MCP partial configuration edit retains target credentials and stays inactive');
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
 echo "All MCP handler checks passed. No network or persistent state used.\n";
}
