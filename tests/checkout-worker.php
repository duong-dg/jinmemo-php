<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_checkout_test_[a-f0-9]{12}$/D',$argv[1]??''))exit(1);
foreach(['AdminAuth','Pricing','Inventory','Products','Vouchers','Quotes','Orders','CheckoutBundles'] as $c)require_once dirname(__DIR__).'/src/'.$c.'.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$task=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
try{if(isset($task['orderPay'])){(new Orders($db))->act($task['id'],$task['orderPay'],'admin');echo 'OK';}elseif(isset($task['create']))echo json_encode((new CheckoutBundles($db))->create($task['create']));else{(new CheckoutBundles($db))->collect($task['collect'],'admin');echo 'OK';}}catch(HttpError $e){echo $e->status;}
