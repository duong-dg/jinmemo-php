<?php
declare(strict_types=1);
require_once __DIR__.'/TransferAudit.php';

/** Source-only financial checks. Does not execute SQL, import, or unlock accounts. */
final class PartnerCreditTransferAudit
{
    private const SAFE = 9007199254740991;
    private const TABLES = ['partners','orders','partner_orders','partner_ledger','payment_references'];

    public function __construct(private int $maxRows = 100000, private int $maxBytes = 33554432)
    {
        if ($maxRows < 1 || $maxRows > 100000 || $maxBytes < 1 || $maxBytes > 33554432) throw new InvalidArgumentException('Invalid credit audit limits.');
        if (PHP_INT_SIZE < 8) throw new RuntimeException('Credit audit requires 64-bit PHP.');
    }

    public function run(string $path, DateTimeImmutable $asOf): array
    {
        $asOf = $asOf->setTimezone(new DateTimeZone('UTC'));
        $result = ['scope'=>'SOURCE_PARTNER_CREDIT_ONLY','status'=>'SKIPPED','asOf'=>$asOf->format('Y-m-d\TH:i:s.u\Z'),
            'rowsChecked'=>0,'partnersChecked'=>0,'chargesChecked'=>0,'repaymentsChecked'=>0,
            'partnersWithDebt'=>0,'partnersOverdue'=>0,'sourceSummarySha256'=>null,'errors'=>[],'errorCount'=>0,
            'unchecked'=>['target_database_and_import','order_actions_and_request_hashes','inventory_and_ticket_files','refunds_and_other_payments','credential_reset_and_account_activation']];
        $error = static function(string $code, ?string $table=null, ?int $row=null) use (&$result): void {
            $result['errorCount']++;
            if (count($result['errors']) < 100) $result['errors'][] = array_filter(['code'=>$code,'table'=>$table,'row'=>$row],static fn($v)=>$v!==null);
        };
        $records = []; $bytes = 0; $limited = false;
        $report = (new TransferAudit())->run($path, function(string $table,array $columns,array $values,int $line,int $row) use (&$records,&$bytes,&$limited,&$result,$error): void {
            $name = substr($table,12);
            if (!in_array($name,self::TABLES,true) || $limited) return;
            $bytes += array_sum(array_map('strlen',$values));
            if ($result['rowsChecked'] >= $this->maxRows || $bytes > $this->maxBytes) {
                $limited=true; $error('CREDIT_INDEX_LIMIT'); return;
            }
            $result['rowsChecked']++;
            $raw = array_combine(array_column($columns,'name'),array_map([self::class,'decode'],$values));
            $keyColumn = ['partner_orders'=>'order_id','payment_references'=>'reference'][$name] ?? 'id';
            $key = self::key($raw[$keyColumn]);
            if (isset($records[$name][$key])) { $error('DUPLICATE_CREDIT_KEY',$table,$row); return; }
            // Keep only the fields needed for reconciliation, never contact/secrets/notes.
            $fields = match($name) {
                'partners'=>['data'], 'orders'=>['code','snapshot','payment_status','booking_status'],
                'partner_orders'=>['partner_id'], 'partner_ledger'=>['id','partner_id','order_id','kind','amount','reference','due_at','created_at'],
                'payment_references'=>['kind','entity_id','amount'],
            };
            $record = array_intersect_key($raw,array_flip($fields));
            $record['row']=$row;
            if ($name==='partners') {
                $data=json_decode($raw['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                $record['limit']=is_array($data)?($data['creditLimit'] ?? null):null;
                unset($record['data']);
            } elseif ($name==='orders') {
                $data=json_decode($raw['snapshot'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                $record['total']=is_array($data)?($data['total'] ?? null):null;
                unset($record['snapshot']);
                $record['code']=self::key($record['code']);
            }
            foreach (['partner_id','order_id','reference','entity_id'] as $field) if (array_key_exists($field,$record) && $record[$field]!==null) $record[$field]=self::key($record[$field]);
            $records[$name][$key]=$record;
        });
        $report['partnerCredit']=$result;
        if (!$report['profileMatches']) return $report;
        if ($limited) { $result['status']='INCOMPLETE'; $report['partnerCredit']=$result; return $report; }
        $partners=$records['partners'] ?? []; $orders=$records['orders'] ?? [];
        $owners=$records['partner_orders'] ?? []; $ledger=$records['partner_ledger'] ?? [];
        $references=$records['payment_references'] ?? []; $entries=[]; $charges=[]; $usedReferences=[];
        foreach ($partners as $key=>$p) {
            $result['partnersChecked']++;
            if (!is_int($p['limit']) || $p['limit']<0 || $p['limit']>1000000000000) $error('INVALID_CREDIT_LIMIT','jm_transfer_partners',$p['row']);
        }
        foreach ($owners as $key=>$owner) {
            if (!isset($orders[$key]) || !isset($partners[$owner['partner_id']])) $error('MISSING_ORDER_OWNER_TARGET','jm_transfer_partner_orders',$owner['row']);
        }
        foreach ($ledger as $entry) {
            $table='jm_transfer_partner_ledger'; $row=$entry['row']; $partner=$entry['partner_id']; $order=$entry['order_id'];
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$entry['id'])) $error('NONCANONICAL_LEDGER_ID',$table,$row);
            if (!isset($partners[$partner])) $error('MISSING_LEDGER_PARTNER',$table,$row);
            $amount=self::money($entry['amount']);
            if ($amount===null) { $error('UNSAFE_LEDGER_AMOUNT',$table,$row); continue; }
            $entry['amount']=$amount;
            if ($entry['kind']==='CHARGE') {
                $result['chargesChecked']++;
                if ($amount<0 || $order===null || $entry['due_at']===null) $error('INVALID_CHARGE_SHAPE',$table,$row);
                if ($order!==null) {
                    if (isset($charges[$order])) $error('DUPLICATE_ORDER_CHARGE',$table,$row);
                    $charges[$order]=true;
                    $o=$orders[$order] ?? null;
                    if ($o===null || ($owners[$order]['partner_id'] ?? null)!==$partner) $error('CHARGE_OWNER_MISMATCH',$table,$row);
                    if ($o!==null && (!is_int($o['total']) || $o['total']<0 || $o['total']>self::SAFE || $amount!==$o['total'])) $error('CHARGE_TOTAL_MISMATCH',$table,$row);
                    if ($o!==null && ($o['payment_status']!=='CREDIT' || $o['booking_status']!=='CONFIRMED')) $error('CHARGE_ORDER_STATE_MISMATCH',$table,$row);
                    if ($o!==null && $o['code']!==$entry['reference']) $error('CHARGE_REFERENCE_MISMATCH',$table,$row);
                }
            } elseif ($entry['kind']==='REPAYMENT') {
                $result['repaymentsChecked']++;
                if ($amount>=0 || $order!==null || $entry['due_at']!==null) $error('INVALID_REPAYMENT_SHAPE',$table,$row);
                $ref=$entry['reference']; $r=$references[$ref] ?? null;
                if (isset($usedReferences[$ref])) $error('DUPLICATE_REPAYMENT_REFERENCE',$table,$row);
                $usedReferences[$ref]=true;
                if ($r===null || $r['kind']!=='PARTNER_REPAYMENT' || $r['entity_id']!==$partner || self::money($r['amount'])!==-$amount) $error('REPAYMENT_REFERENCE_MISMATCH',$table,$row);
            } else $error('UNKNOWN_LEDGER_KIND',$table,$row);
            $entries[$partner][]=$entry;
        }
        foreach ($orders as $key=>$order) if ($order['payment_status']==='CREDIT' && (!isset($charges[$key]) || !isset($owners[$key]))) $error('CREDIT_ORDER_WITHOUT_CHARGE','jm_transfer_orders',$order['row']);
        foreach ($references as $key=>$ref) if ($ref['kind']==='PARTNER_REPAYMENT' && !isset($usedReferences[$key])) $error('REPAYMENT_REFERENCE_WITHOUT_LEDGER','jm_transfer_payment_references',$ref['row']);
        $summaries=[];
        foreach ($partners as $partner=>$account) {
            $rows=$entries[$partner] ?? [];
            // Match Node's ORDER BY created_at,id before applying all repayments FIFO.
            usort($rows,static fn($a,$b)=>strcmp($a['created_at'],$b['created_at']) ?: strcmp($a['id'],$b['id']));
            $positive=0; $settled=0; $overflow=false;
            foreach ($rows as $e) {
                $value=abs($e['amount']); $sum=$e['amount']<0?$settled:$positive;
                if ($value>self::SAFE-$sum) { $overflow=true; break; }
                if ($e['amount']<0) $settled+=$value; else $positive+=$value;
            }
            if ($overflow) { $error('UNSAFE_PARTNER_TOTAL'); continue; }
            if ($settled>$positive) { $error('NEGATIVE_PARTNER_BALANCE'); continue; }
            if ($positive>$settled) $result['partnersWithDebt']++;
            $balance=$positive-$settled; $overdue=0; $chargeSummary=[];
            foreach ($rows as $e) if ($e['amount']>0) {
                $applied=min($settled,$e['amount']); $settled-=$applied;
                $outstanding=$e['amount']-$applied;
                // Node Date/pg timestamp parsing compares milliseconds, not sub-millisecond digits.
                if ($outstanding>0 && $e['due_at']!==null && strcmp(substr($e['due_at'],0,23),substr($asOf->format('Y-m-d H:i:s.u'),0,23))<0) $overdue+=$outstanding;
                $chargeSummary[]=[$e['order_id'],$e['amount'],$outstanding,$e['due_at']];
            }
            if ($overdue>0) $result['partnersOverdue']++;
            $summaries[$partner]=[$partner,$balance,$overdue,$chargeSummary];
        }
        $result['status']=$result['errorCount']===0?'PASSED':'FAILED';
        if ($result['status']==='PASSED') {
            ksort($summaries,SORT_STRING);
            $result['sourceSummarySha256']=hash('sha256',json_encode(array_values($summaries),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        }
        $report['partnerCredit']=$result;
        return $report;
    }

    private static function key(string $value): string { return hash('sha256',$value); }
    private static function decode(string $raw): ?string
    {
        if ($raw==='NULL') return null;
        return str_starts_with($raw,'CONVERT(')?hex2bin(substr($raw,10,-16)):$raw;
    }
    private static function money(string $value): ?int
    {
        $digits=ltrim($value,'-0');
        if (strlen($digits)>16 || (strlen($digits)===16 && strcmp($digits,(string)self::SAFE)>0)) return null;
        return (int)$value;
    }
}
