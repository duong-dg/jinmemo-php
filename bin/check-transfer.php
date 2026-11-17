<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/TransferAudit.php';
if ($argc === 2 && $argv[1] === '--help') {
    echo "php bin/check-transfer.php --file=PATH_TO_NODE_EXPORT.sql\nRead-only Node MySQL transfer v1 file audit. No database, SQL execution or import. Exit 0: complete matching profile; 2: review required; 1: cannot read input. importReady is always false.\n";
    exit;
}
try {
    if ($argc !== 2 || !preg_match('/^--file=(.+)$/D', $argv[1], $m)) throw new InvalidArgumentException('Use --help for usage.');
    $report = (new TransferAudit())->run($m[1]);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    exit($report['profileMatches'] ? 0 : 2);
} catch (InvalidArgumentException $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
catch (Throwable) { fwrite(STDERR,"Transfer audit failed. Check file access, memory limits and installed schema profile. No SQL was executed.\n"); exit(1); }
