<?php
// Fault injection is confined to the plugin's State adapter. All Core owners are unmodified.
namespace emailsync {
 final class State {
  public static $afterPrepare=null;
  public static $observeHandler=null;
  public static function path(string $name):string{return 'system/plugins/fiCMS-emailsync/state/'.$name;}
  public static function read(string $name):array{return \ficms\Files::readJson(self::path($name));}
  public static function update(string $name,callable $update):array|false{
   if(self::$observeHandler){$observe=self::$observeHandler;self::$observeHandler=null;$observe();}
   return \ficms\Files::updateJson(self::path($name),function(array $stored)use($update):array{
    $prepared=$update($stored);
    if(self::$afterPrepare){$fault=self::$afterPrepare;self::$afterPrepare=null;$fault($prepared);}
    return $prepared;
   },true);
  }
 }
 final class DnsMonitor {public static function sourceDomain(string $name):string{return substr($name,strrpos($name,'@')+1);}}
}
namespace {
 $plugin=dirname(__DIR__,2);$core=getenv('FICMS_CORE')?:dirname($plugin).'/fiCMS';
 foreach(['include/classes/fiCMS/src/Files.php','include/classes/imap/src/State.php','include/classes/imap/src/Vault.php','include/classes/imap/src/ConnectionStore.php'] as $file)require $core.'/'.$file;
 foreach(['JobStore','JobConfiguration'] as $class)require $plugin.'/src/'.$class.'.php';
 function check(bool $condition,string $message):void{if(!$condition)throw new \RuntimeException($message);echo 'PASS '.$message."\n";}
 function mysqlEscape($value){return addslashes($value);}
 function mysqlFetchAssoc($sql){return ['id'=>1];}
 function binding(string $id,string $password):array{
  $job=\emailsync\JobStore::get($id);$identities=[];
  foreach(['source','destination'] as $side){$connection=\imap\ConnectionStore::get($job[$side.'_id']);foreach(['id','host','port','security','username'] as $field)$identities[$side][$field]=$connection[$field];}
  return ['source_password'=>$password,'expected_configuration_revision'=>$job['configuration_revision'],'expected_identity'=>$identities];
 }
 function traceProbe(string $password,callable $handler):array{
  try{$handler(E_WARNING,'synthetic warning',__FILE__,__LINE__);}catch(\Throwable $error){return ['message'=>$error->getMessage(),'previous'=>$error->getPrevious(),'trace'=>$error->getTrace()];}
  throw new \RuntimeException('warning_did_not_throw');
 }
 $GLOBALS['tables']=['user'=>'users'];$_SERVER['now']=time();
 $directory=sys_get_temp_dir().'/emailsync-failures-'.bin2hex(random_bytes(8));mkdir($directory,0700);$previous=getcwd();chdir($directory);
 register_shutdown_function(function()use($directory,$previous):void{
  chdir($previous);$files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
  foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($directory);
 });
 ini_set('display_errors','1');ini_set('log_errors','1');ini_set('error_log',$directory.'/php-errors.log');ini_set('zend.exception_ignore_args','0');error_reporting(E_ALL);
 $data=['name'=>'Synthetic failure fixture','report_email'=>'owner@example.invalid','source_username'=>'source@example.invalid','source_host'=>'source.example.invalid','destination_username'=>'target@example.invalid','destination_host'=>'target.example.invalid','destination_password'=>'synthetic-target'];
 $id=\emailsync\JobConfiguration::save($data)['id'];
 $foreignId=\emailsync\JobConfiguration::save(array_merge($data,['name'=>'Synthetic foreign job']))['id'];
 \emailsync\JobStore::update($foreignId,fn($job)=>array_merge($job,['active'=>1,'phase'=>'monitoring']));
 $canary='synthetic-warning-trace-canary';$payload=binding($id,$canary);
 $job=\emailsync\JobStore::get($id);$connections=\imap\ConnectionStore::list(true);$foreign=\emailsync\JobStore::get($foreignId);
 $events=[];$logger=static function(int $severity,string $message)use(&$events):bool{
  $event=['severity'=>$severity,'message'=>$message,'trace'=>debug_backtrace()];$events[]=$event;error_log(json_encode($event));return true;
 };
 set_error_handler($logger);
 // Capture the actual scoped handler and prove its exception omits even caller arguments.
 $local=null;\emailsync\State::$observeHandler=function()use(&$local):void{
  $local=set_error_handler(static fn()=>true);restore_error_handler();
 };
 $path=\imap\State::path('connections.json');chmod($path,0400);clearstatcache(true,$path);
 check(!is_writable($path),'readonly connection-store fault is effective under the test user');
 ob_start();$failed=\emailsync\JobConfiguration::saveProtectedCredential($payload,$id);$output=ob_get_clean();chmod($path,0600);
 check($failed===['result'=>false,'error'=>'credential_write_failed']&&$output===''&&$events===[],'Core fopen warning is converted locally without output or global trace logging');
 $trace=traceProbe($canary,$local);
 check($trace['message']==='credential_write_failed'&&$trace['previous']===null&&!str_contains(serialize($trace),$canary)&&!array_filter($trace['trace'],fn($frame)=>array_key_exists('args',$frame)),'warning exception has a fixed message, no previous exception and no trace arguments');
 check(ini_get('zend.exception_ignore_args')==='0','scoped warning conversion restores the previous exception-argument setting');
 check(\emailsync\JobStore::get($id)===$job&&\imap\ConnectionStore::list(true)===$connections,'readonly-store failure leaves the job and all connection secrets unchanged');
 $restored=set_error_handler($logger);restore_error_handler();check($restored===$logger,'previous PHP error handler is restored after warning failure');
 trigger_error('outside_scope_probe',E_USER_WARNING);check(count($events)===1&&$events[0]['message']==='outside_scope_probe','warnings outside the protected scope still reach the previous global handler');$events=[];
 $canary='synthetic-after-prepare-canary';$payload=binding($id,$canary);$preparedSecretSeen=false;
 \emailsync\State::$afterPrepare=function(array $prepared)use($id,$canary,&$preparedSecretSeen):void{
  $connectionId=$prepared[$id]['source_id'];$preparedSecretSeen=\imap\ConnectionStore::credentials($connectionId)['password']===$canary;
  throw new \RuntimeException('synthetic_after_prepare_failure');
 };
 ob_start();$failed=\emailsync\JobConfiguration::saveProtectedCredential($payload,$id);$output=ob_get_clean();
 check($preparedSecretSeen,'fault occurs after the real Core owner persisted the new encrypted connection');
 check($failed===['result'=>false,'error'=>'credential_write_failed']&&$output===''&&$events===[],'post-prepare exception returns only the fixed failure receipt');
 check(\emailsync\JobStore::get($id)===$job&&\imap\ConnectionStore::list(true)===$connections,'finally cleanup removes the post-prepare orphan and preserves every original encrypted connection');
 check(\emailsync\JobStore::get($foreignId)===$foreign,'cleanup leaves the active foreign fixture job untouched');
 // An ID that acquired a live reference must never be deleted by failure cleanup.
 $referenced=[];\emailsync\State::$afterPrepare=function(array $prepared)use($id,$foreignId,&$referenced):void{
  $jobs=\emailsync\State::read('jobs.json');$jobs[$foreignId]['source_id']=$prepared[$id]['source_id'];
  // Fault fixture publishes a foreign reference while already inside the owner's job lock.
  \ficms\Files::writeJson(\emailsync\State::path('jobs.json'),$jobs,true);
  $referenced=\imap\ConnectionStore::get($jobs[$foreignId]['source_id'],true);
  throw new \RuntimeException('synthetic_referenced_connection_failure');
 };
 $failed=\emailsync\JobConfiguration::saveProtectedCredential($payload,$id);
 check($failed===['result'=>false,'error'=>'credential_write_failed']&&$referenced&&\imap\ConnectionStore::get($referenced['id'],true)===$referenced&&\emailsync\JobStore::get($id)===$job,'failure cleanup retains a newly created connection already referenced by another active job');
 // A subsequent successful call proves handler restoration also on the success path.
 $success=\emailsync\JobConfiguration::saveProtectedCredential($payload,$id);
 $restored=set_error_handler($logger);restore_error_handler();
 check($success===['result'=>true,'type'=>'emailsync','id'=>$id,'status'=>'submitted']&&$restored===$logger,'successful protected write likewise restores the previous error handler');
 restore_error_handler();
 $log=is_file($directory.'/php-errors.log')?file_get_contents($directory.'/php-errors.log'):'';
 check(!str_contains($log,'synthetic-warning-trace-canary')&&!str_contains($log,'synthetic-after-prepare-canary')&&!str_contains(json_encode($failed),'canary'),'PHP logs and receipts contain neither synthetic secret');
 echo "All protected failure checks passed with unchanged Core owners and disposable synthetic state.\n";
}
