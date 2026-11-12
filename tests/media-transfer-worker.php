<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/src/MediaTransfer.php';foreach([$argv[1] ?? '',$argv[2] ?? ''] as $name)if(!preg_match('/^jingo_media_transfer_(?:source|target)_[a-f0-9]{12}(?:_race)?$/D',$name))exit(2);
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");$report=(new MediaTransfer($db,$argv[3]))->run($argv[2],true);if(!$report['ready'])throw new RuntimeException('Worker preflight failed.');echo $report['counts']['insertedRows'];
