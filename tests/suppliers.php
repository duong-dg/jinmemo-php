<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/Suppliers.php';
$dsn=getenv('JINGO_TEST_DSN'); if(!$dsn) { fwrite(STDERR,"Set JINGO_TEST_DSN without dbname.\n"); exit(1); }
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$options);
$name='jingo_supplier_test_'.bin2hex(random_bytes(6)); $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4"); $db=null;
function assertSupplier(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function supplierStatus(int $status,callable $fn): void { try{$fn();}catch(HttpError $e){assertSupplier($e->status===$status,'Unexpected HTTP '.$e->status);return;} throw new RuntimeException('Expected HTTP '.$status); }
try {
    $db=new PDO($dsn.';dbname='.$name,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$options);
    for($i=0;$i<2;$i++) foreach(glob(dirname(__DIR__).'/database/*.sql') as $f) foreach(explode(';',file_get_contents($f)) as $sql) if(trim($sql)!=='') $db->exec($sql);
    $service=new Suppliers($db); $id='00000000-0000-4000-8000-000000000001';
    $input=['id'=>$id,'name'=>' Nhà cung cấp A ','status'=>'ACTIVE','version'=>0,'email'=>'supplier@example.com','website'=>'https://example.com','phone'=>'+84 (123) 456-789'];
    $saved=$service->save($input,'admin'); assertSupplier($saved['name']==='Nhà cung cấp A' && $saved['version']===1 && $saved['productCount']===0,'Creation');
    supplierStatus(409,fn()=>$service->save($input,'admin'));
    supplierStatus(400,fn()=>$service->save(array_merge($input,['website'=>'javascript:alert(1)']),'admin'));
    supplierStatus(400,fn()=>$service->save(array_merge($input,['email'=>'invalid']),'admin'));
    supplierStatus(400,fn()=>$service->save(array_merge($input,['phone'=>'abc']),'admin'));
    supplierStatus(400,fn()=>$service->save(array_merge($input,['version'=>'1']),'admin'));
    supplierStatus(400,fn()=>$service->save($saved,'admin')); // productCount is read-only, same as original schema.
    unset($saved['productCount']); $stale=$saved;
    $saved=$service->save(array_merge($saved,['notes'=>'Đối tác vé']),'admin'); unset($saved['productCount']);
    supplierStatus(409,fn()=>$service->save($stale,'admin'));
    $db->prepare('INSERT INTO jingo_products(id,slug,data) VALUES (?,?,?)')->execute(['p','p',json_encode(['supplierId'=>$id])]);
    assertSupplier($service->list()[0]['productCount']===1,'Product count');
    supplierStatus(409,fn()=>$service->save(array_merge($saved,['status'=>'ARCHIVED']),'admin'));
    $db->exec("UPDATE jingo_products SET data='{}'");
    $db->prepare("INSERT INTO jingo_supply_lots(id,request_hash,supplier_id,product_id,variant_id,customer_type,unit_cost,total_quantity,valid_from,valid_to,data) VALUES (?, 'fixture', ?, 'p','v','ADULT',100,10,'2026-01-01','2026-12-31','{}')")->execute(['00000000-0000-4000-8000-000000000002',$id]);
    supplierStatus(409,fn()=>$service->save(array_merge($saved,['status'=>'ARCHIVED']),'admin'));
    $db->exec('UPDATE jingo_supply_lots SET enabled=0');
    $saved=$service->save(array_merge($saved,['status'=>'ARCHIVED']),'admin'); unset($saved['productCount']);
    $saved=$service->save(array_merge($saved,['status'=>'ACTIVE']),'admin');
    assertSupplier($saved['status']==='ACTIVE' && (int)$db->query('SELECT COUNT(*) FROM jingo_audit')->fetchColumn()===4,'Restore/audit/rollback');
    echo "PASS: suppliers validation, create/update, conflicts, product counts, archive guards, restore, audit, repeatable schema.\n";
} finally { $db=null; $server->exec("DROP DATABASE `$name`"); }
