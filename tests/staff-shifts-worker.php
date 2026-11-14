<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_staff_shifts_test_[a-f0-9]{12}$/D',$argv[1] ?? ''))exit(1);
require dirname(__DIR__).'/src/AdminAuth.php';require dirname(__DIR__).'/src/Shifts.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);[$body,$actor]=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);$service=new Shifts($db);try{if($argv[2]==='open')$service->open($body,$actor);else $service->act($argv[4],$argv[2],$body,$actor);echo 'saved';}catch(HttpError $e){if($e->status!==409)throw $e;echo 'conflict';}
