<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_rides_test_[a-f0-9]{12}$/D',$argv[1]??''))exit(1);
require_once dirname(__DIR__).'/src/AdminAuth.php';require_once dirname(__DIR__).'/src/RideCash.php';require_once dirname(__DIR__).'/src/Shifts.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$in=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
try{echo json_encode(isset($in['shift'])?(new Shifts($db))->act($in['shift'],'close',$in['body'],$in['actor']):(new RideCash($db))->collect($in['body'],$in['actor']));}catch(HttpError $e){echo $e->status;}
