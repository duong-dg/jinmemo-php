<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/PartnerCreditTransferAudit.php';
if ($argc===2 && $argv[1]==='--help') {
    echo "php bin/check-partner-credit-transfer.php --file=NODE_EXPORT.sql --as-of=YYYY-MM-DDTHH:MM:SS.ffffffZ\nRead-only source partner credit reconciliation. No SQL execution, database, import or account activation. Exit 0: complete source profile and credit checks passed; 2: review required; 1: invalid input. importReady remains false.\n"; exit;
}
try {
    $file=null; $time=null;
    foreach (array_slice($argv,1) as $arg) {
        if ($file===null && preg_match('/^--file=(.+)$/D',$arg,$m)) $file=$m[1];
        elseif ($time===null && preg_match('/^--as-of=(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z)$/D',$arg,$m)) $time=$m[1];
        else throw new InvalidArgumentException('Use --help for usage.');
    }
    $asOf=$time===null?false:DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$time,new DateTimeZone('UTC'));
    if ($file===null || $asOf===false || $asOf->format('Y-m-d\TH:i:s.u\Z')!==$time) throw new InvalidArgumentException('Provide --file and a valid explicit UTC --as-of.');
    $report=(new PartnerCreditTransferAudit())->run($file,$asOf);
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($report['profileMatches'] && $report['partnerCredit']['status']==='PASSED'?0:2);
} catch (InvalidArgumentException $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
catch (Throwable) { fwrite(STDERR,"Credit audit failed. Check file access, limits and the installed source profile. No SQL was executed.\n"); exit(1); }
