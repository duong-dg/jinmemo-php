<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/TransferAudit.php';
require dirname(__DIR__).'/src/TransferRelations.php';
if ($argc === 2 && $argv[1] === '--help') {
    echo "php bin/check-transfer.php --file=PATH_TO_NODE_EXPORT.sql [--relations]\nRead-only Node MySQL transfer v1 file audit. --relations checks declared PK/UNIQUE/FK using two passes and bounded hash indexes. No database, SQL execution or import. Exit 0: complete matching profile and requested checks passed; 2: review required; 1: cannot read input. importReady is always false.\n";
    exit;
}
try {
    $file=null; $relations=false;
    foreach(array_slice($argv,1) as $arg) {
        if ($arg==='--relations' && !$relations) $relations=true;
        elseif (preg_match('/^--file=(.+)$/D',$arg,$m) && $file===null) $file=$m[1];
        else throw new InvalidArgumentException('Use --help for usage.');
    }
    if ($file===null) throw new InvalidArgumentException('Use --help for usage.');
    $report = $relations ? (new TransferRelations())->run($file) : (new TransferAudit())->run($file);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    exit($report['profileMatches'] && (!$relations || $report['relations']['status']==='PASSED') ? 0 : 2);
} catch (InvalidArgumentException $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
catch (Throwable) { fwrite(STDERR,"Transfer audit failed. Check file access, memory limits and installed schema profile. No SQL was executed.\n"); exit(1); }
