<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/src/OrderHistoryTransfer.php';
if(!preg_match('/^jingo_oht_[a-f0-9]{12}$/D',$argv[1]??''))exit(1);
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$r=(new OrderHistoryTransfer($db))->run($argv[2],true,$argv[3]);echo $r['applied']?'APPLIED':'BLOCKED';
