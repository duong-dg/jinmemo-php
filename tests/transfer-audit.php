<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/TransferAudit.php';
function auditAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$fixture = file_get_contents(__DIR__.'/fixtures/transfer-audit-node.sql');
$path = tempnam(sys_get_temp_dir(),'transfer_audit_');
$audit = new TransferAudit();
$check = static function(string $sql) use ($path,$audit): array {
    file_put_contents($path,$sql); clearstatcache(true,$path); $before=hash_file('sha256',$path);
    $report=$audit->run($path);
    auditAssert(hash_file('sha256',$path)===$before,'Input changed');
    auditAssert(!$report['importReady'],'Claimed import readiness');
    auditAssert(!str_contains(json_encode($report),'private@example') && !str_contains(json_encode($report),'Khách'),'Private values leaked');
    return $report;
};
try {
    $full=$check($fixture);
    auditAssert($full['formatValid'] && $full['profileMatches'] && count($full['tables'])===61 && $full['errorCount']===0,'Real Node fixture rejected: '.json_encode($full['errors']));
    auditAssert($full['sha256']===hash('sha256',$fixture),'Hash mismatch');
    $bad = [
        str_replace('-- TRANSFER COMPLETE ', '-- INCOMPLETE ', $fixture),
        $fixture."DROP TABLE jingo_orders;\n",
        str_replace('SET NAMES utf8mb4;', 'USE customer_database;', $fixture),
        str_replace('jm_transfer_product_types: 1 rows','jm_transfer_product_types: 2 rows',$fixture),
        str_replace('"jinmemo_product_types":1','"jinmemo_product_types":2',$fixture),
        str_replace('"jinmemo_product_types":1','"jinmemo_product_types":99,"jinmemo_product_types":1',$fixture),
        str_replace('`version` BIGINT NULL','`version` LONGTEXT NULL',$fixture),
        str_replace('`version` BIGINT NULL','`password_hash` BIGINT NULL',$fixture),
        str_replace('jm_transfer_product_types','jm_transfer_admins',$fixture),
        str_replace('9007199254740993','9223372036854775808',$fixture),
        str_replace(',12)',',2147483648)',$fixture),
        str_replace("CONVERT(X'", "CONVERT(X'ff",$fixture),
        str_replace(bin2hex('2026-11-17 01:02:03.123456'),bin2hex('2026-02-30 01:02:03.123456'),$fixture),
        str_replace(bin2hex('{"label":"dữ liệu riêng","amount":9007199254740993}'),bin2hex('{broken json'),$fixture),
        substr($fixture,0,-1),
    ];
    foreach ($bad as $i=>$sql) auditAssert(!$check($sql)['formatValid'],'Bad fixture accepted: '.$i);
    // An absent source module is a partial export, not proof of a corrupt download.
    $prefix=substr($fixture,0,strpos($fixture,'CREATE TABLE'));
    $empty=$check($prefix."-- TRANSFER COMPLETE {}\n");
    auditAssert($empty['formatValid'] && !$empty['profileMatches'] && count($empty['missingTables'])===61,'Missing module classification');
    $crlf=$check(str_replace("\n","\r\n",$fixture)); auditAssert($crlf['formatValid'],'CRLF rejected');
    // Dialect types supported by the exporter but absent in the pinned 61-table schema.
    $testProfile=tempnam(sys_get_temp_dir(),'transfer_profile_');
    try {
        foreach ([
            ['DECIMAL(65,20)','numeric','123.12345678901234567890',true],
            ['DECIMAL(65,20)','numeric','1e-20',true],
            ['DECIMAL(65,20)','numeric','1e-21',false],
            ['DECIMAL(65,20)','numeric',str_repeat('9',45),true],
            ['DECIMAL(65,20)','numeric','1e45',false],
            ['DOUBLE','real','1e309',false],
            ['DOUBLE','real','1e10',true],
            ['BIGINT','bigint','-9223372036854775808',true],
            ['BIGINT','bigint','-9223372036854775809',false],
            ['BIGINT','bigint','1e3',false],
            ['TINYINT(1)','boolean','2',false],
            ['DATE','date',"CONVERT(X'".bin2hex('2024-02-29')."' USING utf8mb4)",true],
            ['DATE','date',"CONVERT(X'".bin2hex('2023-02-29')."' USING utf8mb4)",false],
        ] as [$type,$sourceType,$raw,$valid]) {
            file_put_contents($testProfile,json_encode(['sourceCommit'=>'test-only','tables'=>['jm_transfer_product_types'=>[['name'=>'id','type'=>$type,'sourceType'=>$sourceType,'nullable'=>false]]]]));
            $sql=$prefix.'CREATE TABLE `jm_transfer_product_types` (' ."\n  `id` $type NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\nINSERT INTO `jm_transfer_product_types` (`id`) VALUES ($raw);\n-- jm_transfer_product_types: 1 rows\n-- TRANSFER COMPLETE {\"jinmemo_product_types\":1}\n";
            file_put_contents($path,$sql);clearstatcache(true,$path);
            auditAssert((new TransferAudit($testProfile))->run($path)['formatValid']===$valid,'Boundary value validation failed');
        }
    } finally { unlink($testProfile); }
    // 100 reported errors cap, total errors remains visible; values never included.
    $nulls=preg_replace("/CONVERT\(X'[0-9a-f]*' USING utf8mb4\)/",'NULL',$fixture);
    $many=$check($nulls); auditAssert(count($many['errors'])===100 && $many['errorCount']>100,'Diagnostics not bounded');
    file_put_contents($path,$fixture);
    $cli=dirname(__DIR__).'/bin/check-transfer.php';
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($cli).' '.escapeshellarg('--file='.$path);
    exec($command,$output,$code); auditAssert($code===0 && json_decode(implode("\n",$output),true)['importReady']===false,'CLI full report');
    file_put_contents($path,$prefix."-- TRANSFER COMPLETE {}\n"); $output=[];
    exec($command,$output,$code); auditAssert($code===2,'CLI partial exit code');
    echo "Transfer audit passed: pinned Node exporter, 61 tables, numeric/UTF-8/JSON/date validation, truncated/hostile SQL, privacy, partial export, CLI and unchanged input. No database used.\n";
} finally { unlink($path); }
