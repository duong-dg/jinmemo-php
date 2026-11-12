<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
try {
    $db = jingoDatabase(jingoConfig());
    $files=glob(dirname(__DIR__).'/database/*.sql'); sort($files,SORT_STRING);
    foreach ($files as $file) {
        $sql=file_get_contents($file);
        foreach (explode(';',$sql) as $statement) if (trim($statement)!=='') $db->exec($statement);
    }
    echo "JinMemo schema installed.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
