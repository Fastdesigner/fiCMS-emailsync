<?php
namespace emailsync {
 final class State {
  public static array $files=[]; public static bool $fail=false;
  public static function read(string $name):array{return self::$files[$name]??[];}
  public static function update(string $name,callable $update):array|false{return self::$fail?false:self::$files[$name]=$update(self::read($name));}
 }
 final class DnsMonitor {
  public static $onSnapshot=null;
  public static function cutover(array $baseline,array $current):bool{return false;}
  public static function sourceDomain(string $name):string{return filter_var($name,FILTER_VALIDATE_EMAIL)?substr($name,strrpos($name,'@')+1):'';}
  public static function snapshot(string $name):array{if(self::$onSnapshot){$callback=self::$onSnapshot;self::$onSnapshot=null;$callback();}return ['fingerprint'=>$name];}
 }
 final class MailboxSync {
  public static array $tests=[]; public static $onTest=null;
  public static function test(array $connection):array{self::$tests[]=$connection['username'];if(self::$onTest){$callback=self::$onTest;self::$onTest=null;$callback();}return ['result'=>$connection['password']==='correct','error'=>'login_failed'];}
 }
}
namespace imap {
 final class ConnectionStore {
  public static array $connections=[]; public static bool $failSave=false; private static int $counter=0;
  public static function get(string $id):array{$c=self::$connections[$id]??[];if($c){$c['has_secret']=!empty($c['secret']);unset($c['secret']);}return $c;}
  public static function save(array $data,string $password=''):array{$id=$data['id']?:'conn-'.(++self::$counter);$c=array_merge(self::$connections[$id]??[], $data,['id'=>$id]);if($password!=='')$c['secret']=$password;if(self::$failSave)return array_diff_key($c,['secret'=>true]);self::$connections[$id]=$c;return self::get($id);}
  public static function delete(string $id):bool{unset(self::$connections[$id]);return true;}
  public static function credentials(string $id):array{$c=self::get($id);$c['password']=self::$connections[$id]['secret']??'';return $c;}
 }
}
namespace ficms {final class Runtime {public static function check(...$args):bool{return true;}}}
namespace {
 function mysqlEscape($s){return addslashes($s);}
 function mysqlFetchAssoc($sql){return str_contains($sql,'owner@example.invalid')?['id'=>1]:false;}
 $GLOBALS['tables']=['user'=>'users'];
 $_SERVER['now']=time();
 require dirname(__DIR__,2).'/src/JobStore.php';
 require dirname(__DIR__,2).'/src/JobConfiguration.php';
 require dirname(__DIR__,2).'/src/Settings.php';
 require dirname(__DIR__,2).'/src/Runner.php';
 function check($condition,$message){if(!$condition)throw new \RuntimeException($message);echo 'PASS '.$message."\n";}
 $data=['name'=>'Draft fixture','report_email'=>'owner@example.invalid','source_username'=>'source@example.invalid','source_host'=>'imap.example.invalid','destination_host'=>'target.example.invalid','destination_username'=>'target@example.invalid'];
 $created=\emailsync\JobConfiguration::save($data);
 check(!empty($created['result'])&&$created['draft'],'new job can be saved without passwords');
 $id=$created['id'];$draft=\emailsync\JobStore::get($id);
 check($draft['active']===0&&$draft['phase']==='draft'&&!$draft['baseline_mx'],'draft is inactive without cutover baseline');
 check(!\emailsync\MailboxSync::$tests,'saving a draft never authenticates');
 check(!\emailsync\JobStore::due(),'cron excludes drafts');
 check(\emailsync\Runner::run($id)['status']==='not_runnable','direct runner excludes drafts');
 $failed=\emailsync\JobConfiguration::setActive($id,true);
 check(!$failed['result']&&count($failed['connections'])===2&&!\emailsync\JobStore::get($id)['active'],'activation without secrets fails and remains inactive');
 $withSecrets=$data+['source_password'=>'wrong','destination_password'=>'correct'];
 \emailsync\JobConfiguration::save($withSecrets,$id);
 check(!\emailsync\JobConfiguration::setActive($id,true)['result'],'bad source password cannot activate');
 \emailsync\JobConfiguration::save(['source_password'=>'correct'],$id);
 check(\emailsync\JobConfiguration::setActive($id,true)['result'],'both valid connections allow activation');
 $active=\emailsync\JobStore::get($id);
 check($active['phase']==='initial'&&$active['active']===1,'activation transitions draft to initial');
 \emailsync\JobConfiguration::save(['name'=>'Renamed fixture'],$id);
 check(\emailsync\JobStore::get($id)['active']===1,'renaming does not pause an unchanged connection');
 \emailsync\JobConfiguration::save(['source_password'=>'correct'],$id);
 check(\emailsync\JobStore::get($id)['active']===1,'resubmitting an unchanged revealed password preserves activation');
 \emailsync\JobConfiguration::save(['source_password'=>'replacement'],$id);
 check(\emailsync\JobStore::get($id)['phase']==='draft'&&\emailsync\JobStore::get($id)['active']===0,'changing credentials requires explicit reactivation');
 \emailsync\JobStore::update($id,fn($j)=>array_merge($j,['run_count'=>1,'phase'=>'monitoring','active'=>1]));
 check(!\emailsync\JobConfiguration::save(['source_username'=>'other@example.invalid'],$id)['result'],'source identity cannot change after a run');
 \emailsync\JobConfiguration::save(['source_password'=>'correct'],$id);
 check(\emailsync\JobStore::get($id)['phase']==='monitoring'&&\emailsync\JobStore::get($id)['active']===0,'monitoring credentials can be replaced without losing progress phase');
 $another=\emailsync\JobConfiguration::save($data);
 \emailsync\JobStore::update($another['id'],fn($j)=>array_merge($j,['active'=>1]));
 check(!\emailsync\JobStore::due(),'even an accidentally active draft is excluded from cron');
 check(!\emailsync\JobConfiguration::save(['name'=>'bad','report_email'=>'unknown@example.invalid','source_username'=>'source@example.invalid'])['result'],'report recipient must be an active user');
 $before=\emailsync\JobStore::get($another['id']);
 \emailsync\JobConfiguration::save(['source_password'=>'correct'],$another['id']);
 $oldSource=\emailsync\JobStore::get($another['id'])['source_id'];
 \emailsync\JobConfiguration::save(['source_host'=>'new.example.invalid'],$another['id']);
 $newSource=\emailsync\JobStore::get($another['id'])['source_id'];
 check($newSource!==$oldSource&&!\imap\ConnectionStore::get($newSource)['has_secret'],'changing a draft host does not reuse the previous password');
 check(!\imap\ConnectionStore::get($oldSource),'unused old draft credentials are removed');
 $testsBefore=count(\emailsync\MailboxSync::$tests);
 $probe=\emailsync\JobConfiguration::testConnection(['source_host'=>'attacker.example.invalid','source_port'=>993,'source_security'=>'ssl','source_username'=>'source@example.invalid'],$id,'source');
 check(!$probe['result']&&count(\emailsync\MailboxSync::$tests)===$testsBefore,'direct connection probes never forward a saved password to a changed host');
 $revealed=\imap\ConnectionStore::credentials(\emailsync\JobStore::get($id)['source_id'])['password'];
 $probe=\emailsync\JobConfiguration::testConnection(['source_host'=>'attacker.example.invalid','source_port'=>993,'source_security'=>'ssl','source_username'=>'source@example.invalid','source_password'=>$revealed],$id,'source');
 check(!$probe['result']&&count(\emailsync\MailboxSync::$tests)===$testsBefore,'revealed original passwords cannot be auto-tested against an edited host');
 $guarded=\emailsync\JobConfiguration::save($data+['source_password'=>'correct']);
 \emailsync\JobConfiguration::save(['source_host'=>'edited.example.invalid','source_password'=>'correct'],$guarded['id']);
 check(!\imap\ConnectionStore::get(\emailsync\JobStore::get($guarded['id'])['source_id'])['has_secret'],'saving an edited identity never implicitly carries its revealed original password');
 $ready=\emailsync\JobConfiguration::save($data+['source_password'=>'correct','destination_password'=>'correct']);
 $raceId=$ready['id'];
 \emailsync\MailboxSync::$onTest=fn()=>\emailsync\JobConfiguration::save(['source_host'=>'changed.example.invalid','source_password'=>'wrong'],$raceId);
 $raced=\emailsync\JobConfiguration::setActive($raceId,true);
 check(!$raced['result']&&$raced['error']==='configuration_changed'&&!\emailsync\JobStore::get($raceId)['active'],'configuration changed during an IMAP probe is never activated');
 \imap\ConnectionStore::$failSave=true;
 $beforeFailure=\emailsync\JobStore::get($id);
 $oldPassword=\imap\ConnectionStore::credentials($beforeFailure['source_id'])['password'];
 $failedWrite=\emailsync\JobConfiguration::save(['source_password'=>'replacement'],$id);
 check(!$failedWrite['result']&&$failedWrite['error']==='connection_write_failed','silent connection-store write failure is detected');
 check(\emailsync\JobStore::get($id)===$beforeFailure&&\imap\ConnectionStore::credentials($beforeFailure['source_id'])['password']===$oldPassword,'failed connection write preserves the old job and credentials');
 \imap\ConnectionStore::$failSave=false;
 $stale=\emailsync\JobStore::get($id);
 \emailsync\JobConfiguration::save(['name'=>'Concurrent rename'],$id);
 check(!\emailsync\JobStore::save($stale,$stale),'a stale configuration save cannot overwrite a newer revision');
 $staleRuntime=\emailsync\JobStore::get($raceId);
 \emailsync\JobStore::update($raceId,fn($j)=>array_merge($j,['run_count'=>4,'phase'=>'monitoring','stats_total'=>['messages_transferred'=>12]]));
 check(!\emailsync\JobStore::save($staleRuntime,$staleRuntime)&&\emailsync\JobStore::get($raceId)['run_count']===4,'a concurrent cron update cannot be rolled back by a stale configuration save');
 $runnerDraft=\emailsync\JobConfiguration::save($data+['source_password'=>'correct','destination_password'=>'correct']);
 $runnerId=$runnerDraft['id'];
 \emailsync\JobConfiguration::setActive($runnerId,true);
 \emailsync\DnsMonitor::$onSnapshot=fn()=>\emailsync\JobConfiguration::save(['source_password'=>'replacement'],$runnerId);
 $runnerResult=\emailsync\Runner::run($runnerId);
 check($runnerResult['status']==='configuration_changed'&&!\emailsync\JobStore::get($runnerId)['active']&&\emailsync\JobStore::get($runnerId)['phase']==='draft','runner cannot overwrite a concurrent credential change or undo its pause');
 \emailsync\State::$fail=true;
 $activeBeforeFailure=\emailsync\JobStore::get($id);
 check(!\emailsync\JobConfiguration::save(['source_password'=>'untested'],$id)['result']&&\emailsync\JobStore::get($id)===$activeBeforeFailure&&\imap\ConnectionStore::credentials($activeBeforeFailure['source_id'])['password']===$oldPassword,'failed job persistence never replaces credentials used by an existing job');
 check(!\emailsync\JobConfiguration::save($data)['result'],'failed persistence is not reported as a saved draft');
 check(!\emailsync\JobConfiguration::setActive($id,false)['result'],'failed persistence is not reported as a successful pause');
 echo "All draft lifecycle checks passed. No network or persistent state used.\n";
}
