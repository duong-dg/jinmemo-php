<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/CatalogTransfer.php';
try{
    $source=null;$apply=false;$fingerprint=null;foreach(array_slice($argv,1) as $arg){if($arg==='--help'){echo "php bin/import-catalog.php --source-db=STAGING_DATABASE [--apply --fingerprint=DRY_RUN_SHA256]\nDry-run by default. Types, destinations, suppliers, counters and their old slugs only. No orders, inventory, balances, users, settings or file changes.\n";exit;}if($arg==='--apply'&&!$apply){$apply=true;continue;}if(str_starts_with($arg,'--source-db=')&&$source===null){$source=substr($arg,12);continue;}if(str_starts_with($arg,'--fingerprint=')&&$fingerprint===null){$fingerprint=substr($arg,14);continue;}throw new InvalidArgumentException('Tham số không hợp lệ. Dùng --help.');}
    if(!$source)throw new InvalidArgumentException('Thiếu --source-db.');$config=jingoConfig();$result=(new CatalogTransfer(jingoDatabase($config),$config['MEDIA_DIR']??dirname(__DIR__).'/storage/media'))->run($source,$apply,$fingerprint);echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($result['ready']?0:2);
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Không hoàn tất nhập danh mục. Kiểm tra schema, quyền đọc staging/ghi runtime, file ảnh và giới hạn 16 MiB/50.000 dòng mỗi bảng. Không ghi metadata một phần.\n");exit(1);}
