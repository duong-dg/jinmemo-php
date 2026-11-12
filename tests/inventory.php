<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
foreach(['AdminAuth','Pricing','Inventory','Products','Vouchers','Quotes'] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
function stockAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function stockStatus(int $status,callable $fn):void{try{$fn();}catch(HttpError $e){stockAssert($e->status===$status,'Unexpected HTTP '.$e->status.': '.$e->getMessage());return;}throw new RuntimeException('Expected HTTP '.$status);}
$dsn=getenv('JINGO_TEST_DSN');if(!$dsn){fwrite(STDERR,"Set JINGO_TEST_DSN without dbname.\n");exit(1);}$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];$server=new PDO($dsn,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$opts);$name='jingo_inventory_test_'.bin2hex(random_bytes(6));$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db=null;
try{
 $db=new PDO($dsn.';dbname='.$name,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$opts);$db->exec("SET time_zone='+00:00'");foreach(glob(dirname(__DIR__).'/database/*.sql') as $file)foreach(explode(';',file_get_contents($file)) as $sql)if(trim($sql)!=='')$db->exec($sql);
 $p=['id'=>'p','slug'=>'test','name'=>'Vé thử','status'=>'PUBLISHED','demo'=>false,'variants'=>[['id'=>'v','name'=>'Vé','prices'=>['ADULT'=>100,'LOCAL_CHILD'=>0],'timeSlots'=>[['id'=>'am','start'=>'09:00'],['id'=>'pm','start'=>'15:00']]]],'rules'=>[],'closures'=>[]];$db->prepare('INSERT INTO jingo_products(id,slug,data) VALUES (?,?,?)')->execute(['p','test',json_encode($p)]);
 $stock=new Inventory($db);$quotes=new Quotes($db);$version=0;$day='2099-06-01';$next='2099-06-02';
 stockAssert($stock->availability('p','v',$day,'am')['limited']===false,'Disabled stock');
 $capacity=function(string $slot,int $total,string $from='2099-06-01',string $to='2099-06-02')use($stock,&$version){$r=$stock->capacity(['productId'=>'p','version'=>$version,'variantId'=>'v','slotId'=>$slot,'startDate'=>$from,'endDate'=>$to,'capacity'=>$total,'lowStockThreshold'=>1],'admin');$version=$r['version'];};
 $capacity('',8);$capacity('am',4);$version=$stock->configure(['productId'=>'p','version'=>$version,'enabled'=>true],'admin')['version'];
 stockStatus(409,fn()=>$stock->configure(['productId'=>'p','version'=>0,'enabled'=>false],'admin'));
 stockAssert($stock->availability('p','v','2099-06-03','am')['configured']===false,'Unconfigured day');
 $input=['productId'=>'p','variantId'=>'v','date'=>$day,'slotId'=>'am','quantities'=>['ADULT'=>1,'LOCAL_CHILD'=>1]];
 stockAssert($quotes->quote($input)['total']===100,'Free child pricing');$quotes->quote($input);stockAssert((int)$db->query('SELECT COUNT(*) FROM jingo_inventory_allocations')->fetchColumn()===0,'Quotes reserved inventory');
 $ids=['00000000-0000-4000-8000-000000000001','00000000-0000-4000-8000-000000000002'];$db->beginTransaction();$stock->allocate($ids[0],'p','v',$day,'am',2);$db->commit();
 $db->beginTransaction();$stock->allocate($ids[1],'p','v',$day,'pm',3);$db->commit();
 $db->prepare("INSERT INTO jingo_inventory_allocations(order_id,product_id,variant_id,visit_date,slot_id,quantity) VALUES (?,'p','',?,'',1)")->execute(['00000000-0000-4000-8000-000000000003',$day]);
 stockAssert($stock->availability('p','v',$day,'am')['remaining']===1,'Day/slot/legacy conservative usage');stockAssert($stock->availability('p','v',$day,'pm')['remaining']===2,'Shared day pool');
 stockAssert($stock->availability('p','v',$next,'am')['remaining']===4,'Date isolation');stockStatus(409,fn()=>$quotes->quote($input));
 stockStatus(409,fn()=>$capacity('am',2));stockAssert($stock->read('p',$day,$next)['rows'][1]['capacity']===4,'Capacity failed to roll back');
 $db->beginTransaction();try{$stock->allocate('00000000-0000-4000-8000-000000000004','p','v',$day,'am',2);throw new RuntimeException('Oversell accepted');}catch(HttpError $e){$db->rollBack();stockAssert($e->status===409,'Wrong allocation error');}
 $db->beginTransaction();$stock->release($ids[0]);$stock->release($ids[0]);$db->commit();stockAssert($stock->availability('p','v',$day,'am')['remaining']===3,'Release idempotency');
 $input['quantities']=['ADULT'=>2,'LOCAL_CHILD'=>1];stockAssert($quotes->quote($input)['inventory']['remaining']===3,'Quote remaining');
 stockStatus(400,fn()=>$quotes->quote(array_merge($input,['voucherCode'=>'SALE'])));stockStatus(400,fn()=>$stock->capacity(['productId'=>'p','version'=>$version,'variantId'=>'v','startDate'=>'2099-01-01','endDate'=>'2101-01-01','capacity'=>1],'admin'));
 $capacity('',1,'2099-07-01','2099-07-01');
 $workers=[];foreach(['00000000-0000-4000-8000-000000000010','00000000-0000-4000-8000-000000000011'] as $order){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/inventory-worker.php',$name,$order],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);stockAssert(is_resource($process),'Cannot start concurrency worker');fclose($pipes[0]);$workers[]=[$process,$pipes];}
 $outcomes=[];foreach($workers as [$process,$pipes]){$outcomes[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);stockAssert(proc_close($process)===0,'Concurrency worker failed: '.$error);}sort($outcomes);stockAssert($outcomes===['allocated','conflict'],'Concurrent allocation oversold the last place');
 $stock->configure(['productId'=>'p','version'=>$version,'enabled'=>false],'admin');stockAssert($stock->availability('p','v',$day,'am')['limited']===false,'Disable limit');
 echo "PASS: shared daily/slot stock, legacy usage, free guests, non-reserving quotes, rollback, allocation/release, two-process last-place race.\n";
}finally{if($db&&$db->inTransaction())$db->rollBack();$db=null;$server->exec("DROP DATABASE `$name`");}
