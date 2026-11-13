<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_pos_test_[a-f0-9]{12}$/D',$argv[1] ?? ''))exit(1);
foreach(['AdminAuth','Pricing','Products','Inventory','Vouchers','Orders','Shifts'] as $class)require_once dirname(__DIR__).'/src/'.$class.'.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$body=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
try{if($argv[3]==='create')echo (new Orders($db))->createPos($body,'admin')['code'];elseif($argv[3]==='web')echo (new Orders($db))->create($body)['code'];elseif($argv[3]==='pay')echo (new Orders($db))->act($argv[4],$body,'admin')['paymentStatus'];else{(new Shifts($db))->act($argv[4],'close',$body,['id'=>'admin','email'=>'admin@example.test']);echo 'closed';}}catch(HttpError $e){if($e->status!==409)throw $e;echo 'conflict';}
