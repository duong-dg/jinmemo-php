<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/FullTransferHolding.php';require dirname(__DIR__).'/src/TransferReview.php';require dirname(__DIR__).'/src/TransferFileAudit.php';
function v2check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$fixture=file_get_contents(__DIR__.'/fixtures/full-v2-node.sql');$path=tempnam(sys_get_temp_dir(),'fullv2_');
$check=static function(string $sql)use($path):array{file_put_contents($path,$sql);clearstatcache(true,$path);$r=TransferFormat::audit($path)->run($path);v2check(hash_file('sha256',$path)===hash('sha256',$sql),'Input changed');v2check(!$r['importReady'],'Claimed operational import');return $r;};
$server=null;$db=null;$name='jingo_v2_'.bin2hex(random_bytes(6));
try{
 $r=$check($fixture);v2check($r['manifestValid']&&$r['profileMatches']&&count($r['tables'])===69,'Node fixture rejected '.json_encode($r['errors']));
 v2check(!str_contains($fixture,bin2hex('omit-me'))&&!str_contains($fixture,'`access_hash`'),'Exporter leaked credentials');
 $missing=file_get_contents(__DIR__.'/fixtures/full-v2-missing-node.sql');$r=$check($missing);v2check($r['profileMatches']&&$r['missingTables']===['jm_transfer_admin_notifications'],'Declared missing rejected');
 $hex=static fn(string $s):string=>bin2hex($s);
 $bad=[
  $fixture."DROP TABLE jingo_orders;\n",
  str_replace('SET NAMES utf8mb4;','USE production;',$fixture),
  str_replace('`version` BIGINT NULL','`password_hash` BIGINT NULL',$fixture),
  str_replace($hex('preserved'),$hex('tampered!'),$fixture),
  str_replace($hex('EXPORTED'),$hex('MISSING_SOURCE'),$fixture),
  str_replace($hex('"column_name":"id"'),$hex('"column_name":"xx"'),$fixture),
  str_replace($hex('"data_type":"text"'),$hex('"data_type":"uuid"'),$fixture),
  str_replace($hex('FILTERED_BUSINESS_SETTINGS'),$hex('EXPORTED'),$fixture),
  str_replace($hex('{"fixture":true'),$hex('{"api_key":true'),$fixture),
  str_replace($hex('manual-payments'),$hex('smtp-config'),$fixture),
  str_replace($hex('jinmemo_weather_cache'),$hex('jinmemo_unknown_cache'),$fixture),
  str_replace("\n","\r\n",$fixture),
  substr($fixture,0,-1)
 ];
 foreach($bad as $i=>$sql){$r=$check($sql);v2check(!$r['profileMatches']&&$r['errorCount']>0,'Invalid v2 accepted '.$i);v2check(!str_contains(json_encode($r),'preserved'),'Private values in report');}
 $r=$check(str_replace('full transfer v2','full transfer v3',$fixture));v2check(!$r['formatValid'],'Unknown version accepted');
 $dsn=getenv('JINGO_TEST_DSN');if(!$dsn)throw new RuntimeException('Disposable JINGO_TEST_DSN required');$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];$user=getenv('JINGO_TEST_USER')?:'root';$pass=getenv('JINGO_TEST_PASSWORD')?:'';
 $server=new PDO($dsn,$user,$pass,$opts);$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db=new PDO($dsn.';dbname='.$name,$user,$pass,$opts);
 foreach(glob(dirname(__DIR__).'/database/*.sql') as $f)foreach(explode(';',file_get_contents($f)) as $sql)if(trim($sql)!=='')$db->exec($sql);
 file_put_contents($path,$missing);$service=new FullTransferHolding($db);$m=$service->run($path);v2check($m['ready']&&!$m['operationalReady']&&in_array('SOURCE_MODULES_DECLARED_MISSING',$m['activationBlockers'],true),'Missing module hidden');file_put_contents($path,$fixture);$r=$service->run($path);v2check($r['ready']&&!$r['operationalReady']&&$r['counts']['sourceRows']===161,'V2 dry-run '.json_encode($r));$fp=$r['fingerprint'];
 v2check((int)$db->query('SELECT COUNT(*) FROM jingo_transfer_records')->fetchColumn()===0,'Dry-run writes');
 $r=$service->run($path,true,str_repeat('0',64));v2check(!$r['ready'],'Wrong fingerprint accepted');
 $r=$service->run($path,true,$fp);v2check($r['applied']&&$r['counts']['insertedRows']===161,'V2 apply');
 $payloads=$db->query("SELECT payload FROM jingo_transfer_records WHERE table_name='jm_transfer_settings'")->fetchAll(PDO::FETCH_COLUMN);v2check(count($payloads)===5&&str_contains($payloads[0],'9007199254740993')&&!str_contains(implode('',$payloads),'apiKey'),'JSON precision/redaction');
 $overview=(new TransferReview($db))->overview();v2check(count($overview['tables'])===69&&!$overview['operationalReady']&&$overview['runtimePlan']['tables']['jm_transfer_manifest']['status']==='ARCHIVE_METADATA','V2 admin review');
 $order=$db->query("SELECT payload FROM jingo_transfer_records WHERE table_name='jm_transfer_orders' LIMIT 1")->fetchColumn();v2check(!array_key_exists('access_hash',json_decode($order,true)),'Invented proof');
 $r=$service->run($path,true,$fp);v2check($r['counts']['insertedRows']===0&&$r['counts']['matchingRows']===161,'Replay duplicated');
 $assets=sys_get_temp_dir().'/'.$name;mkdir($assets);mkdir($assets.'/media');mkdir($assets.'/tickets');
 try{
  copy(__DIR__.'/fixtures/transfer-media.webp',$assets.'/media/00000000-0000-4000-8000-000000009901.webp');$pdf=$assets.'/tickets/00000000-0000-4000-8000-000000009401.bin';copy(__DIR__.'/fixtures/transfer-original.pdf',$pdf);$hash=hash_file('sha256',$pdf);
  $files=(new TransferFileAudit())->run($path,$assets.'/media',$assets.'/tickets');v2check($files['ready']&&$files['counts']['ticket_files']===1&&hash_file('sha256',$pdf)===$hash,'V2 original PDF verification');
  if(!preg_match('/host=([^;]+);port=(\d+)/',$dsn,$conn))throw new RuntimeException('Host/port required');
  $env=array_merge(getenv(),['DB_HOST'=>$conn[1],'DB_PORT'=>$conn[2],'DB_DATABASE'=>$name,'DB_USERNAME'=>$user,'DB_PASSWORD'=>$pass]);$pipes=[];
  $proc=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/import-transfer.php','--file='.$path,'--media-dir='.$assets.'/media','--ticket-dir='.$assets.'/tickets'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__),$env);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$cli=json_decode($output,true,512,JSON_THROW_ON_ERROR);v2check($exit===0&&$err===''&&$cli['format']==='full-v2'&&$cli['ready']&&!$cli['applied']&&$cli['originalFiles']['ready'],'CLI v2 auto-detection');
 }finally{foreach(glob($assets.'/media/*') as $f)unlink($f);foreach(glob($assets.'/tickets/*') as $f)unlink($f);rmdir($assets.'/media');rmdir($assets.'/tickets');rmdir($assets);}
 file_put_contents($path,file_get_contents(__DIR__.'/fixtures/full-transfer-node.sql'));$r=$service->run($path);v2check(!$r['ready'],'Mixed v1/v2 overwrite accepted');
 foreach(['orders','admins','mail_outbox','partner_ledger'] as $table)v2check((int)$db->query('SELECT COUNT(*) FROM jingo_'.$table)->fetchColumn()===0,'Live write '.$table);
 echo "Full-v2 passed: Node serialization, manifest/schema/hash rejection, missing-source declaration, lossless holding, no fabricated proofs, replay and v1 mixing blocked.\n";
}finally{$db=null;if($server)$server->exec("DROP DATABASE IF EXISTS `$name`");unlink($path);}
