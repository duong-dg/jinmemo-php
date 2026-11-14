<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_partner_orders_test_[a-f0-9]{12}$/D',$argv[1] ?? ''))exit(1);
foreach(['AdminAuth','Pricing','Products','Inventory','Vouchers','PartnerOrders'] as $c)require_once dirname(__DIR__).'/src/'.$c.'.php';$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");[$actor,$input]=json_decode(base64_decode($argv[2],true),true,512,JSON_THROW_ON_ERROR);try{echo (new PartnerOrders($db))->create($actor,$input)['code'];}catch(HttpError $e){if($e->status!==409)throw $e;echo 'conflict';}
