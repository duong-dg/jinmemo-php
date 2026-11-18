<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/VoucherUseTransfer.php';
try{
    $source=null;$apply=false;$fingerprint=null;
    foreach(array_slice($argv,1) as $arg){
        if($arg==='--help'){echo "php bin/import-voucher-uses.php --source-db=STAGING_DATABASE [--apply --fingerprint=DRY_RUN_SHA256]\nHistorical voucher uses only. Orders and vouchers must already match source metadata. New uses require pending vouchers. Read-only dry-run by default; never imports orders, reprices, changes pending flags or activates accounts/vouchers. Requires schema 058.\n";exit;}
        if($arg==='--apply'&&!$apply){$apply=true;continue;}
        if(str_starts_with($arg,'--source-db=')&&$source===null){$source=substr($arg,12);continue;}
        if(str_starts_with($arg,'--fingerprint=')&&$fingerprint===null){$fingerprint=substr($arg,14);continue;}
        throw new InvalidArgumentException('Tham số không hợp lệ. Dùng --help.');
    }
    if(!$source)throw new InvalidArgumentException('Thiếu --source-db.');
    $report=(new VoucherUseTransfer(jingoDatabase(jingoConfig())))->run($source,$apply,$fingerprint);
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($report['ready']?0:2);
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
catch(Throwable){fwrite(STDERR,"Không hoàn tất nhập lịch sử voucher. Kiểm tra schema 058, staging/đơn/voucher đã chuyển và giới hạn metadata. Không ghi một phần hoặc mở khóa sử dụng.\n");exit(1);}
