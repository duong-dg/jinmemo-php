<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/AdminAuth.php';require dirname(__DIR__).'/src/Rides.php';
function exportCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function exportStatus(int $code,callable $fn):void{try{$fn();}catch(HttpError $e){exportCheck($e->status===$code,'Wrong status '.$e->status);return;}throw new RuntimeException('Expected '.$code);}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/ride-export-node.json'),true,512,JSON_THROW_ON_ERROR);exportCheck(RideExport::csv($fixture['rows'])===$fixture['csv'],'CSV differs from original Node');exportCheck(!str_contains($fixture['csv'],'NEVER_EXPORT'),'Secret exported');
$dsn=getenv('JINGO_TEST_DSN');if(!$dsn)exit(1);$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];$user=getenv('JINGO_TEST_USER')?:'root';$pass=getenv('JINGO_TEST_PASSWORD')?:'';$server=new PDO($dsn,$user,$pass,$opts);$name='jingo_ride_export_'.bin2hex(random_bytes(6));$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db=null;
try{
 $db=new PDO($dsn.';dbname='.$name,$user,$pass,$opts);foreach(glob(dirname(__DIR__).'/database/*.sql') as $file)foreach(explode(';',file_get_contents($file)) as $sql)if(trim($sql)!=='')$db->exec($sql);$service=new RideExport($db);
 $insert=$db->prepare('INSERT INTO jingo_ride_bookings(id,code,request_key,visit_date,status,data) VALUES (?,?,?,?,?,?)');
 foreach($fixture['rows'] as $i=>$r){$id=sprintf('00000000-0000-4000-8000-%012d',$i);$insert->execute([$id,$r['code'],$id,$r['visit_date'],$r['status'],json_encode($r['data'])]);}
 $out=$service->run([],'fixture');exportCheck($out['count']===8&&$out['csv']===$fixture['csv'],'DB export ordering/CSV');
 exportCheck($service->run(['status'=>'CANCELLED'],'fixture')['count']===4,'Status filter');exportCheck($service->run(['date'=>'2099-06-02'],'fixture')['count']===0,'Date filter');exportCheck($service->run(['q'=>'  HỘI AN  '],'fixture')['count']===1,'Case-insensitive name search');exportCheck($service->run(['q'=>'012345'],'fixture')['count']===8,'Phone search');exportCheck($service->run(['q'=>'EXAMPLE.TEST'],'fixture')['count']===8,'Email search');exportCheck($service->run(['q'=>'000007'],'fixture')['count']===1,'Code search');
 foreach(['%','_','\' OR 1=1 --'] as $q)exportCheck($service->run(['q'=>$q],'fixture')['count']===0,'Search treated as pattern/SQL');
 foreach([['q'=>[]],['q'=>str_repeat('x',101)],['date'=>'2099-02-30'],['status'=>'BAD'],['dispatch'=>'all']] as $bad)exportStatus(400,fn()=>$service->run($bad,'fixture'));
 // 10,000 is accepted; the next row must fail without recording a completed export.
 $db->beginTransaction();for($i=8;$i<10000;$i++){$id=sprintf('00000000-0000-4000-8000-%012d',$i);$insert->execute([$id,'JM-X'.sprintf('%012X',$i),$id,'2099-07-01','PENDING','{}']);}$db->commit();exportCheck($service->run([],'fixture')['count']===10000,'10k export rejected');$insert->execute(['00000000-0000-4000-8000-000000010000','JM-X000000002710','00000000-0000-4000-8000-000000010000','2099-07-01','PENDING','{}']);$before=(int)$db->query("SELECT COUNT(*) FROM jingo_audit WHERE action='ride.export'")->fetchColumn();exportStatus(400,fn()=>$service->run([],'fixture'));exportCheck((int)$db->query("SELECT COUNT(*) FROM jingo_audit WHERE action='ride.export'")->fetchColumn()===$before,'Failed export audited as completed');
 echo "Ride export passed: original Node CSV bytes, formula protection, phone/Unicode/zero cost, literal search, filter validation, stable ordering, 10k cap and audit.\n";
}finally{if($db&&$db->inTransaction())$db->rollBack();$db=null;$server->exec('DROP DATABASE '.$name);}
