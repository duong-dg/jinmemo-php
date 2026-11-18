<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/PartnerCreditTransferAudit.php';
function creditAuditAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function creditAuditValue(string $value): string { return "CONVERT(X'".bin2hex($value)."' USING utf8mb4)"; }
function creditAuditRow(string $sql,string $table,int $index,array $replace): string
{
    $i=0;
    return preg_replace_callback('/^INSERT INTO `jm_transfer_'.$table.'` \(([^\n]+)\) VALUES \(([^\n]+)\);$/m',static function($m) use (&$i,$index,$replace,$table) {
        if (++$i!==$index) return $m[0];
        $names=array_map(static fn($n)=>trim($n,'`'),explode(',',$m[1]));$values=explode(',',$m[2]);
        foreach ($replace as $name=>$value) { $pos=array_search($name,$names,true); if ($pos===false) throw new RuntimeException('No test column'); $values[$pos]=$value; }
        return 'INSERT INTO `jm_transfer_'.$table.'` ('.$m[1].') VALUES ('.implode(',',$values).');';
    },$sql);
}
function creditAuditEmpty(string $sql,string $table,int $count): string
{
    $sql=preg_replace('/^INSERT INTO `jm_transfer_'.$table.'` .+;\n/m','',$sql);
    return str_replace(['-- jm_transfer_'.$table.': '.$count.' rows','"jinmemo_'.$table.'":'.$count],['-- jm_transfer_'.$table.': 0 rows','"jinmemo_'.$table.'":0'],$sql);
}
$fixture=str_replace("\r\n","\n",file_get_contents(__DIR__.'/fixtures/partner-credit-transfer-node.sql'));
$expected=json_decode(file_get_contents(__DIR__.'/fixtures/partner-credit-transfer-summary.json'),true,512,JSON_THROW_ON_ERROR);
$asOf=new DateTimeImmutable($expected['asOf']);
$path=tempnam(sys_get_temp_dir(),'credit_transfer_');
$check=static function(string $sql,?DateTimeImmutable $date=null,int $rows=100000,int $bytes=33554432) use($path,$asOf): array {
    file_put_contents($path,$sql);clearstatcache(true,$path);$hash=hash_file('sha256',$path);
    $report=(new PartnerCreditTransferAudit($rows,$bytes))->run($path,$date ?? $asOf);
    creditAuditAssert(!$report['importReady'] && hash_file('sha256',$path)===$hash,'Read-only guarantee');
    $json=json_encode($report);
    foreach (['private-value','private1@example','PRIVATE-ORDER','PRIVATE-REPAYMENT','00000000-0000-4000','missing-private'] as $secret) creditAuditAssert(!str_contains($json,$secret),'Private data leaked');
    return $report;
};
$failure=static function(string $sql,string $code) use($check): void {
    $r=$check($sql);$c=$r['partnerCredit'];
    creditAuditAssert($r['profileMatches'] && $c['status']==='FAILED' && in_array($code,array_column($c['errors'],'code'),true),'Missing error '.$code.' '.json_encode($c));
    creditAuditAssert($c['sourceSummarySha256']===null,'Failed audit produced a summary digest');
};
try {
    $r=$check($fixture);$c=$r['partnerCredit'];
    creditAuditAssert($r['profileMatches'] && $c['status']==='PASSED' && $c['errorCount']===0,'Valid source rejected');
    foreach (['sourceSummarySha256','partnersChecked','chargesChecked','repaymentsChecked','partnersWithDebt','partnersOverdue'] as $field) creditAuditAssert($c[$field]===$expected[$field],'Node FIFO parity '.$field);
    creditAuditAssert($c['rowsChecked']===23 && $r['sha256']===hash('sha256',$fixture),'Coverage/fingerprint');
    // Storage/export order must not change FIFO: timestamps then canonical UUID IDs.
    preg_match_all('/^INSERT INTO `jm_transfer_partner_ledger` .+;$/m',$fixture,$matches);
    $reversed=array_reverse($matches[0]);
    $index=0;$shuffled=preg_replace_callback('/^INSERT INTO `jm_transfer_partner_ledger` .+;$/m',static function()use(&$index,$reversed){return $reversed[$index++];},$fixture);
    creditAuditAssert($check($shuffled)['partnerCredit']['sourceSummarySha256']===$c['sourceSummarySha256'],'Export order changed FIFO');
    // Strict overdue boundary, including Node's millisecond precision.
    foreach ([['2026-01-02T00:00:00.000000Z',0],['2026-01-02T00:00:00.000999Z',0],['2026-01-02T00:00:00.001000Z',1]] as [$date,$count]) creditAuditAssert($check($fixture,new DateTimeImmutable($date))['partnerCredit']['partnersOverdue']===$count,'Overdue boundary');
    $id=static fn(int $n)=>creditAuditValue('00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT));
    $str=static fn(string $s)=>creditAuditValue($s);
    $cases=[
        ['partner_ledger',1,['kind'=>$str('ADJUSTMENT')],'UNKNOWN_LEDGER_KIND'],
        ['partner_ledger',1,['amount'=>'-1000'],'INVALID_CHARGE_SHAPE'],
        ['partner_ledger',1,['amount'=>'1001'],'CHARGE_TOTAL_MISMATCH'],
        ['partner_ledger',1,['amount'=>'9007199254740992'],'UNSAFE_LEDGER_AMOUNT'],
        ['partner_ledger',1,['amount'=>'9223372036854775807'],'UNSAFE_LEDGER_AMOUNT'],
        ['partner_ledger',1,['id'=>$str('PRIVATE-INVALID-ID')],'NONCANONICAL_LEDGER_ID'],
        ['partner_ledger',1,['order_id'=>'NULL'],'INVALID_CHARGE_SHAPE'],
        ['partner_ledger',1,['due_at'=>'NULL'],'INVALID_CHARGE_SHAPE'],
        ['partner_ledger',1,['partner_id'=>$id(2)],'CHARGE_OWNER_MISMATCH'],
        ['partner_ledger',1,['partner_id'=>$str('missing-private')],'MISSING_LEDGER_PARTNER'],
        ['partner_ledger',1,['order_id'=>$str('missing-private')],'CHARGE_OWNER_MISMATCH'],
        ['partner_ledger',1,['reference'=>$str('private-other-code')],'CHARGE_REFERENCE_MISMATCH'],
        ['partner_ledger',2,['order_id'=>$id(11)],'DUPLICATE_ORDER_CHARGE'],
        ['partner_ledger',2,['id'=>$id(111)],'DUPLICATE_CREDIT_KEY'],
        ['partner_ledger',6,['amount'=>'1500'],'INVALID_REPAYMENT_SHAPE'],
        ['partner_ledger',6,['order_id'=>$id(11)],'INVALID_REPAYMENT_SHAPE'],
        ['partner_ledger',6,['due_at'=>$str('2026-01-01 00:00:00.000000')],'INVALID_REPAYMENT_SHAPE'],
        ['partner_ledger',6,['reference'=>$str('missing-private')],'REPAYMENT_REFERENCE_MISMATCH'],
        ['partner_ledger',7,['reference'=>$str('PRIVATE-REPAYMENT-201')],'DUPLICATE_REPAYMENT_REFERENCE'],
        ['partner_orders',1,['partner_id'=>$str('missing-private')],'MISSING_ORDER_OWNER_TARGET'],
        ['orders',1,['payment_status'=>$str('PAID')],'CHARGE_ORDER_STATE_MISMATCH'],
        ['orders',1,['booking_status'=>$str('CANCELLED')],'CHARGE_ORDER_STATE_MISMATCH'],
        ['orders',1,['snapshot'=>$str('{"total":1000.0}')],'CHARGE_TOTAL_MISMATCH'],
        ['orders',1,['snapshot'=>$str('{"total":"1000"}')],'CHARGE_TOTAL_MISMATCH'],
        ['orders',1,['snapshot'=>$str('{"total":9007199254740993}')],'CHARGE_TOTAL_MISMATCH'],
        ['partners',1,['data'=>$str('{"creditLimit":-1}')],'INVALID_CREDIT_LIMIT'],
        ['partners',1,['data'=>$str('{"creditLimit":"1000"}')],'INVALID_CREDIT_LIMIT'],
        ['payment_references',1,['kind'=>$str('ORDER_PAYMENT')],'REPAYMENT_REFERENCE_MISMATCH'],
        ['payment_references',1,['amount'=>'1499'],'REPAYMENT_REFERENCE_MISMATCH'],
        ['payment_references',1,['entity_id'=>$id(2)],'REPAYMENT_REFERENCE_MISMATCH'],
    ];
    foreach ($cases as [$table,$row,$replace,$code]) $failure(creditAuditRow($fixture,$table,$row,$replace),$code);
    $failure(creditAuditEmpty($fixture,'partner_ledger',7),'CREDIT_ORDER_WITHOUT_CHARGE');
    $failure(creditAuditEmpty($fixture,'orders',5),'CHARGE_OWNER_MISMATCH');
    $failure(creditAuditEmpty($fixture,'partner_orders',5),'CHARGE_OWNER_MISMATCH');
    $failure(creditAuditEmpty($fixture,'payment_references',2),'REPAYMENT_REFERENCE_MISMATCH');
    $failure(creditAuditRow($fixture,'partner_ledger',6,['amount'=>'-3001']),'NEGATIVE_PARTNER_BALANCE');
    $overflow=creditAuditRow($fixture,'partner_ledger',1,['amount'=>'9007199254740991']);
    $failure($overflow,'UNSAFE_PARTNER_TOTAL');
    $failure(creditAuditRow($fixture,'partner_ledger',6,['kind'=>$str('CHARGE'),'order_id'=>$id(11)]),'REPAYMENT_REFERENCE_WITHOUT_LEDGER');
    foreach ([[$fixture,1,33554432],[$fixture,100000,1]] as [$sql,$rows,$bytes]) {
        $r=$check($sql,null,$rows,$bytes);creditAuditAssert($r['partnerCredit']['status']==='INCOMPLETE' && $r['partnerCredit']['errors'][0]['code']==='CREDIT_INDEX_LIMIT','Limit must not pass');
    }
    $many=str_replace($matches[0][0],implode("\n",array_fill(0,106,$matches[0][0])),$fixture);
    $many=str_replace(['-- jm_transfer_partner_ledger: 7 rows','"jinmemo_partner_ledger":7'],['-- jm_transfer_partner_ledger: 112 rows','"jinmemo_partner_ledger":112'],$many);
    $r=$check($many);creditAuditAssert($r['partnerCredit']['errorCount']===105 && count($r['partnerCredit']['errors'])===100,'Diagnostics not bounded');
    foreach ([$fixture."DROP TABLE jingo_orders;\n",str_replace('-- TRANSFER COMPLETE ','-- INCOMPLETE ',$fixture),str_replace('`amount` BIGINT NULL','`amount` DOUBLE NULL',$fixture)] as $sql) {
        $r=$check($sql);creditAuditAssert(!$r['profileMatches'] && $r['partnerCredit']['status']==='SKIPPED','Unsafe/incomplete file got financial verdict');
    }
    $empty=$fixture;
    foreach (['partners'=>4,'orders'=>5,'partner_orders'=>5,'partner_ledger'=>7,'payment_references'=>2] as $table=>$count) $empty=creditAuditEmpty($empty,$table,$count);
    $r=$check($empty);creditAuditAssert($r['partnerCredit']['status']==='PASSED' && $r['partnerCredit']['partnersChecked']===0,'Empty full export');
    $cli=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/check-partner-credit-transfer.php');
    foreach ([[$fixture,'2026-11-18T00:00:00.000000Z',0],[$overflow,'2026-11-18T00:00:00.000000Z',2],[$fixture,'2026-02-30T00:00:00.000000Z',1],[$fixture,'2026-11-18T00:00:00Z',1]] as [$sql,$date,$expectedExit]) {
        file_put_contents($path,$sql);$output=[];exec($cli.' '.escapeshellarg('--file='.$path).' '.escapeshellarg('--as-of='.$date).' 2>&1',$output,$exit);
        creditAuditAssert($exit===$expectedExit,'CLI exit '.$exit.' '.implode("\n",$output));
    }
    echo "Partner credit transfer audit passed: original Node FIFO digest, ownership/totals, charge coverage, repayment references, exact safe integers, zero charge, overdue boundaries, limits, privacy and CLI. No database or import.\n";
} finally { unlink($path); }
