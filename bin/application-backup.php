<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/DatabaseBackup.php';
require dirname(__DIR__).'/src/ApplicationSnapshot.php';
$gate=null;
try{
    $keep=7;$revision=null;$maintenance=false;
    foreach(array_slice($argv,1) as $arg){if($arg==='--help'){echo "php bin/application-backup.php --maintenance --revision=GIT_COMMIT [--keep=7]\nOpt-in short maintenance window: drains cooperating PHP requests/workers, captures database/media/key/environment/Git code, then resumes requests before GPG encryption. Requires BACKUP_COORDINATION_DIR and BACKUP_GIT_BINARY, plus database-backup configuration. Pause external SQL/FTP/deploy writers. Does not install cron or restore over live data.\n";exit;}if($arg==='--maintenance'&&!$maintenance){$maintenance=true;continue;}if(str_starts_with($arg,'--revision=')&&$revision===null){$revision=substr($arg,11);continue;}if(preg_match('/^--keep=([1-9][0-9]?)$/D',$arg,$m)){$keep=(int)$m[1];continue;}throw new InvalidArgumentException('Invalid arguments. Use --help.');}
    if(!$maintenance||!is_string($revision)||!preg_match('/^[a-f0-9]{40,64}$/D',$revision)||$keep<1||$keep>90)throw new InvalidArgumentException('Use --maintenance, a matching Git revision and keep 1..90.');
    $config=jingoConfig();foreach(['BACKUP_GPG_HOME','BACKUP_PASSPHRASE_FILE','BACKUP_GPG_BINARY','BACKUP_GPG_PATH_STYLE','BACKUP_GIT_BINARY'] as $name){$v=getenv($name);if($v!==false)$config[$name]=$v;}
    $old=umask(0077);try{$gate=BackupGate::exclusive($config['BACKUP_COORDINATION_DIR']??'');$db=jingoDatabase($config,$gate);$snapshot=new ApplicationSnapshot($config,dirname(__DIR__),$gate);$result=(new DatabaseBackup($db,$config,$snapshot))->run($keep,$revision);}finally{if($gate)$gate->release();umask($old);}echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Application backup failed. Check coordination/private paths, clean matching Git revision, media/key/environment, GPG and quota. Unverified snapshots are not published.\n");exit(1);}finally{if($gate)$gate->release();}
