<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/AdminAuth.php';require_once dirname(__DIR__).'/src/PartnerShares.php';
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_partner_files_test_[a-f0-9]{12}$/D',$argv[1] ?? ''))exit(1);
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$in=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);echo "READY\n";flush();try{(new PartnerShares($db,$argv[2].'/.tickets'))->guest($in['credential'],'download',$in['file']);echo '200';}catch(HttpError $e){echo $e->status;}
