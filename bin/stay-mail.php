<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/AdminAuth.php';
require dirname(__DIR__).'/src/StayMail.php';
try{
    $limit=$argv[1]??'10';if(!preg_match('/^(?:[1-9]|[1-4][0-9]|50)$/D',$limit))throw new RuntimeException('Usage: php bin/stay-mail.php [1..50]');
    $c=jingoConfig();$db=jingoDatabase($c);$worker=new StayMail($db,$c['MEDIA_DIR']??dirname(__DIR__).'/storage/media',$c);$counts=[];
    for($i=0;$i<(int)$limit;$i++){$status=$worker->processOne();$counts[$status]=($counts[$status]??0)+1;if(in_array($status,['disabled','empty','busy'],true))break;}
    echo json_encode($counts,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Stay mail worker failed. Check database/schema, SMTP, encryption key and website URL.\n");exit(1);}
