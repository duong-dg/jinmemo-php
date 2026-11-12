<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/src/AdminAuth.php';require dirname(__DIR__).'/src/Pricing.php';require dirname(__DIR__).'/src/Inventory.php';
$database=$argv[1] ?? '';if(!preg_match('/^jingo_inventory_test_[a-f0-9]{12}$/D',$database))exit(2);
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$database,getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->beginTransaction();try{(new Inventory($db))->allocate($argv[2],'p','v','2099-07-01','am',1);$db->commit();echo 'allocated';}catch(HttpError $e){$db->rollBack();if($e->status!==409)throw $e;echo 'conflict';}
