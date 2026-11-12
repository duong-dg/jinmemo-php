<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
foreach(['bootstrap','AdminAuth','ContentHtml','Media','Posts','PostTransfer'] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
try{
    $source=null;$apply=false;foreach(array_slice($argv,1) as $argument){if($argument==='--help'){echo "php bin/import-posts.php --source-db=DATABASE [--apply]\nDefault: read-only dry-run. Imports blog tables and POST/POST_EN aliases only into empty blog tables. Source and target must be separate databases on the configured MySQL server.\n";exit;}if($argument==='--apply'&&!$apply){$apply=true;continue;}if(str_starts_with($argument,'--source-db=')&&$source===null){$source=substr($argument,12);continue;}throw new InvalidArgumentException('Tham số không hợp lệ. Dùng --help.');}if(!$source)throw new InvalidArgumentException('Thiếu --source-db=DATABASE. Dùng --help.');
    $config=jingoConfig();$result=(new PostTransfer(jingoDatabase($config),$config['MEDIA_DIR'] ?? dirname(__DIR__).'/storage/media'))->run($source,$apply);echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($result['ready']?0:2);
}catch(InvalidArgumentException $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
catch(Throwable){fwrite(STDERR,"Không hoàn tất kiểm tra/nhập bài viết. Kiểm tra tên database, quyền MySQL, bảng staging, giới hạn 16 MB/50.000 dòng mỗi bảng và schema. Dữ liệu đích không được ghi một phần.\n");exit(1);}
