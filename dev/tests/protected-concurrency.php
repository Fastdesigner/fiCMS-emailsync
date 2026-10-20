<?php
// Integration check against the existing fiCMS Files/ConnectionStore/Vault owners.
// All persistent state is synthetic and lives in a disposable temporary directory.
namespace emailsync {
 final class DnsMonitor {
  public static function sourceDomain(string $name):string{return substr($name,strrpos($name,'@')+1);}
 }
}
namespace {
 $plugin=dirname(__DIR__,2);
 $core=getenv('FICMS_CORE') ?: dirname($plugin).'/fiCMS';
 foreach(['include/classes/fiCMS/src/Files.php','include/classes/imap/src/State.php','include/classes/imap/src/Vault.php','include/classes/imap/src/ConnectionStore.php'] as $file) require $core.'/'.$file;
 foreach(['State','JobStore','JobConfiguration'] as $class) require $plugin.'/src/'.$class.'.php';
 if(!function_exists('pcntl_fork')) throw new \RuntimeException('test_requires_pcntl');
 function check(bool $condition,string $message):void{if(!$condition)throw new \RuntimeException($message);echo 'PASS '.$message."\n";}
 function mysqlEscape($value){return addslashes($value);}
 function mysqlFetchAssoc($sql){if(isset($GLOBALS['beforeRecipient'])){$callback=$GLOBALS['beforeRecipient'];unset($GLOBALS['beforeRecipient']);$callback();}return ['id'=>1];}
 $GLOBALS['tables']=['user'=>'users'];
 $_SERVER['now']=time();
 $directory=sys_get_temp_dir().'/emailsync-cas-'.bin2hex(random_bytes(8));
 mkdir($directory,0700);$previous=getcwd();chdir($directory);$parentPid=getmypid();
 register_shutdown_function(function()use($directory,$previous,$parentPid):void{
  if(getmypid()!==$parentPid)return;
  chdir($previous);
  $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
  foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($directory);
 });
 $created=\emailsync\JobConfiguration::save(['name'=>'Synthetic concurrency fixture','report_email'=>'owner@example.invalid','source_username'=>'source@example.invalid','source_host'=>'source.example.invalid','destination_username'=>'target@example.invalid','destination_host'=>'target.example.invalid','destination_password'=>'synthetic-destination-canary']);
 check(!empty($created['result']),'real owners create an inactive synthetic draft');
 $id=$created['id'];$job=\emailsync\JobStore::get($id);$identities=[];
 foreach(['source','destination'] as $side){$connection=\imap\ConnectionStore::get($job[$side.'_id']);foreach(['id','host','port','security','username'] as $field)$identities[$side][$field]=$connection[$field];}
 $payload=['source_password'=>'synthetic-source-canary','expected_configuration_revision'=>$job['configuration_revision'],'expected_identity'=>$identities];
 $sockets=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
 foreach($sockets as $socket)stream_set_timeout($socket,10);
 $pid=pcntl_fork();
 if($pid===-1)throw new \RuntimeException('test_fork_failed');
 if($pid===0){
  fclose($sockets[0]);
  if(fgets($sockets[1])!=="read\n")exit(2);
  $changed=\emailsync\JobConfiguration::save(['destination_host'=>'changed.example.invalid','destination_password'=>'synthetic-new-destination'],$id);
  fwrite($sockets[1],!empty($changed['result'])?"changed\n":"failed\n");fclose($sockets[1]);exit(empty($changed['result'])?3:0);
 }
 fclose($sockets[1]);$afterConcurrentJob=[];$afterConcurrentConnections=[];
 // The first process already read and validated its expected binding before this DB lookup.
 // The second process now completes a real target change before the first reaches the CAS lock.
 $GLOBALS['beforeRecipient']=function()use($sockets,$id,&$afterConcurrentJob,&$afterConcurrentConnections):void{
  fwrite($sockets[0],"read\n");
  if(fgets($sockets[0])!=="changed\n")throw new \RuntimeException('test_concurrent_write_failed');
  $afterConcurrentJob=\emailsync\JobStore::get($id);
  $afterConcurrentConnections=\imap\ConnectionStore::list(true);
 };
 $result=\emailsync\JobConfiguration::saveProtectedCredential($payload,$id);
 fclose($sockets[0]);pcntl_waitpid($pid,$status);
 check(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'second PHP process completes the concurrent target change');
 check($result===['result'=>false,'error'=>'target_changed'],'protected write rejects an actual target change after its initial read');
 check(\emailsync\JobStore::get($id)===$afterConcurrentJob&&\imap\ConnectionStore::list(true)===$afterConcurrentConnections,'rejected concurrent write preserves the winning job and encrypted connections exactly');
 $job=\emailsync\JobStore::get($id);
 check(\imap\ConnectionStore::credentials($job['source_id'])['password']===''&&\imap\ConnectionStore::credentials($job['destination_id'])['password']==='synthetic-new-destination','rejected source secret is absent and concurrent destination secret is retained');
 foreach(['source','destination'] as $side){$connection=\imap\ConnectionStore::get($job[$side.'_id']);foreach(['id','host','port','security','username'] as $field)$payload['expected_identity'][$side][$field]=$connection[$field];}
 $payload['expected_configuration_revision']=$job['configuration_revision'];
 $destination=\imap\ConnectionStore::get($job['destination_id'],true);
 check(\emailsync\JobConfiguration::saveProtectedCredential($payload,$id)===['result'=>true,'type'=>'emailsync','id'=>$id,'status'=>'submitted'],'current binding succeeds through real encrypted connection storage');
 $written=\emailsync\JobStore::get($id);
 check($written['phase']==='draft'&&$written['active']===0&&$written['run_count']===0&&\imap\ConnectionStore::get($written['destination_id'],true)===$destination,'real protected write preserves draft state and the entire destination ciphertext');
 check(\imap\ConnectionStore::credentials($written['source_id'])['password']==='synthetic-source-canary'&&!str_contains(file_get_contents(\imap\State::path('connections.json')),'synthetic-source-canary'),'only encrypted canary is persisted by the existing Vault owner');
 echo "All real-lock concurrency checks passed; temporary synthetic state removed on exit.\n";
}
