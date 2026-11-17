<?php
declare(strict_types=1);
require_once __DIR__.'/BackupGate.php';
/** Captures app files while cooperating PHP requests/workers are drained. */
final class ApplicationSnapshot
{
    public function __construct(private array $config,private string $application,private BackupGate $gate){}
    private static function inside(string $path,string $root):bool{$n=fn($v)=>strtolower(str_replace('\\','/',rtrim($v,'/\\')));return $n($path)===$n($root)||str_starts_with($n($path),$n($root).'/');}
    private function git(array $args):string
    {
        $binary=$this->config['BACKUP_GIT_BINARY']??'';if(!is_file($binary)||!is_executable($binary)||is_link($binary)||!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~',$binary))throw new RuntimeException('Configure an absolute Git executable.');$pipes=[];$p=proc_open(array_merge([$binary,'-C',$this->application],$args),[['pipe','r'],['pipe','w'],['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($p))throw new RuntimeException('Cannot read deployed source.');fclose($pipes[0]);$out=stream_get_contents($pipes[1],1048577);fclose($pipes[1]);$exit=proc_close($p);if($exit!==0||strlen($out)>1048576)throw new RuntimeException('Cannot verify deployed source.');return trim($out);
    }
    private static function info(string $path):array
    {
        clearstatcache(true,$path);$s=lstat($path);if(!$s||is_link($path)||($s['mode']&0170000)!==0100000)throw new RuntimeException('Snapshot accepts regular files only.');$h=hash_file('sha256',$path);if($h===false)throw new RuntimeException('Cannot hash snapshot file.');clearstatcache(true,$path);$after=lstat($path);if(!$after||$s['size']!==$after['size']||$s['mtime']!==$after['mtime']||$s['ino']!==$after['ino']||$s['dev']!==$after['dev'])throw new RuntimeException('File changed during snapshot.');return ['bytes'=>$s['size'],'sha256'=>$h];
    }
    private static function files(string $root):array
    {
        $out=[];$total=0;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $entry){if($entry->isLink())throw new RuntimeException('Media symlinks are not backed up.');if($entry->isDir())continue;$rel=str_replace('\\','/',substr($entry->getPathname(),strlen($root)+1));foreach(explode('/',$rel) as $part)if($part===''||$part==='.'||$part==='..'||preg_match('/[\x00-\x1f\x7f:\\\\]/',$part)||!preg_match('//u',$part))throw new RuntimeException('Unsupported media filename.');$meta=self::info($entry->getPathname());$total+=$meta['bytes'];if(count($out)>=50000||$total>2147483648)throw new RuntimeException('Media exceeds application snapshot limits.');$out[$rel]=$meta;}ksort($out,SORT_STRING);return $out;
    }
    public function release():void{$this->gate->release();}
    public function capture(string $work,array $manifest):array
    {
        try{
            if(!$this->gate->owns($this->config['BACKUP_COORDINATION_DIR']??''))throw new RuntimeException('Application snapshot requires an exclusive gate.');$app=realpath($this->application);$media=realpath($this->config['MEDIA_DIR']??$this->application.'/storage/media');$public=realpath($this->application.'/public');if(!$app||!$media||self::inside($app,$media)||!is_dir($media)||is_link($this->config['MEDIA_DIR']??$media)||$public&&self::inside($media,$public))throw new RuntimeException('Use private media storage.');
            foreach(['BACKUP_DIR','BACKUP_GPG_HOME','BACKUP_PASSPHRASE_FILE','BACKUP_COORDINATION_DIR'] as $name){$p=realpath($this->config[$name]??'');if(!$p||self::inside($p,$app)||self::inside($p,$media)||self::inside($app,$p)||self::inside($media,$p))throw new RuntimeException('Backup resources must be separate from source and media.');}
            if($this->git(['rev-parse','--show-toplevel'])!==str_replace('\\','/',$app)&&realpath($this->git(['rev-parse','--show-toplevel']))!==$app)throw new RuntimeException('Application must be the repository root.');if($this->git(['rev-parse','HEAD'])!==$manifest['revision']||$this->git(['status','--porcelain','--untracked-files=all'])!=='')throw new RuntimeException('Deploy a clean, matching Git revision first.');
            $environment=$app.'/.env';$envBefore=self::info($environment);$diskGate=null;foreach(file($environment,FILE_IGNORE_NEW_LINES) as $line){$pair=explode('=',trim($line),2);if(count($pair)===2&&trim($pair[0])==='BACKUP_COORDINATION_DIR')$diskGate=trim(trim($pair[1]),"\"'");}if(!$diskGate||!$this->gate->owns($diskGate))throw new RuntimeException('Persist the same coordination directory in application .env first.');$before=self::files($media);if(!isset($before['.smtp-key'])||$before['.smtp-key']['bytes']!==32)throw new RuntimeException('A complete 32-byte application encryption key is required.');
            $tar=new PharData($work.'/media.tar');foreach($before as $path=>$meta)$tar->addFile($media.'/'.$path,$path);unset($tar);chmod($work.'/media.tar',0600);
            foreach($before as $path=>$meta){$f=fopen('phar://'.$work.'/media.tar/'.$path,'rb');if(!$f)throw new RuntimeException('Cannot verify media entry.');$hash=hash_init('sha256');hash_update_stream($hash,$f);$s=fstat($f);fclose($f);if($s['size']!==$meta['bytes']||!hash_equals($meta['sha256'],hash_final($hash)))throw new RuntimeException('Media archive content mismatch.');}
            $this->git(['-c','core.autocrlf=false','-c','core.eol=lf','archive','--format=tar','--output='.$work.'/source.tar',$manifest['revision']]);chmod($work.'/source.tar',0600);if(!copy($environment,$work.'/environment.env'))throw new RuntimeException('Cannot snapshot environment.');chmod($work.'/environment.env',0600);if(self::info($work.'/environment.env')!==$envBefore||self::info($environment)!==$envBefore||self::files($media)!==$before||$this->git(['rev-parse','HEAD'])!==$manifest['revision']||$this->git(['status','--porcelain','--untracked-files=all'])!=='')throw new RuntimeException('Application changed while taking snapshot.');
            $effective=json_encode($this->config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(file_put_contents($work.'/effective-config.json',$effective)!==strlen($effective))throw new RuntimeException('Cannot snapshot effective configuration.');chmod($work.'/effective-config.json',0600);
            $manifest['format']='jinmemo-php-application-backup-v1';$manifest['scope']='PHP_APPLICATION';$manifest['media']=$before;$manifest['files']=[];foreach(['database.sql','media.tar','source.tar','environment.env','effective-config.json'] as $file)$manifest['files'][$file]=self::info($work.'/'.$file);$manifest['excluded']=['backup-passphrase','gpg-home','git-history','cpanel-account','ssl-certificates','cron-installation'];$manifest['coordination']='PHP bootstrap gate; external database/FTP/deploy writers must be paused';return $manifest;
        }finally{$this->release();}
    }
}
