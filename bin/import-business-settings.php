<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/BusinessSettingsTransfer.php';
try{
    $file=null;$scope='all';$scopeSet=false;$apply=false;$fp=null;$withGifts=false;
    foreach(array_slice($argv,1) as $arg){
        if($arg==='--help'){echo "php bin/import-business-settings.php --file=FULL_V2.sql [--scope=all|manual-payments|gifts] [--with-gift-orders] [--apply --fingerprint=DRY_RUN_SHA256]\nImports selected settings into runtime jingo_settings, preserving source version and JSON. Dry-run by default; refuses overwrite. Gifts require empty source gift orders unless --with-gift-orders imports supported standalone historical orders atomically with stock. Bundle/payment-dependent gifts remain blocked. Requires existing media and compatible feature flag. No emails, credentials, stock recalculation or file changes.\n";exit;}
        if($arg==='--with-gift-orders'&&!$withGifts){$withGifts=true;continue;}
        if(str_starts_with($arg,'--file=')&&$file===null){$file=substr($arg,7);continue;}if(str_starts_with($arg,'--scope=')&&!$scopeSet){$scope=substr($arg,8);$scopeSet=true;continue;}if($arg==='--apply'&&!$apply){$apply=true;continue;}if(str_starts_with($arg,'--fingerprint=')&&$fp===null){$fp=substr($arg,14);continue;}throw new InvalidArgumentException('Dùng --help để xem tham số.');
    }
    if(!$file)throw new InvalidArgumentException('Cần --file=FULL_V2.sql.');
    $r=(new BusinessSettingsTransfer(jingoDatabase(jingoConfig())))->run($file,$scope,$apply,$fp,$withGifts);echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($r['ready']?0:2);
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Không nhập cấu hình. Kiểm schema, file nguồn, ảnh đã nhập và dữ liệu phụ thuộc; giao dịch chưa hoàn tất đã rollback.\n");exit(1);}
