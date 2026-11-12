<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/ProductTypes.php';
$dsn=getenv('JINGO_TEST_DSN');
if(!$dsn) { fwrite(STDERR,"Set JINGO_TEST_DSN to a disposable MySQL server without dbname.\n"); exit(1); }
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$options);
$name='jingo_types_test_'.bin2hex(random_bytes(6)); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
function verify(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function status(int $expected,callable $fn): void {
    try { $fn(); } catch(HttpError $e) { verify($e->status===$expected,'Unexpected HTTP '.$e->status); return; }
    throw new RuntimeException('Expected HTTP '.$expected);
}
$db=null;
try {
    $db=new PDO($dsn.';dbname='.$name,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$options);
    for($i=0;$i<2;$i++) foreach(glob(dirname(__DIR__).'/database/*.sql') as $file) foreach(explode(';',file_get_contents($file)) as $sql) if(trim($sql)!=='') $db->exec($sql);
    $service=new ProductTypes($db);
    $input=['id'=>'tour','slug'=>'tour-da-nang','name'=>'Tour Đà Nẵng','description'=>'Khám phá / biển 🌊','bookingKind'=>'TOUR','status'=>'ACTIVE','version'=>0];
    $type=$service->save($input,'admin-test'); verify($type['version']===1,'Create version');
    verify(count($service->list())===1,'Public list');
    verify(!isset($service->list()[0]['version']),'Public version leaked');
    verify($service->list(false,'en')===[],'Untranslated English visible');
    status(400,fn()=>$service->save(array_merge($input,['extra'=>true]),'admin-test'));
    status(400,fn()=>$service->save(array_merge($input,['version'=>'1']),'admin-test'));
    status(409,fn()=>$service->save($input,'admin-test'));
    $type['english']=['name'=>'Da Nang tour','description'=>'Explore the coast','status'=>'PUBLISHED','sourceHash'=>ProductTypes::sourceHash($type)];
    $type=$service->save($type,'admin-test');
    verify($service->find('tour-da-nang','en')['name']==='Da Nang tour','English publication');
    $stale=$type; $type['slug']='tour-moi'; $type=$service->save($type,'admin-test');
    verify($service->find('tour-da-nang')['slug']==='tour-moi','Old slug alias');
    status(409,fn()=>$service->save($stale,'admin-test'));
    $other=array_merge($input,['id'=>'other','slug'=>'tour-da-nang']);
    status(409,fn()=>$service->save($other,'admin-test'));
    $other['slug']='tour-moi'; status(409,fn()=>$service->save($other,'admin-test'));
    $type['name']='Nội dung mới'; $type=$service->save($type,'admin-test');
    verify($type['english']['status']==='NEEDS_REVIEW','Translation not invalidated');
    status(404,fn()=>$service->find('tour-moi','en'));
    $db->prepare('INSERT INTO jingo_products(id,slug,data) VALUES (?,?,?)')->execute(['p1','p1',json_encode(['type'=>'tour'])]);
    status(409,fn()=>$service->save(array_merge($type,['status'=>'ARCHIVED']),'admin-test'));
    verify($service->find('tour-moi')['status']==='ACTIVE','Archive failure did not roll back');
    $db->exec('DELETE FROM jingo_products');
    $type=$service->save(array_merge($type,['status'=>'ARCHIVED']),'admin-test');
    verify($service->list()===[] && count($service->list(true))===1,'Archive visibility');
    status(404,fn()=>$service->find('tour-da-nang'));
    $type=$service->save(array_merge($type,['status'=>'ACTIVE','slug'=>'tour-da-nang']),'admin-test');
    verify($service->find('tour-moi')['slug']==='tour-da-nang','Reusing own alias');
    verify((int)$db->query('SELECT COUNT(*) FROM jingo_audit')->fetchColumn()===6,'Audit count or rollback');
    echo "PASS: create/update, strict validation, version conflicts, aliases, duplicate slugs, English review, archive dependency, rollback, audit, repeatable schema.\n";
} finally { $db=null; $server->exec("DROP DATABASE `$name`"); }
