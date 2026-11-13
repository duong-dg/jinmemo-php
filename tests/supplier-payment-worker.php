<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_supplierpay_test_[a-f0-9]{12}$/D',$argv[1] ?? ''))exit(1);
require dirname(__DIR__).'/src/AdminAuth.php';require dirname(__DIR__).'/src/SupplierPayments.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$input=json_decode(base64_decode($argv[2],true),true,512,JSON_THROW_ON_ERROR);try{(new SupplierPayments($db))->pay($input,'admin');echo 'paid';}catch(HttpError $e){if(!in_array($e->status,[400,409],true))throw $e;echo $e->status===400?'overpaid':'conflict';}
