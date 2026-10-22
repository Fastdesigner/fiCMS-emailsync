<?php
namespace ficms {
 final class Runtime {
  public static float $elapsed=0;public static float $limit=10;private static array $starts=[];
  public static function nextRequest():void{self::$starts=[];}
  public static function load(string $name):int{self::$starts[$name]??=self::$elapsed;return (int) ((self::$elapsed-self::$starts[$name])*1000);}
  public static function loadCheck(string $name,$threshold):bool{return self::load($name)<$threshold*1000;}
  public static function grant(float $seconds):bool{return self::$elapsed+$seconds<=self::$limit;}
 }
}
namespace emailsync {
 final class State {
  public static array $files=[];
  public static function read(string $name):array{return self::$files[$name]??[];}
  public static function update(string $name,callable $callback):array|false{return self::$files[$name]=$callback(self::read($name));}
  public static function path(string $name):string{return $name;}
  public static function write(string $name,$value,bool $secure=false):bool{return true;}
 }
}
namespace imap {
 final class Client {
  public static string $fail='';public static array $closed=[];public static array $appended=[];private string $side;
  public function __construct(array $connection){$this->side=$connection['side'];}
  public function connect():void{if(self::$fail===$this->side)throw new \RuntimeException('imap_command_failed_login');}
  public function close():void{self::$closed[]=$this->side;}
  public function folders():array{return [['name'=>'INBOX','delimiter'=>'/','attributes'=>[]]];}
  public function select(string $name,bool $readOnly=false):array{return ['uidvalidity'=>1,'exists'=>20];}
  public function uidsAfter(int $uid):array{return $uid<20?range($uid+1,20):[];}
  public function fetch(int $uid):array{\ficms\Runtime::$elapsed+=1;$stream=fopen('php://temp','w+');fwrite($stream,"Message-ID: <$uid@fixture.invalid>\r\n\r\nSynthetic $uid");rewind($stream);return ['stream'=>$stream,'size'=>ftell($stream)+50,'flags'=>[],'internaldate'=>'1-Jan-2020 00:00:00 +0000'];}
  public static function hash($stream):string{return hash('sha256',stream_get_contents($stream));}
  public function uidsByHeader(...$args):array{return [];}
  public function uidsBySize(...$args):array{return [];}
  public function append(string $name,$stream,int $size,array $flags,string $date):int{self::$appended[]=count(self::$appended)+1;return count(self::$appended);}
  public function subscribe(string $name):void{}
 }
}
namespace {
 require dirname(__DIR__,2).'/src/ProgressStore.php';
 require dirname(__DIR__,2).'/src/MailboxSync.php';
 $_SERVER['now']=time();$checks=0;
 function check($condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;echo "PASS $message\n";}
 $job=['id'=>'budget'];$source=['side'=>'source'];$target=['side'=>'destination'];
 $r=\emailsync\MailboxSync::run($job,$source,$target);
 check($r['success']&&$r['partial']&&$r['stats']['messages_transferred']===8,'real transfer loop copies eight synthetic one-second messages per slice');
 check(\emailsync\ProgressStore::get('budget')['folders'][hash('sha256','INBOX')]['last_uid']===8,'slice persists the last safely transferred UID');
 \ficms\Runtime::$limit=30;\ficms\Runtime::nextRequest();
 $r=\emailsync\MailboxSync::run($job,$source,$target);
 check($r['success']&&$r['stats']['messages_transferred']===8&&\emailsync\ProgressStore::get('budget')['folders'][hash('sha256','INBOX')]['last_uid']===16,'next slice resumes without recopying the previous eight');
 \ficms\Runtime::nextRequest();$r=\emailsync\MailboxSync::run($job,$source,$target);
 check($r['success']&&!$r['partial']&&$r['stats']['messages_transferred']===4,'last slice completes the remaining messages');
 \ficms\Runtime::$limit=\ficms\Runtime::$elapsed+3;\ficms\Runtime::nextRequest();
 $r=\emailsync\MailboxSync::run(['id'=>'limited'],$source,$target);
 check($r['partial']&&$r['stats']['messages_transferred']===3,'shared cron budget limits a slice before its own eight-second cap');
 foreach(['source','destination'] as $side){\imap\Client::$fail=$side;\imap\Client::$closed=[];$r=\emailsync\MailboxSync::run(['id'=>'failed-'.$side],$source,$target);check(!$r['success']&&$r['error_side']===$side&&$r['error']==='imap_command_failed_login',"$side login failure is attributed to its actual side");check(count(\imap\Client::$closed)===2,'both IMAP clients are closed after login failure');}
 echo "$checks real transfer budget checks passed; no network or persistent state.\n";
}
