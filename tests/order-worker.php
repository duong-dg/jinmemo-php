<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
foreach(['AdminAuth','Pricing','Inventory','Products','Vouchers','Orders'] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
$database=$argv[1] ?? '';if(!preg_match('/^jingo_orders_test_[a-f0-9]{12}$/D',$database))exit(2);
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$database,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$input=['requestId'=>$argv[2],'accessKey'=>str_repeat('c',64),'productId'=>'p','variantId'=>'v','date'=>'2099-07-01','quantities'=>['ADULT'=>1],'expectedTotal'=>500,'contact'=>['name'=>'Race test','email'=>'race@example.test','phone'=>'0901234567'],'accepted'=>true];
try{echo json_encode((new Orders($db))->create($input));}catch(HttpError $e){if($e->status!==409)throw $e;echo 'conflict';}
