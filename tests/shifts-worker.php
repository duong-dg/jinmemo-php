<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_shifts_test_[a-f0-9]{12}$/D',$argv[1] ?? ''))exit(1);
require dirname(__DIR__).'/src/AdminAuth.php';require dirname(__DIR__).'/src/Shifts.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$body=json_decode(base64_decode($argv[2]),true);$s=new Shifts($db);$actor=['id'=>'admin','email'=>'admin@example.test'];try{if($argv[3]==='open')$s->open($body,$actor);else $s->act($argv[4],$argv[3],$body,$actor);echo 'saved';}catch(HttpError $e){if($e->status!==409)throw $e;echo 'conflict';}
