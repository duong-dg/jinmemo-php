<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/TransportPlan.php';
function sectionAssert(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function sectionStatus(int $expected,callable $fn):void {try{$fn();}catch(HttpError $e){sectionAssert($e->status===$expected,'Wrong status '.$e->status.' '.$e->getMessage());return;}throw new RuntimeException('Expected '.$expected);}
$dsn=getenv('JINGO_TEST_DSN');if(!$dsn)exit(1);$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];$user=getenv('JINGO_TEST_USER')?:'root';$password=getenv('JINGO_TEST_PASSWORD')?:'';$server=new PDO($dsn,$user,$password,$options);$name='jingo_sections_test_'.bin2hex(random_bytes(6));$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db=null;
try {
 $db=new PDO($dsn.';dbname='.$name,$user,$password,$options);foreach(glob(dirname(__DIR__).'/database/*.sql') as $f)foreach(explode(';',file_get_contents($f)) as $sql)if(trim($sql)!=='')$db->exec($sql);
 $s=new TransportPlan($db);$plan=['vehicles'=>[['id'=>'car','name'=>'Car','seats'=>4]],'areas'=>[['id'=>'a','name'=>'A'],['id'=>'b','name'=>'B']],'fares'=>[],'trips'=>[],'requests'=>[]];$s->save(['version'=>0,'plan'=>$plan],'fixture');$first=$s->read();
 sectionAssert(count($first['sectionVersions'])===4,'Missing section tokens');
 $vehicles=$first['plan']['vehicles'];$vehicles[0]['name']='Changed car';$s->saveSection('vehicles',['version'=>$first['sectionVersions']['vehicles'],'rows'=>$vehicles],'fixture');
 $areas=$first['plan']['areas'];$areas[0]['name']='Changed area';$r=$s->saveSection('areas',['version'=>$first['sectionVersions']['areas'],'rows'=>$areas],'fixture');
 sectionAssert($r['plan']['vehicles'][0]['name']==='Changed car'&&$r['plan']['areas'][0]['name']==='Changed area'&&$r['version']===3,'Unrelated concurrent changes overwritten');
 sectionAssert($r['sectionVersion']===$r['sectionVersions']['areas'],'Response token mismatch');
 sectionStatus(409,fn()=>$s->saveSection('vehicles',['version'=>$first['sectionVersions']['vehicles'],'rows'=>$vehicles],'fixture'));
 $before=$s->read();$audit=(int)$db->query('SELECT COUNT(*) FROM jingo_audit')->fetchColumn();
 foreach([
  ['trips',['version'=>str_repeat('0',64),'rows'=>[]]],
  ['areas',['version'=>1,'rows'=>[]]],
  ['areas',['version'=>$before['sectionVersions']['areas'],'rows'=>[['id'=>'orphan','name'=>'Orphan','parentId'=>'missing']]]],
  ['settings',['version'=>$before['sectionVersions']['settings'],'settings'=>['areas'=>[]]]],
 ] as [$part,$input])sectionStatus(400,fn()=>$s->saveSection($part,$input,'fixture'));
 sectionAssert($s->read()===$before&&(int)$db->query('SELECT COUNT(*) FROM jingo_audit')->fetchColumn()===$audit,'Rejected save changed data/audit');
 $r=$s->saveSection('settings',['version'=>$first['sectionVersions']['settings'],'settings'=>['enabled'=>true,'note'=>'Đi / về 🐴','noteEn'=>'Transfer']],'fixture');
 sectionAssert($r['plan']['enabled']&&$r['plan']['vehicles'][0]['name']==='Changed car','Settings overwrote unrelated data');
 // Guard references in active bookings through the same locked persist path.
 $db->exec('RENAME TABLE jingo_ride_bookings TO jingo_ride_bookings_full');$db->exec('CREATE TABLE jingo_ride_bookings(visit_date CHAR(10),status VARCHAR(20),trip_id VARCHAR(80),data JSON)');
 $db->prepare('INSERT INTO jingo_ride_bookings VALUES (?,?,?,?)')->execute(['2099-01-01','CONFIRMED',null,json_encode(['vehicleId'=>'car','from'=>'a','to'=>'b'])]);
 $before=$s->read();sectionStatus(409,fn()=>$s->saveSection('vehicles',['version'=>$before['sectionVersions']['vehicles'],'rows'=>[]],'fixture'));sectionAssert($s->read()===$before,'Active booking guard failed');
 $db->exec('DROP TABLE jingo_ride_bookings');$db->exec('RENAME TABLE jingo_ride_bookings_full TO jingo_ride_bookings');
 $race=static function(array $tasks)use($name):array{$workers=[];foreach($tasks as $task){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/transport-sections-worker.php',$name,base64_encode(json_encode($task))],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$p,$pipes];}$out=[];foreach($workers as [$p,$pipes]){$out[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);sectionAssert(proc_close($p)===0,$error);}sort($out);return $out;};
 $snapshot=$s->read();$tasks=[];foreach(['vehicles','areas'] as $section){$rows=$snapshot['plan'][$section];$rows[0]['name']='Parallel '.$section;$tasks[]=['section'=>$section,'input'=>['version'=>$snapshot['sectionVersions'][$section],'rows'=>$rows]];}
 sectionAssert($race($tasks)===['OK','OK'],'Different-section race lost update');$r=$s->read();sectionAssert($r['plan']['vehicles'][0]['name']==='Parallel vehicles'&&$r['plan']['areas'][0]['name']==='Parallel areas','Concurrent values not retained');
 $tasks=[];foreach(['first','second'] as $label){$rows=$r['plan']['vehicles'];$rows[0]['name']=$label;$tasks[]=['section'=>'vehicles','input'=>['version'=>$r['sectionVersions']['vehicles'],'rows'=>$rows]];}
 sectionAssert($race($tasks)===['409','OK'],'Same-section race not rejected');
 echo "Transport sections passed: independent updates retained, stale section rejected, settings isolation, strict input, rollback/audit and active-booking guards.\n";
}finally{if($db&&$db->inTransaction())$db->rollBack();$db=null;$server->exec('DROP DATABASE '.$name);}
