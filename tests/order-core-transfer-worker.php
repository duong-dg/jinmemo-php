<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_core_target_[a-f0-9]{12}_race$/D',$argv[1]??'')||!preg_match('/^jingo_core_source_[a-f0-9]{12}$/D',$argv[2]??''))exit(1);
require dirname(__DIR__).'/src/OrderCoreTransfer.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$r=(new OrderCoreTransfer($db))->run($argv[2],true,$argv[3]);if(!$r['ready']){fwrite(STDERR,json_encode($r['errors']));exit(2);}echo $r['counts']['insertedRows'];
