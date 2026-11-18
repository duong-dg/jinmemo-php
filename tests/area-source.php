<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/AreaSource.php';
function areaAssert(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
function areaStatus(int $expected,callable $action):void {try{$action();}catch(HttpError $e){areaAssert($e->status===$expected,'Unexpected status '.$e->status);return;}throw new RuntimeException('Expected HTTP '.$expected);}
$dsn=getenv('JINGO_TEST_DSN');if(!$dsn||!preg_match('/host=([^;]+);port=(\d+)/',$dsn,$conn))exit(1);
$user=getenv('JINGO_TEST_USER')?:'root';$password=getenv('JINGO_TEST_PASSWORD')?:'';$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$user,$password,$options);$name='jingo_area_test_'.bin2hex(random_bytes(6));$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db=null;$process=null;$pipes=[];$dir=sys_get_temp_dir().'/'.$name;mkdir($dir,0700);
try {
 $db=new PDO($dsn.';dbname='.$name,$user,$password,$options);
 foreach(glob(dirname(__DIR__).'/database/*.sql') as $file)foreach(explode(';',file_get_contents($file)) as $sql)if(trim($sql)!=='')$db->exec($sql);
 $now=time();$calls=[];$responses=['v2/'=>[['code'=>1,'name'=>'Tỉnh thử','extra'=>'stripped']], 'v2/p/1?depth=2'=>['code'=>1,'name'=>'Tỉnh thử','wards'=>[['code'=>101,'name'=>'Xã thử']]],'v1/'=>[['code'=>1,'name'=>'Tỉnh thử']], 'v1/p/1?depth=2'=>['code'=>1,'name'=>'Tỉnh thử','districts'=>[['code'=>10,'name'=>'Huyện thử']]],'v1/d/10?depth=2'=>['code'=>10,'name'=>'Huyện thử','wards'=>[['code'=>1001,'name'=>'Xã cũ']]]];
 $service=new AreaSource($db,static function($path)use(&$calls,&$responses){$calls[]=$path;if(!isset($responses[$path]))throw new RuntimeException('Mock provider offline');return $responses[$path];},static function()use(&$now){return $now;});
 foreach([['province'=>[]],['version'=>'v3'],['province'=>'1&depth=3'],['province'=>'http://localhost'],['district'=>'10'],['province'=>'1','district'=>'10','version'=>'v2'],['extra'=>'x']] as $query)areaStatus(400,fn()=>$service->load($query));
 areaAssert(!$calls,'Invalid params reached provider');
 areaAssert($service->load([],true)['data']===[['code'=>1,'name'=>'Tỉnh thử']],'Unit metadata leaked');
 $areas=$service->load(['province'=>'1'])['data'];areaAssert($areas[0]['id']==='vn-v2-p-1'&&$areas[1]['parentId']==='vn-v2-p-1'&&$areas[1]['id']==='vn-v2-w-101','V2 tree');
 areaAssert($service->load(['province'=>'1'],true)['data']===[['code'=>101,'name'=>'Xã thử']],'Gift wards');
 areaAssert(count($calls)===2,'Cache not shared across gift/admin');
 $old=$service->load(['version'=>'v1','province'=>'1','district'=>'10'])['data'];areaAssert(count($old)===3&&$old[1]['id']==='vn-v1-d-10'&&$old[2]['parentId']==='vn-v1-d-10'&&$old[2]['name']==='Xã cũ (cũ)','V1 tree');
 areaStatus(400,fn()=>$service->load(['version'=>'v1','province'=>'1','district'=>'11']));areaAssert(!in_array('v1/d/11?depth=2',$calls,true),'Wrong district fetched');
 $before=count($calls);$now+=86399;$service->load(['province'=>'1']);areaAssert(count($calls)===$before,'24h cache expired early');$now+=1;$service->load(['province'=>'1']);areaAssert(count($calls)===$before+1,'Cache did not expire');
 // Bad/unavailable provider is rate backed off and cannot change saved settings.
 $responses['v2/p/2?depth=2']=['code'=>2,'name'=>'Bad','wards'=>[['code'=>0,'name'=>'Bad']]];
 areaStatus(503,fn()=>$service->load(['province'=>'2']));$before=count($calls);areaStatus(503,fn()=>$service->load(['province'=>'2']));areaAssert(count($calls)===$before,'Failed upstream hammered');
 $now+=31;$responses['v2/p/2?depth=2']=['code'=>2,'name'=>'Recovered','wards'=>[]];areaAssert($service->load(['province'=>'2'])['data'][0]['name']==='Recovered','Provider retry failed');
 $responses['v2/p/3?depth=2']=['code'=>4,'name'=>'Mismatched','wards'=>[]];areaStatus(503,fn()=>$service->load(['province'=>'3']));
 areaAssert((int)$db->query('SELECT COUNT(*) FROM jingo_settings')->fetchColumn()===0,'Address load changed settings');
 // Hard cache bound, including cold/failed requests.
 for($n=10;$n<145;$n++){ $responses['v2/p/'.$n.'?depth=2']=['code'=>$n,'name'=>'Fixture','wards'=>[]];$service->load(['province'=>(string)$n]); }
 areaAssert((int)$db->query('SELECT COUNT(*) FROM jingo_area_source_cache')->fetchColumn()===128,'Cache unbounded');
 // Seed current-time cache so real HTTP tests never call the public provider.
 $now=time();$db->exec('DELETE FROM jingo_area_source_cache');$service->load([],true);$service->load(['province'=>'1'],true);
 $token=bin2hex(random_bytes(32));$db->prepare('INSERT INTO jingo_admins(id,email,password_hash) VALUES (?,?,?)')->execute(['area-admin','area@example.test',password_hash('fixture-secret',PASSWORD_BCRYPT)]);$db->prepare('INSERT INTO jingo_sessions VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))')->execute([hash('sha256',$token),'area-admin']);
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$err);$address=stream_socket_get_name($socket,false);fclose($socket);$base='http://'.$address;$root=dirname(__DIR__);
 $env=array_merge(getenv(),['APP_ENV'=>'development','PUBLIC_BASE_URL'=>$base,'DB_HOST'=>$conn[1],'DB_PORT'=>$conn[2],'DB_DATABASE'=>$name,'DB_USERNAME'=>$user,'DB_PASSWORD'=>$password]);
 $process=proc_open([PHP_BINARY,'-d','display_errors=0','-S',$address,'-t',$root.'/public',$root.'/public/index.php'],[['pipe','r'],['file',$dir.'/out','a'],['file',$dir.'/err','a']],$pipes,$root,$env);
 for($n=0;$n<40;$n++){$s=@stream_socket_client('tcp://'.$address,$errno,$err,0.1);if($s){fclose($s);break;}usleep(50000);}
 $http=static function(string $path,int $expected,string $method='GET',bool $admin=false)use($base,$token):array{$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Connection: close',...($admin?['Cookie: jingo_admin='.$token]:[])],CURLOPT_TIMEOUT=>5]);$raw=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$len=curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);areaAssert($code===$expected,'HTTP '.$path.' '.$code.' '.$raw);return ['headers'=>substr($raw,0,$len),'body'=>json_decode(substr($raw,$len),true)];};
 $http('/api/v1/admin/ride-areas',401);$r=$http('/api/v1/admin/ride-areas?province=1',200,'GET',true);areaAssert($r['body']['data'][1]['id']==='vn-v2-w-101','Admin endpoint');
 $r=$http('/api/v1/gifts/addresses?province=1',200);areaAssert($r['body']['data'][0]['code']===101&&str_contains(strtolower($r['headers']),'cache-control: no-store'),'Public address endpoint');
 $http('/api/v1/gifts/addresses?province[]=1',400);$http('/api/v1/gifts/addresses',405,'POST');$http('/api/v1/admin/ride-areas',405,'POST',true);
 $q=$db->prepare('UPDATE jingo_order_limits SET attempts=60 WHERE id=?');$q->execute([hash('sha256','gift-addresses:127.0.0.1')]);$r=$http('/api/v1/gifts/addresses',429);areaAssert(str_contains($r['headers'],'Retry-After: 60'),'Rate limit headers');
 echo "Area source passed: v1/v2 trees, shared cache/TTL/cap/backoff, validation, existing settings untouched, private admin and public HTTP/rate limits. No live upstream calls.\n";
}finally{if(is_resource($process)){proc_terminate($process);foreach($pipes as $p)fclose($p);proc_close($process);}if($db&&$db->inTransaction())$db->rollBack();$db=null;$server->exec('DROP DATABASE '.$name);foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
