<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/OrderCoreTransfer.php';
try{
    $source=null;$apply=false;$fingerprint=null;
    foreach(array_slice($argv,1) as $arg){
        if($arg==='--help'){echo "php bin/import-order-cores.php --source-db=STAGING_DATABASE [--apply --fingerprint=DRY_RUN_SHA256]\nValidated order cores into isolated jingo_transfer_orders only. Default read-only dry-run. No live orders, allocations, payments, emails or activation. operationalReady always false. Requires schema 059.\n";exit;}
        if($arg==='--apply'&&!$apply){$apply=true;continue;}if(str_starts_with($arg,'--source-db=')&&$source===null){$source=substr($arg,12);continue;}if(str_starts_with($arg,'--fingerprint=')&&$fingerprint===null){$fingerprint=substr($arg,14);continue;}throw new InvalidArgumentException('Tham số không hợp lệ. Dùng --help.');
    }
    if(!$source)throw new InvalidArgumentException('Thiếu --source-db.');$r=(new OrderCoreTransfer(jingoDatabase(jingoConfig())))->run($source,$apply,$fingerprint);echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($r['ready']?0:2);
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Không hoàn tất nhập lõi đơn vào kho chuyển đổi. Kiểm tra schema 059, staging và giới hạn dữ liệu. Không tạo đơn vận hành hoặc ghi một phần.\n");exit(1);}
