<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/TransferFileAudit.php';
try{
    $args=[];foreach(array_slice($argv,1) as $arg){if($arg==='--help'){echo "php bin/check-transfer-files.php --file=NODE_EXPORT.sql --media-dir=MEDIA_ROOT --ticket-dir=TICKET_ROOT\nRead-only original file checks: UUID.webp and UUID.bin, bytes/hash, WebP dimensions. No PDF processing, copying, rewriting, database or activation.\n";exit;}if(!preg_match('/^--(file|media-dir|ticket-dir)=(.+)$/D',$arg,$m)||isset($args[$m[1]]))throw new InvalidArgumentException('Use --help.');$args[$m[1]]=$m[2];}
    if(count($args)!==3)throw new InvalidArgumentException('Use --help.');$r=(new TransferFileAudit())->run($args['file'],$args['media-dir'],$args['ticket-dir']);echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($r['ready']?0:2);
}catch(InvalidArgumentException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}catch(Throwable){fwrite(STDERR,"Original file verification did not complete. No file was changed.\n");exit(1);}
