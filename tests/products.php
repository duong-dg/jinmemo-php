<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
foreach(['AdminAuth','Pricing','ContentHtml','Media','ProductTypes','Destinations','ProductSchema','Products','Suppliers'] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
function productCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function productStatus(int $status,callable $work):void{try{$work();}catch(HttpError $e){productCheck($e->status===$status,'Unexpected HTTP '.$e->status.': '.$e->getMessage());return;}throw new RuntimeException('Expected HTTP '.$status);}
function normalizeProduct(mixed $v):mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);foreach($v as &$x)$x=normalizeProduct($x);return $v;}
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/product-schema-node.json'),true,512,JSON_THROW_ON_ERROR);
foreach($fixtures as $case){try{$result=ProductSchema::validate($case['input']);productCheck($case['valid'],$case['name'].': accepted invalid input');productCheck(normalizeProduct($result)===normalizeProduct($case['expected']),$case['name'].': normalized schema differs');productCheck(Products::sourceHash($result)===$case['hash'],$case['name'].': source hash differs');}catch(HttpError $e){productCheck(!$case['valid'],$case['name'].': rejected valid input: '.$e->getMessage());}}
$dsn=getenv('JINGO_TEST_DSN');if(!$dsn){fwrite(STDERR,"Schema parity passed; set JINGO_TEST_DSN for database tests.\n");exit(1);}
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];$server=new PDO($dsn,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$opts);$name='jingo_products_test_'.bin2hex(random_bytes(6));$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db=null;
try{
 $db=new PDO($dsn.';dbname='.$name,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',$opts);foreach(glob(dirname(__DIR__).'/database/*.sql') as $file)foreach(explode(';',file_get_contents($file)) as $sql)if(trim($sql)!=='')$db->exec($sql);
 $types=new ProductTypes($db);$type=$types->save(['id'=>'ticket','slug'=>'ve','name'=>'Vé','status'=>'ACTIVE','bookingKind'=>'TICKET','version'=>0],'admin');$products=new Products($db);$input=$fixtures[0]['input'];
 $p=$products->save($input,'admin');productCheck($p['version']===1,'Creation version');productStatus(404,fn()=>$products->find('ve-thu',true));productStatus(409,fn()=>$products->save($input,'admin'));
 productStatus(400,fn()=>$products->save(array_merge($p,['type'=>'missing']),'admin'));productStatus(400,fn()=>$products->save(array_merge($p,['cover'=>['id'=>'00000000-0000-4000-8000-000000000099','alt'=>'Missing']]),'admin'));
 $p['status']='PUBLISHED';$p['onlineDiscount']=['type'=>'PERCENT','value'=>15];$p['supplierId']='00000000-0000-4000-8000-000000000001';
 $supplier=(new Suppliers($db))->save(['id'=>$p['supplierId'],'name'=>'Nguồn vé','status'=>'ACTIVE','version'=>0],'admin');$p=$products->save($p,'admin');$public=$products->publicView($products->find('ve-thu',true));
 productCheck(!array_intersect(['supplierId','rules','english','version'],array_keys($public))&&$public['variants'][0]['prices']['ADULT']===510000,'Public pricing/privacy');
 productStatus(409,fn()=>$types->save(array_merge($type,['status'=>'ARCHIVED']),'admin'));unset($supplier['productCount']);productStatus(409,fn()=>(new Suppliers($db))->save(array_merge($supplier,['status'=>'ARCHIVED']),'admin'));
 $destinations=new Destinations($db);$destination=$destinations->save(['id'=>'00000000-0000-4000-8000-000000000002','slug'=>'da-nang','name'=>'Đà Nẵng chuẩn','parentId'=>null,'status'=>'ACTIVE','version'=>0],'admin');
 $p['destinationId']=$destination['id'];$p['destination']='Nhãn cũ';$p=$products->save($p,'admin');productCheck($p['destination']==='Đà Nẵng chuẩn','Destination normalization');productStatus(409,fn()=>$destinations->save(array_merge($destination,['status'=>'ARCHIVED']),'admin'));
 $p['english']=['name'=>'Test ticket','destination'=>'Da Nang','description'=>'Local experience','detailHtml'=>'','seoTitle'=>'','seoDescription'=>'','variants'=>[['id'=>'eco','name'=>'Eco']],'status'=>'PUBLISHED','sourceHash'=>Products::sourceHash($p)];$p=$products->save($p,'admin');productCheck($products->publicView($products->find('ve-thu',true,false,'en'),'en')['name']==='Test ticket','English publication');
 $p['description']='Nội dung đã đổi';$p['slug']='ve-moi';$p=$products->save($p,'admin');productCheck($p['english']['status']==='NEEDS_REVIEW','Translation review');productCheck($products->find('ve-thu',true)['slug']==='ve-moi','Alias');productStatus(404,fn()=>$products->find('ve-moi',true,false,'en'));
 $other=$input;$other['id']='other';productStatus(409,fn()=>$products->save($other,'admin'));
 $p=$products->archive($p['id'],['version'=>$p['version'],'archived'=>true],'admin');productCheck($products->list()===[],'Archive visibility');$p=$products->archive($p['id'],['version'=>$p['version'],'archived'=>false],'admin');productStatus(404,fn()=>$products->find('ve-moi',true));
 echo 'PASS: '.count($fixtures)." Node schema/hash fixtures; product CRUD, versions, references, media, privacy, online prices, aliases, English review, archive/restore.\n";
}finally{$db=null;$server->exec("DROP DATABASE `$name`");}
