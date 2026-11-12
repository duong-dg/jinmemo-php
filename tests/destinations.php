<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/Destinations.php';
require dirname(__DIR__).'/src/Media.php';
$dsn=getenv('JINGO_TEST_DSN');
if(!$dsn) { fwrite(STDERR,"Set JINGO_TEST_DSN to a disposable server without dbname.\n"); exit(1); }
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$opts);
$name='jingo_destination_test_'.bin2hex(random_bytes(6)); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$db=null;
function checkDestination(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function destinationStatus(int $expected,callable $fn): void { try{$fn();}catch(HttpError $e){checkDestination($e->status===$expected,'HTTP '.$e->status.' expected '.$expected);return;} throw new RuntimeException('Expected HTTP '.$expected); }
function fixture(int $n,?string $parent=null): array { return ['id'=>sprintf('00000000-0000-4000-8000-%012d',$n),'slug'=>'place-'.$n,'englishSlug'=>'destination-'.$n,'name'=>'Điểm đến '.$n,'englishName'=>'Place '.$n,'parentId'=>$parent,'status'=>'ACTIVE','version'=>0]; }
try {
    $db=new PDO($dsn.';dbname='.$name,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$opts);
    foreach(glob(dirname(__DIR__).'/database/*.sql') as $f) foreach(explode(';',file_get_contents($f)) as $sql) if(trim($sql)!=='') $db->exec($sql);
    $service=new Destinations($db); $root=$service->save(fixture(1),'admin'); $child=$service->save(fixture(2,$root['id']),'admin');
    checkDestination(count($service->list())===2,'Tree list');
    checkDestination($service->find('destination-2','en')['name']==='Place 2','English detail');
    checkDestination(!isset($service->find('place-1')['version']),'Private version');
    destinationStatus(400,fn()=>$service->save(array_merge($root,['parentId'=>$child['id']]),'admin'));
    destinationStatus(400,fn()=>$service->save(array_merge($root,['status'=>'ARCHIVED']),'admin'));
    destinationStatus(400,fn()=>$service->save(fixture(3,'00000000-0000-4000-8000-000000000099'),'admin'));
    destinationStatus(400,fn()=>$service->save(array_merge(fixture(3),['coordinates'=>['lat'=>91,'lon'=>0]]),'admin'));
    destinationStatus(400,fn()=>$service->save(array_merge(fixture(3),['cover'=>['id'=>$root['id'],'alt'=>'Ảnh']]),'admin'));
    $stale=$child; $child=$service->save(array_merge($child,['slug'=>'new-place','englishSlug'=>'new-destination','coordinates'=>['lat'=>16.05,'lon'=>108.2]]),'admin');
    destinationStatus(409,fn()=>$service->save($stale,'admin'));
    checkDestination($service->find('place-2')['slug']==='new-place','Vietnamese alias');
    checkDestination($service->find('destination-2','en')['slug']==='new-destination','English alias');
    $preserve=$child; unset($preserve['coordinates'],$preserve['cover']); $child=$service->save($preserve,'admin');
    checkDestination($child['coordinates']['lat']===16.05,'Omitted coordinates lost');
    destinationStatus(409,fn()=>$service->save(array_merge(fixture(3),['englishSlug'=>'destination-2']),'admin'));
    $db->prepare('INSERT INTO jingo_products(id,slug,data) VALUES (?,?,?)')->execute(['p','p',json_encode(['destinationId'=>$child['id']])]);
    destinationStatus(409,fn()=>$service->save(array_merge($child,['status'=>'ARCHIVED']),'admin'));
    $db->exec('DELETE FROM jingo_products');
    $child=$service->save(array_merge($child,['status'=>'ARCHIVED']),'admin');
    destinationStatus(404,fn()=>$service->find('place-2'));
    $root=$service->save(array_merge($root,['status'=>'ARCHIVED']),'admin');
    checkDestination(count($service->list())===0 && count($service->list(true))===2,'Archived tree');
    $root=$service->save(array_merge($root,['status'=>'ACTIVE']),'admin');
    $parent=$root['id'];
    for($i=3;$i<=11;$i++) { $n=$service->save(fixture($i,$parent),'admin'); $parent=$n['id']; }
    destinationStatus(400,fn()=>$service->save(fixture(12,$parent),'admin'));
    echo "PASS: tree, cycles, 10-level limit, parents, archive references, coordinates, media blocking, aliases, versions, localization.\n";
} finally { $db=null; $server->exec("DROP DATABASE `$name`"); }
