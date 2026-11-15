<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_transport_plan_test_[a-f0-9]{12}$/D',$argv[1]??''))exit(1);
require_once dirname(__DIR__).'/src/AdminAuth.php';require_once dirname(__DIR__).'/src/TransportPlan.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
try{(new TransportPlan($db))->save(json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR),'admin');echo 'OK';}catch(HttpError $e){echo $e->status;}
