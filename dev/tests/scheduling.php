<?php
namespace emailsync {
 function dns_get_record($domain,$type) { return $GLOBALS['fixtureMx']; }
 final class State {
  public static array $files=[];
  public static function read(string $name):array{return self::$files[$name]??[];}
  public static function update(string $name,callable $callback):array|false{return self::$files[$name]=$callback(self::read($name));}
  public static function write(string $name,$value,bool $secure=false):bool{self::$files[$name]=$value;return true;}
 }
 final class Statistics {public static function add(...$args):bool{return true;}}
 final class MailboxSync {
  public static array $result=[]; public static int $calls=0; public static $onRun=null;
  public static function run(array $job,array $source,array $destination):array {self::$calls++;if(self::$onRun)(self::$onRun)();return self::$result+['started'=>time(),'finished'=>time(),'duration'=>1,'stats'=>['messages_discovered'=>0]];}
 }
}
namespace imap {
 final class ConnectionStore {
  public static int $reads=0; public static int $purges=0;
  public static function get(string $id):array{return ['id'=>$id,'host'=>'mail.target.invalid'];}
  public static function credentials(string $id):array{self::$reads++;return self::get($id)+['password'=>'synthetic'];}
  public static function purgeSecret(string $id):void{self::$purges++;}
 }
}
namespace ficms {
 final class Runtime {
  public static bool $grant=true;public static array $requests=[];
  public static function grant(float $seconds):bool{return self::$grant;}
  public static function request(string $name,float $seconds):void{self::$requests[$name]=$seconds;}
 }
}
namespace {
 require dirname(__DIR__,2).'/src/JobStore.php';
 require dirname(__DIR__,2).'/src/DnsMonitor.php';
 require dirname(__DIR__,2).'/src/Settings.php';
 require dirname(__DIR__,2).'/src/ProgressStore.php';
 require dirname(__DIR__,2).'/src/Runner.php';
 $_SERVER['now']=time();$now=time();$checks=0;
 function check($condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;echo "PASS $message\n";}
 function mx(string $host):array{return ['targets'=>[$host],'records'=>[['target'=>$host,'priority'=>10,'ttl'=>3600]],'fingerprint'=>hash('sha256',$host),'ttl'=>3600];}
 $old=mx('old.source.invalid');$target=mx('mail.target.invalid');
 $GLOBALS['fixtureMx']=[['target'=>'mail.target.invalid','pri'=>10,'ttl'=>3600]];
 $target=\emailsync\DnsMonitor::snapshot('fixture.invalid');
 function job(string $id,array $extra=[]):array{global $old,$target,$now;return array_merge(['id'=>$id,'domain'=>'fixture.invalid','source_id'=>'src','destination_id'=>'dst','configuration_revision'=>'rev','phase'=>'monitoring','active'=>1,'next_run'=>$now-10,'last_run'=>$now-3600,'created'=>$now-1000000,'initial_completed'=>$now-900000,'cutover_detected'=>$now-800000,'baseline_mx'=>$old,'current_mx'=>$target,'cutover_fingerprint'=>$target['fingerprint'],'last_source_change'=>$now-800000,'stats_total'=>['messages_synchronized'=>100,'messages_transferred'=>100],'last_summary'=>['success'=>1,'partial'=>0,'finished'=>$now-100,'stats'=>['messages_discovered'=>0,'messages_source'=>100]]],$extra);}
 function resetFixture(array $jobs):void{\emailsync\State::$files=['jobs.json'=>$jobs];\emailsync\MailboxSync::$calls=0;\emailsync\MailboxSync::$onRun=null;\emailsync\MailboxSync::$result=['success'=>true,'partial'=>0,'stats'=>['messages_discovered'=>0,'messages_source'=>100]];\imap\ConnectionStore::$reads=0;\ficms\Runtime::$grant=true;}
 resetFixture(['monitor'=>job('monitor'),'initial'=>job('initial',['phase'=>'initial','initial_completed'=>0])]);
 check(\emailsync\JobStore::due()['id']==='initial','initial copy wins over due monitoring');
 \emailsync\JobStore::update('monitor',fn($j)=>array_merge($j,['last_run'=>$now-21601]));
 check(\emailsync\JobStore::due()['id']==='monitor','monitoring receives a fair turn after six hours');
 resetFixture(['a'=>job('a',['phase'=>'initial','last_run'=>$now-100]),'b'=>job('b',['phase'=>'initial','last_run'=>$now-200])]);
 check(\emailsync\JobStore::due()['id']==='b','multiple initial copies rotate by last run');
 foreach(['draft','completed'] as $phase){resetFixture(['x'=>job('x',['phase'=>$phase])]);check(!\emailsync\JobStore::due(),"$phase is excluded");}
 resetFixture(['x'=>job('x',['active'=>0])]);check(!\emailsync\JobStore::due(),'inactive jobs are excluded');
 resetFixture(['x'=>job('x',['next_run'=>$now+500])]);check(!\emailsync\JobStore::due(),'future retry is respected');
 resetFixture(['x'=>job('x',['phase'=>'initial','initial_completed'=>0])]);\ficms\Runtime::$grant=false;
 check(\emailsync\Runner::runDue()['status']==='deferred'&&\emailsync\MailboxSync::$calls===0&&\ficms\Runtime::$requests['emailsync']===8.0,'budget refusal requests backlog without credentials or IMAP');
 \ficms\Runtime::$grant=true;\emailsync\MailboxSync::$result=['success'=>true,'partial'=>1,'stats'=>['messages_discovered'=>10,'messages_transferred'=>10,'messages_synchronized'=>10]];
 \emailsync\Runner::runDue();$stored=\emailsync\JobStore::get('x');
 check($stored['next_run']<=time()&&$stored['last_result']==='partial','partial copy is due on the next normal cron');
 check(\ficms\Runtime::$requests['emailsync']===8.0,'partial copy keeps the core backlog active');
 resetFixture(['x'=>job('x')]);\emailsync\Runner::run('x');$stored=\emailsync\JobStore::get('x');
 check($stored['phase']==='completed'&&$stored['active']===0,'verified legacy final sweep finishes automatically');
 check(\emailsync\MailboxSync::$calls===0&&\imap\ConnectionStore::$reads===0,'proven complete job does not resolve secrets or retry the old login');
 check(\imap\ConnectionStore::$purges===0,'completion retains user-visible saved connections');
 check(count(\emailsync\State::read('events.json'))===1,'completion creates exactly one existing event');
 check(\emailsync\Runner::run('x')['status']==='not_runnable','completed job cannot run or emit a second event');
 // P1 regression: an empty sweep before the full deadline never proves final completion.
 $cutover=$now-604900;$deadline=$cutover+604800;
 resetFixture(['x'=>job('x',['cutover_detected'=>$cutover,'last_source_change'=>$cutover,'last_summary'=>['success'=>1,'partial'=>0,'finished'=>$deadline-1,'stats'=>['messages_discovered'=>0]]])]);
 \emailsync\MailboxSync::$result=['success'=>false,'partial'=>0,'error_side'=>'source','error'=>'imap_command_failed_login'];
 $result=\emailsync\Runner::run('x');$stored=\emailsync\JobStore::get('x');
 check(\emailsync\MailboxSync::$calls===1&&$stored['phase']==='monitoring'&&$stored['active']===0&&$stored['last_result']==='source_unavailable','sweep before deadline plus closed source pauses instead of false completion');
 check($stored['last_error']===''&&$stored['review_reason']==='source_login_unavailable_after_cutover','expected source retirement is review state, not repeated generic failure');
 check(!\emailsync\State::read('events.json'),'source retirement does not produce another failed notification');
 check(\emailsync\Runner::run('x')['status']==='not_runnable','retired source is not retried automatically');
 foreach(['destination',''] as $side){resetFixture(['x'=>job('x',['last_summary'=>[]])]);\emailsync\MailboxSync::$result=['success'=>false,'error_side'=>$side,'error'=>'imap_command_failed_login'];\emailsync\Runner::run('x');check(\emailsync\JobStore::get('x')['last_result']==='failed'&&\emailsync\JobStore::get('x')['active']===1,'destination or unattributed failure remains a real retryable error');}
 resetFixture(['x'=>job('x',['initial_completed'=>0,'phase'=>'initial','last_summary'=>[]])]);\emailsync\MailboxSync::$result=['success'=>false,'error_side'=>'source','error'=>'imap_command_failed_login'];\emailsync\Runner::run('x');check(\emailsync\JobStore::get('x')['last_result']==='failed','incomplete initial copy cannot be retired');
 resetFixture(['x'=>job('x',['last_summary'=>[]])]);\emailsync\MailboxSync::$result=['success'=>false,'error_side'=>'source','error'=>'imap_timeout'];\emailsync\Runner::run('x');check(\emailsync\JobStore::get('x')['last_result']==='failed','transient timeout is not treated as deleted source');
 resetFixture(['x'=>job('x',['cutover_detected'=>$now-100,'last_source_change'=>$now-100,'last_summary'=>[]])]);\emailsync\Runner::run('x');$stored=\emailsync\JobStore::get('x');
 check($stored['phase']==='monitoring'&&$stored['next_run']>=time()+3598,'monitoring continues hourly before its deadline');
 resetFixture(['x'=>job('x',['last_summary'=>[]])]);\emailsync\MailboxSync::$onRun=fn()=>\emailsync\JobStore::update('x',fn($j)=>array_merge($j,['active'=>0,'configuration_revision'=>'new']));\emailsync\Runner::run('x');check(\emailsync\JobStore::get('x')['configuration_revision']==='new'&&\emailsync\JobStore::get('x')['active']===0,'concurrent human pause or configuration change wins');
 $j=job('observe');$changed=\emailsync\DnsMonitor::observe($j,mx('wrong.invalid'),'mail.target.invalid');check(!$changed['cutover_detected'],'unrelated MX change resets cutover evidence');
 $changed=\emailsync\DnsMonitor::observe($j,$old,'mail.target.invalid');check(!$changed['cutover_detected'],'return to source MX resets cutover evidence');
 $changed=\emailsync\DnsMonitor::observe($j,['fingerprint'=>'','targets'=>[]],'mail.target.invalid');check($changed['cutover_detected']===$j['cutover_detected']&&$changed['current_mx']['fingerprint']==='','DNS outage preserves history but blocks completion');
 $mixed=$target;$mixed['targets'][]='old.source.invalid';check(!\emailsync\DnsMonitor::matchesDestination($mixed,'mail.target.invalid'),'mixed source and destination MX is not a completed cutover');
 check(\emailsync\DnsMonitor::matchesDestination($target,'MAIL.TARGET.INVALID.'),'destination comparison normalizes host case and trailing dot');
 $manual=\emailsync\DnsMonitor::confirm($j,false);check($manual['cutover_manual']&&$manual['cutover_fingerprint']===$target['fingerprint'],'manual confirmation binds the actual current MX fingerprint');
 $manual=\emailsync\DnsMonitor::observe($manual,$target,'different-imap.invalid');check($manual['cutover_manual'],'manual confirmation supports distinct IMAP and MX hosts');
 $manual=\emailsync\DnsMonitor::observe($manual,$old,'different-imap.invalid');check(!$manual['cutover_detected']&&!$manual['cutover_manual'],'changed DNS invalidates manual confirmation too');
 $reset=\emailsync\DnsMonitor::confirm($j,true);check(!$reset['cutover_detected']&&!$reset['cutover_manual']&&$reset['baseline_mx']===$target,'reset is shared by UI and MCP');
 echo "$checks scheduling and lifecycle checks passed; no network or persistent state.\n";
}
