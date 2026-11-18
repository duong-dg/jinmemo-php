<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/RefundTransfer.php';
try{
    $file=null;$apply=false;$fp=null;
    foreach(array_slice($argv,1) as $arg){if($arg==='--help'){echo "php bin/import-refunds.php --file=FULL_V2.sql [--apply --fingerprint=DRY_RUN_SHA256]\nHistorical records into runtime jingo_refunds only. Requires matching native parent roots and original actual receipts; does not import parents or execute money transfers. Dry-run by default, atomic/no-overwrite, no providers or messages.\n";exit;}if(str_starts_with($arg,'--file=')&&$file===null){$file=substr($arg,7);continue;}if($arg==='--apply'&&!$apply){$apply=true;continue;}if(str_starts_with($arg,'--fingerprint=')&&$fp===null){$fp=substr($arg,14);continue;}throw new InvalidArgumentException('Dùng --help để xem tham số.');}
    if(!$file)throw new InvalidArgumentException('Cần --file=FULL_V2.sql.');$r=(new RefundTransfer(jingoDatabase(jingoConfig())))->run($file,$apply,$fp);echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($r['ready']?0:2);
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Không nhập lịch sử hoàn. Kiểm schema, giới hạn dữ liệu, đơn cha và khoản thực thu; giao dịch chưa hoàn tất đã rollback.\n");exit(1);}
