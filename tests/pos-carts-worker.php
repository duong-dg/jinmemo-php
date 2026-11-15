<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_pos_carts_test_[a-f0-9]{12}$/D',$argv[1]??''))exit(1);
foreach(['AdminAuth','Pricing','Inventory','Products','Vouchers','Quotes','Orders','Carts','Shifts'] as $c)require_once dirname(__DIR__).'/src/'.$c.'.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$task=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
try{if(isset($task['create']))echo json_encode((new Carts($db))->create($task['create'],null,$task['actor']));elseif(isset($task['close'])){(new Shifts($db))->act($task['close'],'close',$task['body'],$task['actor']);echo 'OK';}else{(new Carts($db))->pay($task['code'],$task['pay'],'pos-user:'.$task['actor']['id'],$task['actor']);echo 'OK';}}catch(HttpError $e){echo $e->status;}
