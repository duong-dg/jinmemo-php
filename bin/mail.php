<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/StayMail.php';
require dirname(__DIR__).'/src/OrderMail.php';
try{
 $limit=$argv[1]??'10';if(!preg_match('/^(?:[1-9]|[1-4][0-9]|50)$/D',$limit))throw new RuntimeException('Usage: php bin/mail.php [1..50]');
 $c=jingoConfig();$db=jingoDatabase($c);$dir=$c['MEDIA_DIR']??dirname(__DIR__).'/storage/media';$workers=['stays'=>new StayMail($db,$dir,$c),'orders'=>new OrderMail($db,$dir,$c)];$counts=[];$handled=0;
 for($round=0;$round<(int)$limit;$round++){$progress=false;foreach($workers as $type=>$worker){$state=$worker->processOne();$counts[$type][$state]=($counts[$type][$state]??0)+1;if(!in_array($state,['empty','disabled','busy'],true)){$handled++;$progress=true;}if($handled>=(int)$limit)break 2;}if(!$progress)break;}
 echo json_encode($counts,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Mail worker failed. Check database/schema, SMTP, encryption key and website URL.\n");exit(1);}
