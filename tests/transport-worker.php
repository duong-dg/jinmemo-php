<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_transport_test_[a-f0-9]{12}$/D',$argv[1]??''))exit(1);
foreach(['AdminAuth','Pricing','Inventory','Products','Vouchers','Quotes','Orders','Transport'] as $c)require_once dirname(__DIR__).'/src/'.$c.'.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
try{$in=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);if($argv[2]==='create')(new Orders($db))->create($in);else (new Transport($db))->save($argv[2],$in,'admin');echo 'OK';}catch(HttpError $e){echo $e->status;}
