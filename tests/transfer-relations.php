<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/TransferRelations.php';
function relationAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function relationValue(string $value): string { return "CONVERT(X'".bin2hex($value)."' USING utf8mb4)"; }
function relationRow(string $sql,string $table,array $replace): string {
    return preg_replace_callback('/^INSERT INTO `'.$table.'` \(([^\n]+)\) VALUES \(([^\n]+)\);$/m',static function($m) use($replace,$table) {
        $names=array_map(static fn($v)=>trim($v,'`'),explode(',',$m[1])); $values=explode(',',$m[2]);
        foreach($replace as $name=>$value) { $i=array_search($name,$names,true); if($i===false)throw new RuntimeException('Test column absent');$values[$i]=$value; }
        return 'INSERT INTO `'.$table.'` ('.$m[1].') VALUES ('.implode(',',$values).');';
    },$sql);
}
function relationDuplicate(string $sql,string $table,array $replace=[]): string {
    preg_match('/^INSERT INTO `'.$table.'` .+;$/m',$sql,$m);
    $row=relationRow($m[0],$table,$replace);
    $sql=str_replace($m[0],$m[0]."\n".$row,$sql);
    $sql=str_replace('-- '.$table.': 1 rows','-- '.$table.': 2 rows',$sql);
    return str_replace('"jinmemo_'.substr($table,12).'":1','"jinmemo_'.substr($table,12).'":2',$sql);
}
$fixture=file_get_contents(__DIR__.'/fixtures/transfer-audit-node.sql');
$path=tempnam(sys_get_temp_dir(),'relation_audit_');
$check=static function(string $sql,int $limit=100000) use($path): array {
    file_put_contents($path,$sql);clearstatcache(true,$path);$hash=hash_file('sha256',$path);
    $result=(new TransferRelations($limit))->run($path);
    relationAssert(!$result['importReady'] && hash_file('sha256',$path)===$hash,'Read-only/import-ready guarantee failed');
    $json=json_encode($result);
    foreach(['private@example','Khách','missing-private-target','private-secondary-id'] as $private) relationAssert(!str_contains($json,$private),'Report leaked data');
    return $result;
};
try {
    $r=$check($fixture);
    relationAssert($r['formatValid'] && $r['relations']['status']==='INCOMPLETE' && $r['relations']['errorCount']===0,'Expected absent external table');
    relationAssert($r['relations']['unresolvedReferences'][0]['reason']==='TARGET_NOT_EXPORTED','External dependency misclassified');
    // Nullable foreign key: no reference means there is nothing to resolve.
    $valid=relationRow($fixture,'jm_transfer_gateway_events',['attempt_id'=>'NULL']);
    $r=$check($valid);
    relationAssert($r['relations']['status']==='PASSED' && $r['relations']['declaredConstraintsValid']===true && $r['relations']['foreignReferencesChecked']===54,'Valid declared relations rejected');
    relationAssert($r['relations']['indexedKeys']===87 && $r['relations']['rowsChecked']===61,'Index coverage wrong');
    $duplicates=[
        relationDuplicate($valid,'jm_transfer_products'),
        relationDuplicate($valid,'jm_transfer_products',['id'=>relationValue('private-secondary-id')]),
        relationDuplicate($valid,'jm_transfer_inventory_buckets'),
        relationDuplicate($valid,'jm_transfer_checkout_members'),
    ];
    foreach($duplicates as $i=>$sql) {
        $r=$check($sql); relationAssert($r['formatValid'] && $r['relations']['status']==='FAILED','Duplicate missed '.$i);
        $codes=array_column($r['relations']['errors'],'code');
        relationAssert(in_array($i===1?'DUPLICATE_UNIQUE':'DUPLICATE_PRIMARY',$codes,true),'Wrong duplicate classification');
    }
    // Parent is declared later in the file: two passes must resolve it.
    $r=$check(relationRow($valid,'jm_transfer_cart_orders',['order_id'=>relationValue('missing-private-target')]));
    relationAssert($r['formatValid'] && $r['relations']['status']==='FAILED' && $r['relations']['errors'][0]['code']==='MISSING_FOREIGN_TARGET','Orphan missed');
    // Missing target table differs from an exported empty target table.
    $missing=preg_replace('/^CREATE TABLE `jm_transfer_orders` \(\n.*?^-- jm_transfer_orders: 1 rows\n/ms','',$valid);
    $missing=str_replace('"jinmemo_orders":1,','',$missing);
    $r=$check($missing);
    relationAssert($r['formatValid'] && $r['relations']['status']==='INCOMPLETE' && in_array('TARGET_TABLE_MISSING',array_column($r['relations']['unresolvedReferences'],'reason'),true),'Missing source module classified as orphan');
    $empty=preg_replace('/^INSERT INTO `jm_transfer_orders` .+;\n/m','',$valid);
    $empty=str_replace(['-- jm_transfer_orders: 1 rows','"jinmemo_orders":1'],['-- jm_transfer_orders: 0 rows','"jinmemo_orders":0'],$empty);
    $r=$check($empty);relationAssert($r['relations']['status']==='FAILED','Empty exported parent accepted');
    // PostgreSQL UNIQUE allows several NULL values; PK still differs.
    $nullable=relationRow($valid,'jm_transfer_pos_shifts',['close_request'=>'NULL']);
    $nullable=relationDuplicate($nullable,'jm_transfer_pos_shifts',['id'=>relationValue('private-secondary-id')]);
    relationAssert($check($nullable)['relations']['status']==='PASSED','Nullable UNIQUE semantics wrong');
    // Canonical UUID key comparison is case insensitive; text key comparison remains byte based.
    $uuid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $uuidSql=relationRow($valid,'jm_transfer_stays',['id'=>relationValue($uuid)]);
    foreach(['jm_transfer_stay_aliases','jm_transfer_stay_bookings'] as $t)$uuidSql=relationRow($uuidSql,$t,['hotel_id'=>relationValue(strtoupper($uuid))]);
    relationAssert($check($uuidSql)['relations']['status']==='PASSED','UUID canonicalization failed');
    $numbers=relationDuplicate($valid,'jm_transfer_post_revisions',['version'=>'012']);
    relationAssert(in_array('DUPLICATE_PRIMARY',array_column($check($numbers)['relations']['errors'],'code'),true),'Numeric key normalization failed');
    $tuple=relationRow($valid,'jm_transfer_inventory_buckets',['variant_id'=>relationValue('a:b'),'visit_date'=>relationValue('c')]);
    $tuple=relationDuplicate($tuple,'jm_transfer_inventory_buckets',['variant_id'=>relationValue('a'),'visit_date'=>relationValue('b:c')]);
    relationAssert($check($tuple)['relations']['status']==='PASSED','Composite tuple encoding ambiguous');
    $r=$check($valid,1);relationAssert($r['relations']['status']==='INCOMPLETE' && $r['relations']['indexedKeys']===1 && $r['relations']['errors'][0]['code']==='INDEX_LIMIT','Index limit silently skipped');
    preg_match('/^INSERT INTO `jm_transfer_products` .+;$/m',$valid,$row);
    $many=str_replace($row[0],implode("\n",array_fill(0,101,$row[0])),$valid);
    $many=str_replace(['-- jm_transfer_products: 1 rows','"jinmemo_products":1'],['-- jm_transfer_products: 101 rows','"jinmemo_products":101'],$many);
    $r=$check($many);relationAssert($r['formatValid'] && count($r['relations']['errors'])===100 && $r['relations']['errorCount']===200,'Relation diagnostics not bounded');
    $wrongType=str_replace('`id` LONGTEXT NULL','`id` BIGINT NULL',$valid);
    $r=$check($wrongType);relationAssert(!$r['formatValid'] && $r['relations']['status']==='SKIPPED','Schema mismatch used for relation verdict');
    $r=$check($valid."DROP TABLE jingo_orders;\n");relationAssert(!$r['formatValid'] && $r['relations']['status']==='SKIPPED','Unsafe input used for relation verdict');
    $cli=dirname(__DIR__).'/bin/check-transfer.php';$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($cli).' --relations '.escapeshellarg('--file='.$path);
    foreach([[$valid,0],[$fixture,2],[$duplicates[0],2]] as [$sql,$expected]) {
        file_put_contents($path,$sql);$output=[];exec($command,$output,$code);relationAssert($code===$expected,'Relation CLI exit code');
    }
    echo "Transfer relations passed: declared PK/UNIQUE/FK, composite keys, orphan/missing/external targets, NULL semantics, UUID, index limits, privacy, unchanged input and CLI. No database used.\n";
} finally { unlink($path); }
