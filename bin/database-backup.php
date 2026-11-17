<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/DatabaseBackup.php';
try{
    $keep=7;$revision=null;
    foreach(array_slice($argv,1) as $arg){if($arg==='--help'){echo "php bin/database-backup.php --revision=GIT_COMMIT [--keep=7]\nEncrypted PHP database only; requires private BACKUP_DIR, BACKUP_GPG_HOME, BACKUP_PASSPHRASE_FILE and absolute BACKUP_GPG_BINARY. Does not back up media, original tickets, keys, environment or code. Does not install cron.\n";exit;}if(str_starts_with($arg,'--revision=')&&$revision===null){$revision=substr($arg,11);continue;}if(preg_match('/^--keep=([1-9][0-9]?)$/D',$arg,$m)){$keep=(int)$m[1];continue;}throw new InvalidArgumentException('Invalid arguments. Use --help.');}
    if($revision===null)throw new InvalidArgumentException('Provide --revision=GIT_COMMIT.');$config=jingoConfig();foreach(['BACKUP_GPG_HOME','BACKUP_PASSPHRASE_FILE','BACKUP_GPG_BINARY','BACKUP_GPG_PATH_STYLE'] as $name){$v=getenv($name);if($v!==false)$config[$name]=$v;}
    $old=umask(0077);try{$result=(new DatabaseBackup(jingoDatabase($config),$config))->run($keep,$revision);}finally{umask($old);}echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Database backup failed. Check private path permissions, CLI/GPG configuration, passphrase, disk space and database access. No unverified archive is published; inspect last-run status.\n");exit(1);}
