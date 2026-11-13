<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_allocation_test_[a-f0-9]{12}$/D',$argv[1] ?? ''))exit(1);
foreach(['AdminAuth','Pricing','SupplyLots','SupplyAllocation'] as $class)require_once dirname(__DIR__).'/src/'.$class.'.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
try{(new SupplyAllocation($db))->allocate($argv[2],['requestId'=>$argv[3],'version'=>1,'mode'=>'AUTO'],'admin');echo 'saved';}catch(HttpError $e){if($e->status!==409)throw $e;echo 'conflict';}
