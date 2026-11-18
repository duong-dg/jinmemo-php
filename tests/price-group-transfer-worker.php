<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_group_target_[a-f0-9]{12}_race$/D',$argv[1]??'')||!preg_match('/^jingo_group_source_[a-f0-9]{12}$/D',$argv[2]??''))exit(1);
require dirname(__DIR__).'/src/CatalogTransfer.php';$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$r=(new CatalogTransfer($db,$argv[3],true,true))->run($argv[2],true,$argv[4]);if(!$r['ready'])exit(2);echo array_sum(array_column($r['counts'],'insertedRows'));
