<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/AdminAuth.php';require dirname(__DIR__).'/src/AdminSecurity.php';
try{$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_X_JINGO_ADMIN_ENTRY']=$argv[6];$service=new AdminSecurity($db,['MEDIA_DIR'=>$argv[2]]);$service->verify(['challengeId'=>$argv[3],'code'=>$argv[4]],$argv[5]);echo 'ok';}catch(HttpError $e){echo $e->status;}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}
