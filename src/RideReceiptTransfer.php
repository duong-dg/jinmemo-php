<?php
declare(strict_types=1);

/** Historical SePay receipts only. Does not replay settlement or manufacture missing QR attempts. */
final class RideReceiptTransfer
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public function schemaCompatible():bool
    {
        $specs=[
            'gateway_events'=>['id'=>['string',200,'ascii'], 'attempt_id'=>['string',27,'ascii',true], 'order_id'=>['string',36,'ascii',true], 'provider'=>['string',20,'ascii'], 'mode'=>['string',4,'ascii'], 'amount'=>['bigint'], 'status'=>['string',12,'ascii'], 'reason'=>['text',65535,'utf8mb4'], 'fingerprint'=>['string',64,'ascii'], 'created_at'=>['datetime']],
            'payment_references'=>['reference_hash'=>['string',64,'ascii'], 'reference'=>['string',41,'utf8mb4'], 'kind'=>['string',12], 'entity_id'=>['string',36,'ascii'], 'amount'=>['bigint'], 'created_at'=>['datetime']],
            'ride_payment_events'=>['event_id'=>['string',200,'ascii'], 'ride_id'=>['string',36,'ascii']],
        ];
        foreach($specs as $table=>$spec){$cols=array_column($this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,IS_NULLABLE,DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['jingo_'.$table])->fetchAll(PDO::FETCH_ASSOC),null,'COLUMN_NAME');
            foreach($spec as $key=>$rule){$c=$cols[$key]??null;if(!$c||$c['IS_NULLABLE']!==(!empty($rule[3])?'YES':'NO'))return false;$ok=match($rule[0]){'string'=>in_array($c['DATA_TYPE'],['char','varchar'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']>=$rule[1]&&(!isset($rule[2])||$c['CHARACTER_SET_NAME']===$rule[2]),'text'=>in_array($c['DATA_TYPE'],['text','mediumtext','longtext'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']>=$rule[1]&&$c['CHARACTER_SET_NAME']===$rule[2],'bigint'=>$c['DATA_TYPE']==='bigint','datetime'=>$c['DATA_TYPE']==='datetime'&&(int)$c['DATETIME_PRECISION']===6,default=>false};if(!$ok)return false;}
            $primary=$this->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX",['jingo_'.$table])->fetchAll(PDO::FETCH_COLUMN);if($primary!==[array_key_first($spec)])return false;
        }return true;
    }
    public static function prepare(array $tables,array $rides):array
    {
        $result=[];$events=array_column($tables['gateway_events']??[],null,'id');$refs=[];$links=[];$resolved=[];$resolvedCodes=[];$owners=[];
        foreach($tables['payment_references']??[] as $r)$refs[$r['entity_id']][]=$r;
        foreach($tables['ride_payment_events']??[] as $r){$links[$r['ride_id']][]=$r;$owners[$r['event_id']][$r['ride_id']]=true;}
        foreach($tables['gateway_resolutions']??[] as $r){$resolved[$r['event_id']]=true;$resolvedCodes[$r['target_code']]=true;}
        foreach($rides as $ride){
            $d=json_decode($ride['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(($d['paymentStatus']??'UNPAID')!=='PAID')continue;
            $reference=$d['paymentReference']??null;$receipt=$refs[$ride['id']]??[];$linked=$links[$ride['id']]??[];$e=is_string($reference)?($events[$reference]??null):null;
            if(!is_string($reference)||!preg_match('/^SEPAY:LIVE:[0-9]{1,30}$/D',$reference)||count($receipt)!==1||count($linked)!==1||!$e||isset($resolved[$reference])||isset($resolvedCodes[$ride['code']]))throw new RuntimeException('PAID_RIDE_RECEIPT_GRAPH_INVALID');
            $p=$receipt[0];if($p['reference']!==$reference||$p['kind']!=='RIDE_PAYMENT'||$linked[0]['event_id']!==$reference||$e['provider']!=='SEPAY'||$e['mode']!=='LIVE'||$e['status']!=='PAID'||$e['attempt_id']!==null||$e['order_id']!==null||!preg_match('/^[a-f0-9]{64}$/D',$e['fingerprint'])||strlen($e['reason'])>65535)throw new RuntimeException('PAID_RIDE_RECEIPT_GRAPH_INVALID');
            if(!is_int($d['total']??null)||$d['total']<1||$d['total']>15000000000||($d['paidAmount']??null)!==$d['total']||$p['amount']!==(string)$d['total']||$e['amount']!==$p['amount']||!is_string($d['paidAt']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/D',$d['paidAt']))throw new RuntimeException('PAID_RIDE_AMOUNT_OR_TIME_INVALID');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',str_replace('Z',str_contains($d['paidAt'],'.')?'Z':'.000Z',$d['paidAt']),new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();if(!$date||$errors!==false&&($errors['warning_count']||$errors['error_count']))throw new RuntimeException('PAID_RIDE_AMOUNT_OR_TIME_INVALID');
            if(count($owners[$reference]??[])!==1)throw new RuntimeException('PAID_RIDE_EVENT_SHARED');
            $result[$ride['id']]=['receipt'=>$p,'event'=>$e,'link'=>$linked[0],'code'=>$ride['code']];
        }
        return $result;
    }
    private static function same(array $target,array $source):bool{foreach($source as $key=>$value)if(!array_key_exists($key,$target)||($value===null?$target[$key]!==null:(string)$target[$key]!==$value))return false;return true;}
    /** Returns immutable target state, errors and rows to insert; caller holds the catalog lock. */
    public function inspect(array $graph,array $existing,bool $lock):array
    {
        $suffix=$lock?' FOR UPDATE':'';$errors=[];$state=[];$new=[];$bytes=0;
        foreach($graph as $id=>$g){
            $p=$g['receipt'];$e=$g['event'];$hash=hash('sha256',$p['reference']);
            $refs=$this->query('SELECT * FROM jingo_payment_references WHERE entity_id=? OR reference_hash=? ORDER BY reference_hash LIMIT 3'.$suffix,[$id,$hash])->fetchAll(PDO::FETCH_ASSOC);
            $event=$this->query('SELECT * FROM jingo_gateway_events WHERE id=?'.$suffix,[$e['id']])->fetch(PDO::FETCH_ASSOC);
            $links=$this->query('SELECT event_id,ride_id FROM jingo_ride_payment_events WHERE ride_id=? OR event_id=? ORDER BY event_id,ride_id LIMIT 3'.$suffix,[$id,$e['id']])->fetchAll(PDO::FETCH_ASSOC);
            $resolution=$this->query('SELECT event_id,target_code FROM jingo_gateway_resolutions WHERE event_id=? OR target_code=? ORDER BY event_id LIMIT 2'.$suffix,[$e['id'],$g['code']])->fetchAll(PDO::FETCH_ASSOC);
            $entry=[$refs,$event?:null,$links,$resolution?:null];if(($bytes+=strlen(json_encode($entry,JSON_THROW_ON_ERROR)))>67108864)throw new RuntimeException('Paid ride target evidence limit.');$state[$id]=$entry;
            if(!$refs&&!$event&&!$links&&!$resolution&&!isset($existing[$id])){$new[$id]=$g;continue;}
            if(!isset($existing[$id])||count($refs)!==1||!self::same($refs[0],$p)||$refs[0]['reference_hash']!==$hash||!$event||!self::same($event,$e)||count($links)!==1||!self::same($links[0],$g['link'])||$resolution)$errors[]='RUNTIME_PAID_RIDE_GRAPH_DIFFERS';
        }
        return ['state'=>$state,'errors'=>$errors,'new'=>$new];
    }
    public function insert(array $graph):void
    {
        if(!$this->db->inTransaction())throw new LogicException('Historical ride receipts require the import transaction.');
        foreach($graph as $g){$e=$g['event'];$p=$g['receipt'];$keys=['id','attempt_id','order_id','provider','mode','amount','status','reason','fingerprint','created_at'];$this->query('INSERT INTO jingo_gateway_events('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_map(static fn($k)=>$e[$k],$keys));$this->query('INSERT INTO jingo_payment_references(reference_hash,reference,kind,entity_id,amount,created_at) VALUES (?,?,?,?,?,?)',[hash('sha256',$p['reference']),$p['reference'],$p['kind'],$p['entity_id'],$p['amount'],$p['created_at']]);$this->query('INSERT INTO jingo_ride_payment_events(event_id,ride_id) VALUES (?,?)',[$g['link']['event_id'],$g['link']['ride_id']]);}
    }
}
