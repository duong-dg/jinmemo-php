<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/FullTransferHolding.php';require dirname(__DIR__).'/src/TransferFileAudit.php';
try{
    $file=null;$apply=false;$fingerprint=null;$media=null;$tickets=null;
    foreach(array_slice($argv,1) as $arg){
        if($arg==='--help'){echo "php bin/import-transfer.php --file=NODE_EXPORT.sql [--media-dir=MEDIA_ROOT --ticket-dir=TICKET_ROOT] [--apply --fingerprint=DRY_RUN_SHA256]\nAuto-detect v1 (61 tables) or full-v2 (68 source tables plus verified manifest) into lossless isolated holding records. File/PK/unique/FK and source reconciliation checks; optional original asset checks run before apply. Read-only dry-run by default; no SQL execution, live imports, providers, emails or activation. Requires schema 061.\n";exit;}
        if($arg==='--apply'&&!$apply){$apply=true;continue;}if(str_starts_with($arg,'--file=')&&$file===null){$file=substr($arg,7);continue;}if(str_starts_with($arg,'--fingerprint=')&&$fingerprint===null){$fingerprint=substr($arg,14);continue;}if(str_starts_with($arg,'--media-dir=')&&$media===null){$media=substr($arg,12);continue;}if(str_starts_with($arg,'--ticket-dir=')&&$tickets===null){$tickets=substr($arg,13);continue;}throw new InvalidArgumentException('Tham số không hợp lệ. Dùng --help.');
    }
    if(!$file||($media===null)!==($tickets===null))throw new InvalidArgumentException('Cần --file và cung cấp cả hai thư mục nếu kiểm file.');
    $files=$media===null?null:(new TransferFileAudit())->run($file,$media,$tickets);$blocked=$files!==null&&!$files['ready'];
    $r=(new FullTransferHolding(jingoDatabase(jingoConfig())))->run($file,$apply&&!$blocked,$fingerprint,$files['sourceSha256']??null);$r['originalFiles']=$files;
    if($blocked){$r['ready']=false;$r['fingerprint']=null;$r['mode']=$apply?'apply-blocked-files':'dry-run';$r['errorCount']++;if(count($r['errors'])<100)$r['errors'][]=['code'=>'ORIGINAL_FILES_INVALID'];}
    if($files!==null&&$files['ready']&&$files['sourceSha256']===$r['sourceSha256'])$r['activationBlockers']=array_values(array_diff($r['activationBlockers'],['PHYSICAL_FILES_NOT_VERIFIED']));
    echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($r['ready']?0:2);
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Không hoàn tất nhập export vào kho chờ. Kiểm tra schema 061, file, quyền DB và giới hạn bộ nhớ/dữ liệu. Không chạy SQL nguồn hoặc ghi dữ liệu vận hành.\n");exit(1);}
