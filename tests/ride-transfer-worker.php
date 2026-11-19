<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/RideTransfer.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$r=(new RideTransfer($db))->run($argv[2],true,$argv[3],in_array($argv[4]??'',['paid-qr','paid-refunds'],true),($argv[4]??'')==='paid-refunds',($argv[4]??'')==='closed-pools');echo $r['applied']?'APPLIED':'BLOCKED';
