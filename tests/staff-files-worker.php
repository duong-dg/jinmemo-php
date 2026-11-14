<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_staff_files_test_[a-f0-9]{12}$/D',$argv[1] ?? '')||basename($argv[2] ?? '')!==$argv[1])exit(1);
foreach(['AdminAuth','Pricing','Products','Inventory','Vouchers','Orders','PosUsers','PosAuth','PosPortal','TicketFiles','StaffTicketFiles'] as $c)require_once dirname(__DIR__).'/src/'.$c.'.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);[$actor,$id,$body]=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);$result=(new StaffTicketFiles($db,$argv[2].'/.tickets'))->upload($actor,$id,$body,$argv[2].'/source.pdf','race.pdf');echo $result['id'];
