<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/BackupVerification.php';
try{
    $args=[];foreach(array_slice($argv,1) as $arg){if($arg==='--help'){echo "php bin/verify-backup.php --archive=ABSOLUTE_TAR_GPG --work-dir=PRIVATE_DIRECTORY [--sha256=EXPECTED_HASH]\nNative PHP archive integrity/structure check only. Requires GPG/passphrase configuration; does not connect to MySQL, execute SQL, restore files, install cron or create a restore-test marker.\n";exit;}if(!preg_match('/^--(archive|work-dir|sha256)=(.+)$/D',$arg,$m)||isset($args[$m[1]]))throw new InvalidArgumentException('Invalid arguments. Use --help.');$args[$m[1]]=$m[2];}if(!isset($args['archive'],$args['work-dir']))throw new InvalidArgumentException('Provide --archive and --work-dir.');$config=jingoConfig();foreach(['BACKUP_GPG_HOME','BACKUP_PASSPHRASE_FILE','BACKUP_GPG_BINARY','BACKUP_GPG_PATH_STYLE'] as $name){$v=getenv($name);if($v!==false)$config[$name]=$v;}$old=umask(0077);try{$report=(new BackupVerification($config))->run($args['archive'],$args['work-dir'],$args['sha256']??null);}finally{umask($old);}echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Backup verification failed. Check native format, ciphertext hash, GPG/key, private workspace/quota and archive integrity. No SQL is executed and no archive paths are extracted.\n");exit(1);}
