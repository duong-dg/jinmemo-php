<?php
declare(strict_types=1);
require_once __DIR__.'/DatabaseExport.php';
require_once __DIR__.'/Backups.php';
/** Encrypted database-only recovery archive. Does not imply a full-stack backup. */
final class DatabaseBackup
{
    public function __construct(private PDO $db,private array $config){}
    private static function regular(string $path):bool{return is_file($path)&&!is_link($path);}
    private static function privatePath(string $path,bool $directory):string
    {
        $normal=str_replace('\\','/',$path);
        if(!preg_match('~^(?:[A-Za-z]:/|/)~',$normal)||is_link($path))throw new RuntimeException('Invalid private path.');
        $real=realpath($path);$public=realpath(dirname(__DIR__).'/public');
        $norm=static fn($v)=>strtolower(str_replace('\\','/',rtrim($v,'/\\')));
        if(!$real||($directory?!is_dir($real):!self::regular($real))||$public&&($norm($real)===$norm($public)||str_starts_with($norm($real),$norm($public).'/')))throw new RuntimeException('Private path must exist outside public.');
        if(PHP_OS_FAMILY!=='Windows'&&(fileperms($real)&0077)!==0)throw new RuntimeException('Private path permissions must exclude group and others.');
        return $real;
    }
    private static function json(string $path,array $data):void
    {
        $tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
        try{$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)||!chmod($tmp,0600)||!rename($tmp,$path))throw new RuntimeException('Cannot publish backup metadata.');}finally{if(self::regular($tmp))unlink($tmp);}
    }
    private function gpg(array $args,string $home,string $key,string $binary):void
    {
        $path=fn($p)=>PHP_OS_FAMILY==='Windows'&&($this->config['BACKUP_GPG_PATH_STYLE']??'')==='CYGWIN'?preg_replace_callback('~^([A-Za-z]):/~',fn($m)=>'/'.strtolower($m[1]).'/',str_replace('\\','/',$p)):$p;$home=$path($home);$key=$path($key);$args=array_map($path,$args);
        $pipes=[];$process=proc_open(array_merge([$binary,'--no-options','--homedir',$home,'--batch','--yes','--no-symkey-cache','--pinentry-mode','loopback','--passphrase-file',$key],$args),[['pipe','r'],['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a'],['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Cannot start encryption.');fclose($pipes[0]);
        if(proc_close($process)!==0)throw new RuntimeException('Encryption or verification failed.');
    }
    public function run(int $keep,string $revision):array
    {
        if($keep<1||$keep>90||!preg_match('/^[a-f0-9]{40,64}$/D',$revision))throw new InvalidArgumentException('Invalid keep/revision.');
        if(!in_array($this->config['BACKUP_GPG_PATH_STYLE']??'NATIVE',['NATIVE','CYGWIN'],true))throw new RuntimeException('Invalid GPG path style.');
        if(!class_exists('PharData')||!function_exists('proc_open'))throw new RuntimeException('CLI requires Phar and proc_open.');
        $root=self::privatePath($this->config['BACKUP_DIR']??'',true);$home=self::privatePath($this->config['BACKUP_GPG_HOME']??'',true);$key=self::privatePath($this->config['BACKUP_PASSPHRASE_FILE']??'',false);
        $binary=$this->config['BACKUP_GPG_BINARY']??'';if(!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~',$binary)||!self::regular($binary)||!is_executable($binary))throw new RuntimeException('Configure an absolute GPG executable.');
        if(filesize($key)>4096)throw new RuntimeException('Invalid backup key.');$pass=file_get_contents($key);$pass=rtrim($pass,"\r\n");if(strlen($pass)<32||str_contains($pass,"\n")||str_contains($pass,"\r")||str_contains($pass,"\0"))throw new RuntimeException('Use a single-line backup passphrase of at least 32 bytes.');unset($pass);
        $lockPath=$root.'/.php-database-backup.lock';if(is_link($lockPath))throw new RuntimeException('Invalid backup lock.');$lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('Another backup is running.');}chmod($lockPath,0600);
        $id='jinmemo-backup-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));$work=$root.'/.work-'.$id;$phase='preflight';$started=gmdate('Y-m-d\TH:i:s\Z');$created=false;$sql=null;
        $state=function(string $value)use($root,$started,&$phase){self::json($root.'/last-run.json',['state'=>$value,'startedAt'=>$started,'updatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'phase'=>$phase]);};
        try{
            $state('RUNNING');if(!mkdir($work,0700))throw new RuntimeException('Cannot create private workspace.');$created=true;
            $phase='database-dump';$state('RUNNING');$sql=(new DatabaseExport($this->db))->create('cli:database-backup');$target=fopen($work.'/database.sql','xb');if(!$target)throw new RuntimeException('Cannot save snapshot.');try{if(stream_copy_to_stream($sql,$target)!==fstat($sql)['size'])throw new RuntimeException('Cannot save snapshot.');}finally{fclose($target);}fclose($sql);$sql=null;chmod($work.'/database.sql',0600);
            $handle=fopen($work.'/database.sql','rb');fseek($handle,-min(filesize($work.'/database.sql'),65536),SEEK_END);$tail=stream_get_contents($handle);fclose($handle);if(!preg_match('/-- PHP DATABASE COMPLETE (\{[^\r\n]+\})\s*$/D',$tail,$match))throw new RuntimeException('Incomplete database snapshot.');$counts=json_decode($match[1],true,32,JSON_THROW_ON_ERROR);
            $snapshot=['orders'=>$counts['jingo_orders']??0,'carts'=>$counts['jingo_carts']??0,'products'=>$counts['jingo_products']??0,'payments'=>$counts['jingo_order_payments']??0,'ticketFiles'=>0];
            $manifest=['format'=>'jinmemo-php-database-backup-v1','scope'=>'PHP_DATABASE_ONLY','id'=>$id,'createdAt'=>$started,'revision'=>$revision,'databaseFile'=>'database.sql','databaseSha256'=>hash_file('sha256',$work.'/database.sql'),'tables'=>$counts,'excluded'=>['media','original-ticket-files','smtp-key','environment','source-code']];
            self::json($work.'/manifest.json',$manifest);$phase='compose-snapshot';$state('RUNNING');$tar=new PharData($work.'/snapshot.tar');$tar->addFile($work.'/database.sql','database.sql');$tar->addFile($work.'/manifest.json','manifest.json');unset($tar);chmod($work.'/snapshot.tar',0600);
            $phase='encryption';$state('RUNNING');$this->gpg(['--cipher-algo','AES256','--output',$work.'/encrypted.tar.gpg','--symmetric',$work.'/snapshot.tar'],$home,$key,$binary);chmod($work.'/encrypted.tar.gpg',0600);
            $phase='archive-verification';$state('RUNNING');$this->gpg(['--output',$work.'/verified.tar','--decrypt',$work.'/encrypted.tar.gpg'],$home,$key,$binary);chmod($work.'/verified.tar',0600);if(!hash_equals(hash_file('sha256',$work.'/snapshot.tar'),hash_file('sha256',$work.'/verified.tar')))throw new RuntimeException('Archive verification mismatch.');
            $meta=['format'=>'jinmemo-php-database-backup-v1','id'=>$id,'createdAt'=>$started,'verifiedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'bytes'=>filesize($work.'/encrypted.tar.gpg'),'sha256'=>hash_file('sha256',$work.'/encrypted.tar.gpg'),'revision'=>$revision,'snapshot'=>$snapshot,'scope'=>'PHP_DATABASE_ONLY'];
            $phase='publish-backup';$state('RUNNING');if(file_exists($root.'/'.$id.'.tar.gpg')||is_link($root.'/'.$id.'.tar.gpg')||file_exists($root.'/'.$id.'.json')||is_link($root.'/'.$id.'.json'))throw new RuntimeException('Backup id already exists.');if(!rename($work.'/encrypted.tar.gpg',$root.'/'.$id.'.tar.gpg'))throw new RuntimeException('Cannot publish archive.');try{self::json($root.'/'.$id.'.json',$meta);}catch(Throwable $e){if(self::regular($root.'/'.$id.'.tar.gpg'))unlink($root.'/'.$id.'.tar.gpg');throw $e;}
            $view=new Backups($this->db,$root);$records=[];foreach(glob($root.'/jinmemo-backup-*.json')?:[] as $file){try{$r=$view->record(basename($file,'.json'));if(($r['scope']??null)==='PHP_DATABASE_ONLY'&&$r['id']!==$id)$records[]=$r;}catch(HttpError){}}usort($records,fn($a,$b)=>strcmp($b['id'],$a['id']));$remove=array_slice($records,$keep-1);
            foreach($remove as $old){$path=$root.'/'.$old['id'].'.tar.gpg';if(!self::regular($path)||!hash_equals($old['sha256'],hash_file('sha256',$path)))continue;foreach(['.json','.restore-test.json','.tar.gpg'] as $suffix){$p=$root.'/'.$old['id'].$suffix;if(self::regular($p)&&!unlink($p))throw new RuntimeException('Cannot rotate verified backups.');}}
            $state('SUCCESS');return array_intersect_key($meta,array_flip(['id','scope','bytes','sha256','revision']));
        }catch(Throwable $e){try{$state('FAILED');}catch(Throwable){}throw $e;}finally{if(is_resource($sql))fclose($sql);if($created)foreach(['database.sql','manifest.json','snapshot.tar','encrypted.tar.gpg','verified.tar'] as $name)if(self::regular($work.'/'.$name))unlink($work.'/'.$name);if($created&&is_dir($work)&&!is_link($work))rmdir($work);flock($lock,LOCK_UN);fclose($lock);}
    }
}
