<?php
declare(strict_types=1);
require_once __DIR__.'/AdminAuth.php';
require_once __DIR__.'/Pricing.php';
require_once __DIR__.'/Vouchers.php';

/** Imports historical use rows only after their orders/vouchers match. Never activates vouchers. */
final class VoucherUseTransfer
{
    private const TABLES=['orders','vouchers','voucher_uses','partner_orders','pos_orders'];
    private array $errors=[];
    private int $errorCount=0;
    private int $bytes=0;
    private array $profile;
    public function __construct(private PDO $db)
    {
        $this->profile=json_decode((string)file_get_contents(dirname(__DIR__).'/docs/TRANSFER-EXPORT-SCHEMA.json'),true,512,JSON_THROW_ON_ERROR)['tables'];
        if(PHP_INT_SIZE<8)throw new RuntimeException('64-bit PHP required.');
    }
    private static function identifier(string $s):string
    {
        if(!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}$/D',$s))throw new InvalidArgumentException('Tên database không hợp lệ.');return '`'.$s.'`';
    }
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function error(string $table,int $row,string $code):void
    {
        $this->errorCount++;if(count($this->errors)<100)$this->errors[]=['table'=>$table,'row'=>$row,'code'=>$code];
    }
    private static function stable(mixed $v):mixed
    {
        if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=self::stable($x);unset($x);return(object)$a;}
        return is_array($v)?array_map([self::class,'stable'],$v):$v;
    }
    private static function timestamp(string $s):string
    {
        if(preg_match('/^[1-9][0-9]{3}-\d\d-\d\d \d\d:\d\d:\d\d(?:\.\d{1,6})?$/D',$s)!==1)throw new RuntimeException('Invalid timestamp.');
        $s=str_contains($s,'.')?str_pad($s,26,'0'):$s.'.000000';$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$s,new DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d H:i:s.u')!==$s)throw new RuntimeException('Invalid timestamp.');return $s;
    }
    private static function canonical(array $r):string
    {
        unset($r['transfer_pending']);
        foreach(['snapshot','contact','data'] as $key)if(isset($r[$key]))$r[$key]=json_decode($r[$key],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if(isset($r['created_at']))$r['created_at']=self::timestamp($r['created_at']);
        foreach(['version','released'] as $key)if(isset($r[$key]))$r[$key]=(string)$r[$key];
        return json_encode(self::stable((object)$r),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    private function schema(string $database,string $table,array $columns):void
    {
        $s=$this->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$database,$table])->fetch(PDO::FETCH_ASSOC);
        $actual=$this->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$database,$table])->fetchAll(PDO::FETCH_COLUMN);sort($actual);sort($columns);
        if(!$s||$s['TABLE_TYPE']!=='BASE TABLE'||strtoupper($s['ENGINE']??'')!=='INNODB'||$actual!==$columns)$this->error($table,0,'SCHEMA_MISMATCH');
    }
    private function rows(string $database,string $table,string $lock=''):array
    {
        $old=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=null;$rows=[];$count=0;
        try{
            $q=$this->query('SELECT * FROM '.self::identifier($database).'.'.self::identifier($table).$lock);
            while($r=$q->fetch(PDO::FETCH_ASSOC)){
                $this->bytes+=strlen(json_encode($r,JSON_THROW_ON_ERROR));
                if(++$count>50000||$this->bytes>33554432)throw new RuntimeException('Voucher use metadata limit.');
                $key=strtolower((string)$r[in_array($table,['jm_transfer_vouchers','jingo_vouchers','jm_transfer_orders','jingo_orders'],true)?'id':'order_id']);
                if(isset($rows[$key])){$this->error($table,$count,'DUPLICATE_KEY');continue;}
                $rows[$key]=$r;
            }
        }finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$old);}return $rows;
    }
    private static function integer(mixed $v):bool{return is_int($v)&&$v>=0&&$v<=9007199254740991;}
    public function run(string $source,bool $apply=false,?string $fingerprint=null):array
    {
        self::identifier($source);$target=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();self::identifier($target);
        if(strcasecmp($source,$target)===0)throw new InvalidArgumentException('Staging phải khác database PHP.');
        if($apply&&(!is_string($fingerprint)||preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1))throw new InvalidArgumentException('Cần fingerprint từ dry-run trước --apply.');
        $this->errors=[];$this->errorCount=0;$this->bytes=0;
        $report=['scope'=>'voucher-use-history-only','sourceDatabase'=>$source,'targetDatabase'=>$target,'mode'=>$apply?'apply':'dry-run','ready'=>false,'applied'=>false,'fingerprint'=>null,
            'counts'=>['sourceRows'=>0,'newRows'=>0,'matchingRows'=>0,'insertedRows'=>0,'activeRows'=>0,'releasedRows'=>0],
            'errors'=>[],'errorCount'=>0,'voucherAccessPolicy'=>'PRESERVE_PENDING_NO_ACTIVATION','notImported'=>['orders','voucher_metadata','payments','inventory','partner_ledger','credential_or_voucher_activation']];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            foreach(self::TABLES as $table){
                $cols=array_column($this->profile['jm_transfer_'.$table],'name');
                $this->schema($source,'jm_transfer_'.$table,$cols);$this->schema($target,'jingo_'.$table,$table==='vouchers'?[...$cols,'transfer_pending']:$cols);
            }
            foreach(['jingo_audit','jingo_catalog_locks'] as $table){$s=$this->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,$table])->fetch(PDO::FETCH_ASSOC);if(!$s||$s['TABLE_TYPE']!=='BASE TABLE'||strtoupper($s['ENGINE']??'')!=='INNODB')$this->error($table,0,'SCHEMA_MISMATCH');}
            // Existing orders must be able to retain the source's UTC microseconds.
            $precision=$this->query("SELECT DATA_TYPE,DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='jingo_orders' AND COLUMN_NAME='created_at'",[$target])->fetch(PDO::FETCH_ASSOC);
            if(!$precision||$precision['DATA_TYPE']!=='datetime'||(int)$precision['DATETIME_PRECISION']!==6)$this->error('jingo_orders',0,'ORDER_TIME_PRECISION_MISMATCH');
            foreach($this->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='jingo_voucher_uses'",[$target])->fetchAll(PDO::FETCH_ASSOC) as $column){
                $ok=$column['COLUMN_NAME']==='released'?$column['DATA_TYPE']==='tinyint':in_array($column['DATA_TYPE'],['char','varchar'],true)&&(int)$column['CHARACTER_MAXIMUM_LENGTH']>=36;
                if(!$ok||$column['IS_NULLABLE']!=='NO')$this->error('voucher_uses',0,'RUNTIME_CAPACITY_MISMATCH');
            }
            $primary=$this->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='jingo_voucher_uses' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX",[$target])->fetchAll(PDO::FETCH_COLUMN);
            if($primary!==['order_id'])$this->error('voucher_uses',0,'RUNTIME_PRIMARY_KEY_MISMATCH');
            if($this->errors){$report['errors']=$this->errors;$report['errorCount']=$this->errorCount;$this->db->rollBack();return $report;}
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".($apply?' FOR UPDATE':''))->fetchColumn())throw new RuntimeException('Missing catalog lock.');
            $src=[];$dst=[];$hashes=['scope:voucher-use-history-only'];
            // Orders before vouchers matches cancellation's row lock order. Import in a drained maintenance window.
            foreach(self::TABLES as $table){
                $src[$table]=$this->rows($source,'jm_transfer_'.$table,$apply?' LOCK IN SHARE MODE':'');
                $dst[$table]=$this->rows($target,'jingo_'.$table,$apply?' FOR UPDATE':'');
                foreach($src[$table] as $r)$hashes[]='source:'.$table.':'.hash('sha256',self::canonical($r));
            }
            $codes=[];$validVouchers=[];
            foreach($src['vouchers'] as $key=>$v){
                try{
                    $data=json_decode($v['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$body=$data;$body['version']=(int)$v['version'];$validated=Vouchers::validate($body);
                    if(($data['id']??null)!==$v['id']||($data['code']??null)!==$v['code']||$validated['code']!==$v['code']||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$v['version'])||(int)$v['version']>2147483646)throw new RuntimeException();
                    if(isset($codes[$v['code']]))throw new RuntimeException();$codes[$v['code']]=$key;$validVouchers[$key]=$validated;
                }catch(Throwable){$this->error('vouchers',0,'INVALID_VOUCHER_METADATA');}
                $existing=$dst['vouchers'][$key]??null;
                if(!$existing||self::canonical($existing)!==self::canonical($v))$this->error('vouchers',0,'VOUCHER_METADATA_NOT_MATCHED');
                if($existing)$hashes[]='target:vouchers:'.hash('sha256',self::canonical($existing));
            }
            $pending=[];$active=[];
            foreach($src['voucher_uses'] as $key=>$use){
                $row=++$report['counts']['sourceRows'];$voucherKey=strtolower((string)$use['voucher_id']);
                foreach(['order_id','voucher_id'] as $field)if(!is_string($use[$field])||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$use[$field]))$this->error('voucher_uses',$row,'INVALID_USE_ID');
                if(!in_array((string)$use['released'],['0','1'],true)){$this->error('voucher_uses',$row,'INVALID_RELEASED_FLAG');continue;}
                $released=(int)$use['released']===1;$report['counts'][$released?'releasedRows':'activeRows']++;
                if(!$released)$active[$voucherKey]=($active[$voucherKey]??0)+1;
                $voucher=$src['vouchers'][$voucherKey]??null;$order=$src['orders'][$key]??null;
                if(!$voucher||$use['voucher_id']!==$voucher['id']||!$order||$use['order_id']!==$order['id']){$this->error('voucher_uses',$row,'MISSING_SOURCE_PARENT');continue;}
                if(isset($src['partner_orders'][$key])||isset($src['pos_orders'][$key])||isset($dst['partner_orders'][$key])||isset($dst['pos_orders'][$key]))$this->error('voucher_uses',$row,'NON_WEBSITE_VOUCHER_ORDER');
                try{
                    $snapshot=json_decode($order['snapshot'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$discount=$snapshot['discount']??null;
                    if(!is_array($discount)||array_diff(array_keys($discount),['code','amount','subtotal'])||($discount['code']??null)!==$voucher['code']||!self::integer($discount['amount']??null)||!self::integer($discount['subtotal']??null)||$discount['subtotal']<1||!self::integer($snapshot['total']??null)||$discount['amount']>$discount['subtotal']||$snapshot['total']!==$discount['subtotal']-$discount['amount'])throw new RuntimeException();
                    if(!in_array($order['booking_status'],['REQUESTED','CONFIRMED','CANCELLED'],true)||!in_array($order['payment_status'],['UNPAID','PAID'],true)||$released!==($order['booking_status']==='CANCELLED')||$released&&$order['payment_status']!=='UNPAID')throw new RuntimeException();
                }catch(Throwable){$this->error('voucher_uses',$row,'ORDER_DISCOUNT_OR_STATE_MISMATCH');}
                $existingOrder=$dst['orders'][$key]??null;
                if(!$existingOrder||self::canonical($existingOrder)!==self::canonical($order))$this->error('voucher_uses',$row,'ORDER_NOT_IMPORTED_OR_DIFFERS');
                if($existingOrder)$hashes[]='target:orders:'.hash('sha256',self::canonical($existingOrder));
                $existing=$dst['voucher_uses'][$key]??null;
                if($existing){if(self::canonical($existing)!==self::canonical($use))$this->error('voucher_uses',$row,'EXISTING_USE_DIFFERS');else$report['counts']['matchingRows']++;}
                else{
                    $report['counts']['newRows']++;$pending[]=$use;
                    if((int)($dst['vouchers'][$voucherKey]['transfer_pending']??0)===0)$this->error('voucher_uses',$row,'VOUCHER_NOT_PENDING');
                }
            }
            // Check the reverse links too: no discount snapshot may silently lose its use row.
            foreach($src['orders'] as $key=>$order){
                $snapshot=json_decode($order['snapshot'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                if(is_array($snapshot)&&array_key_exists('discount',$snapshot)&&!isset($src['voucher_uses'][$key]))$this->error('orders',0,'DISCOUNT_WITHOUT_USE');
            }
            foreach($dst['voucher_uses'] as $key=>$use)if(isset($src['vouchers'][strtolower($use['voucher_id'])])){
                if(!isset($src['voucher_uses'][$key]))$this->error('voucher_uses',0,'EXTRA_RUNTIME_USE');
            }
            foreach($dst['orders'] as $key=>$order){
                $snapshot=json_decode($order['snapshot'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$code=$snapshot['discount']['code']??null;
                if(is_string($code)&&isset($codes[$code])&&!isset($src['voucher_uses'][$key]))$this->error('orders',0,'EXTRA_RUNTIME_DISCOUNT_ORDER');
            }
            foreach($validVouchers as $key=>$v)if($v['maxUses']!==null&&($active[$key]??0)>$v['maxUses'])$this->error('vouchers',0,'SOURCE_USAGE_EXCEEDS_LIMIT');
            // Runtime rows/flags are separately locked/revalidated; the token represents immutable source + parent metadata.
            // Exclude use rows because an exact replay must accept the original dry-run token.
            sort($hashes,SORT_STRING);
            $report['fingerprint']=hash('sha256',implode("\n",$hashes));
            if($apply&&!hash_equals($fingerprint,$report['fingerprint']))$this->error('voucher_uses',0,'FINGERPRINT_MISMATCH');
            $report['errors']=$this->errors;$report['errorCount']=$this->errorCount;$report['ready']=$this->errorCount===0;
            if(!$apply||!$report['ready']){$this->db->rollBack();return $report;}
            $insert=$this->db->prepare('INSERT INTO jingo_voucher_uses(order_id,voucher_id,released) VALUES (?,?,?)');
            foreach($pending as $use)$insert->execute([$use['order_id'],$use['voucher_id'],(int)$use['released']]);
            if($pending)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','voucher-uses.import','voucher-uses:'.$report['fingerprint']]);
            $this->db->commit();$report['counts']['insertedRows']=count($pending);$report['applied']=true;return $report;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
