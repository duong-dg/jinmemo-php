<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_pos_portal_test_[a-f0-9]{12}$/D',$argv[1] ?? ''))exit(1);
foreach(['AdminAuth','PosUsers','PosAuth'] as $c)require_once dirname(__DIR__).'/src/'.$c.'.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$body=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);
if($argv[2]==='save'){(new PosUsers($db))->save($body,'admin');echo 'saved';}else{$_SERVER['HTTP_X_JINGO_POS']='1';$_SERVER['HTTP_ORIGIN']='http://localhost';$_SERVER['REMOTE_ADDR']='race';try{(new PosAuth($db,['APP_ENV'=>'development','PUBLIC_BASE_URL'=>'http://localhost']))->login($body);echo 'logged';}catch(HttpError $e){if($e->status!==401)throw $e;echo '401';}}
