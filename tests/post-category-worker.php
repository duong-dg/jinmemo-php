<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
foreach(['AdminAuth','PostCategories'] as $class)require dirname(__DIR__).'/src/'.$class.'.php';$name=$argv[1] ?? '';if(!preg_match('/^jingo_postactions_test_[a-f0-9]{12}$/D',$name))exit(2);
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$name,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");try{(new PostCategories($db))->act('TRANSLATE',['name'=>base64_decode($argv[2]),'version'=>(int)$argv[3],'englishName'=>$argv[4]],'admin');echo 'saved';}catch(HttpError $e){if($e->status!==409)throw $e;echo 'conflict';}
