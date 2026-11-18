<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/OrderRelationTransfer.php';
try{
    $source=null;$apply=false;$fingerprint=null;
    foreach(array_slice($argv,1) as $arg){
        if($arg==='--help'){echo "php bin/import-order-relations.php --source-db=STAGING_DATABASE [--apply --fingerprint=DRY_RUN_SHA256]\nChannel links and original action history into isolated holding tables. Exact held order cores and referenced partner/counter metadata required. Read-only dry-run by default. No live order changes, action execution, invented action versions, payments, mail or activation. Requires schema 060.\n";exit;}
        if($arg==='--apply'&&!$apply){$apply=true;continue;}if(str_starts_with($arg,'--source-db=')&&$source===null){$source=substr($arg,12);continue;}if(str_starts_with($arg,'--fingerprint=')&&$fingerprint===null){$fingerprint=substr($arg,14);continue;}throw new InvalidArgumentException('Tham số không hợp lệ. Dùng --help.');
    }
    if(!$source)throw new InvalidArgumentException('Thiếu --source-db.');$r=(new OrderRelationTransfer(jingoDatabase(jingoConfig())))->run($source,$apply,$fingerprint);echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($r['ready']?0:2);
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Không hoàn tất nhập liên kết/lịch sử đơn vào kho chờ. Kiểm tra schema 060, lõi đơn và metadata cha. Không ghi một phần hoặc thực thi thao tác cũ.\n");exit(1);}
