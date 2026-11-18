<?php
declare(strict_types=1);
require_once __DIR__.'/AdminAuth.php';
require_once __DIR__.'/Pricing.php';


/** Holds validated order cores separately until the full financial/inventory graph can be published. */
final class OrderCoreTransfer
{
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
                if(++$count>50000||$this->bytes>33554432)throw new RuntimeException('Order core metadata limit.');
                $key=strtolower((string)$r['id']);
                if(isset($rows[$key])){$this->error($table,$count,'DUPLICATE_KEY');continue;}
                $rows[$key]=$r;
            }
        }finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$old);}return $rows;
    }
    private static function integer(mixed $v):bool{return is_int($v)&&$v>=0&&$v<=9007199254740991;}
    private static function text(mixed $v,int $min,int $max):bool{return is_string($v)&&!str_contains($v,"\0")&&preg_match('//u',$v)===1&&mb_strlen($v,'UTF-8')>=$min&&mb_strlen($v,'UTF-8')<=$max;}
    private static function uuid(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$v)===1;}
    public static function validateRecord(array $row):void{self::valid($row);}
    private static function valid(array $r):void
    {
        if(!self::uuid($r['id'])||!self::uuid($r['request_id'])||!preg_match('/^(?:JM|JG)[A-F0-9]{10}$/D',$r['code'])||!preg_match('/^[a-f0-9]{64}$/D',$r['request_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$r['access_hash'])||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$r['version'])||(int)$r['version']>2147483646||!self::text($r['instructions'],0,65535)||strlen($r['instructions'])>65535)throw new RuntimeException();
        self::timestamp($r['created_at']);
        if(!in_array($r['booking_status'],['REQUESTED','CONFIRMED','CANCELLED'],true)||!in_array($r['payment_status'],['UNPAID','PAID','CREDIT'],true)||$r['booking_status']!=='CONFIRMED'&&$r['payment_status']!=='UNPAID')throw new RuntimeException();
        if(!is_string($r['snapshot'])||!is_string($r['contact'])||strlen($r['snapshot'])>1048576||strlen($r['contact'])>65536)throw new RuntimeException();
        if(!(json_decode($r['snapshot'],false,512,JSON_THROW_ON_ERROR) instanceof stdClass)||!(json_decode($r['contact'],false,512,JSON_THROW_ON_ERROR) instanceof stdClass))throw new RuntimeException();
        $s=json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$c=json_decode($r['contact'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        foreach(['name'=>200,'email'=>320,'phone'=>40] as $key=>$max)if(!self::text($c[$key]??null,1,$max))throw new RuntimeException();
        if(!is_string($s['productId']??null)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$s['productId'])||array_key_exists('variantId',$s)&&(!is_string($s['variantId'])||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$s['variantId']))||array_key_exists('locale',$s)&&!in_array($s['locale'],['vi','en'],true)||!self::text($s['productName']??null,1,500)||!self::text($s['variantName']??null,1,500)||!is_string($s['date']??null)||!is_bool($s['demo']??null)||!self::integer($s['total']??null)||!self::text($s['policy']??null,0,20000))throw new RuntimeException();
        Pricing::validateDate($s['date']);
        if(!is_array($s['lines']??null)||!array_is_list($s['lines'])||count($s['lines'])<1||count($s['lines'])>6)throw new RuntimeException();
        $sum=0;$original=0;$guests=0;$seen=[];
        foreach($s['lines'] as $line){
            if(!is_array($line)||!in_array($line['customerType']??null,Pricing::GROUPS,true)||isset($seen[$line['customerType']])||!is_int($line['quantity']??null)||$line['quantity']<1||$line['quantity']>50||!self::integer($line['unitPrice']??null)||!self::integer($line['subtotal']??null)||$line['unitPrice']>intdiv(Pricing::MAX_SAFE,$line['quantity'])||$line['subtotal']!==$line['unitPrice']*$line['quantity']||$line['subtotal']>Pricing::MAX_SAFE-$sum)throw new RuntimeException();
            $seen[$line['customerType']]=true;$guests+=$line['quantity'];$sum+=$line['subtotal'];$price=$line['originalUnitPrice']??$line['unitPrice'];
            if(!self::integer($price)||$price<$line['unitPrice']||$price>intdiv(Pricing::MAX_SAFE,$line['quantity'])||$price*$line['quantity']>Pricing::MAX_SAFE-$original)throw new RuntimeException();
            if(array_key_exists('originalUnitPrice',$line)||array_key_exists('unitDiscount',$line))if(!self::integer($line['originalUnitPrice']??null)||!self::integer($line['unitDiscount']??null)||$line['unitDiscount']!==$price-$line['unitPrice'])throw new RuntimeException();
            $original+=$price*$line['quantity'];
        }
        if($guests>150)throw new RuntimeException();
        if(array_key_exists('onlineDiscount',$s)){
            $d=$s['onlineDiscount'];if(!is_array($d)||!in_array($d['type']??null,['NONE','PERCENT','AMOUNT'],true)||!self::integer($d['value']??null)||!self::integer($d['amount']??null)||!self::integer($d['subtotal']??null)||$d['subtotal']!==$original||$d['amount']!==$original-$sum||($d['type']==='PERCENT'&&($d['value']<1||$d['value']>100))||($d['type']==='AMOUNT'&&($d['value']<1||$d['value']>100000000))||($d['type']==='NONE'&&($d['value']!==0||$d['amount']!==0)))throw new RuntimeException();
        }elseif($original!==$sum)throw new RuntimeException();
        $discount=0;
        if(array_key_exists('discount',$s)){
            $d=$s['discount'];if(!is_array($d)||!is_string($d['code']??null)||!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/D',$d['code'])||!self::integer($d['amount']??null)||($d['subtotal']??null)!==$sum||$sum<1||$d['amount']>$sum||($s['onlineDiscount']['amount']??0)>0)throw new RuntimeException();$discount=$d['amount'];
        }
        if($s['total']!==$sum-$discount)throw new RuntimeException();
    }
    public function run(string $source,bool $apply=false,?string $fingerprint=null):array
    {
        self::identifier($source);$target=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();self::identifier($target);
        if(strcasecmp($source,$target)===0)throw new InvalidArgumentException('Staging phải khác database PHP.');
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)))throw new InvalidArgumentException('Cần fingerprint từ dry-run trước --apply.');
        $this->errors=[];$this->errorCount=0;$this->bytes=0;
        $report=['scope'=>'order-core-holding-only','mode'=>$apply?'apply':'dry-run','sourceDatabase'=>$source,'targetDatabase'=>$target,'ready'=>false,'applied'=>false,'operationalReady'=>false,'fingerprint'=>null,
            'counts'=>['sourceRows'=>0,'newRows'=>0,'matchingRows'=>0,'matchingLiveRows'=>0,'insertedRows'=>0],'errors'=>[],'errorCount'=>0,
            'notImported'=>['live_orders','order_actions','order_payments','channel_links','voucher_uses','inventory','stock','tickets_and_files','mail','partner_ledger','activation']];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            $columns=array_column($this->profile['jm_transfer_orders'],'name');
            $this->schema($source,'jm_transfer_orders',$columns);foreach(['jingo_transfer_orders','jingo_orders'] as $table)$this->schema($target,$table,$columns);
            foreach(['jingo_transfer_orders','jingo_orders'] as $table){
                $types=$this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,DATETIME_PRECISION,CHARACTER_SET_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,$table])->fetchAll(PDO::FETCH_ASSOC);
                foreach($types as $t){$name=$t['COLUMN_NAME'];$ok=match($name){'created_at'=>$t['DATA_TYPE']==='datetime'&&(int)$t['DATETIME_PRECISION']===6,'version'=>$t['DATA_TYPE']==='int','snapshot','contact'=>$t['DATA_TYPE']==='json'||$t['DATA_TYPE']==='longtext'&&$t['CHARACTER_SET_NAME']==='utf8mb4','instructions'=>in_array($t['DATA_TYPE'],['text','longtext'],true)&&$t['CHARACTER_SET_NAME']==='utf8mb4',default=>in_array($t['DATA_TYPE'],['char','varchar'],true)&&(int)$t['CHARACTER_MAXIMUM_LENGTH']>=(['id'=>36,'code'=>12,'request_id'=>36,'request_hash'=>64,'access_hash'=>64,'booking_status'=>20,'payment_status'=>20][$name]??PHP_INT_MAX)};if(!$ok||$t['IS_NULLABLE']!=='NO')$this->error($table,0,'RUNTIME_CAPACITY_MISMATCH');}
                $unique=[];foreach($this->query('SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$target,$table])->fetchAll(PDO::FETCH_ASSOC) as $index)$unique[$index['INDEX_NAME']][]=$index['COLUMN_NAME'];
                if(($unique['PRIMARY']??[])!==['id']||!in_array(['code'],$unique,true)||!in_array(['request_id'],$unique,true))$this->error($table,0,'RUNTIME_UNIQUE_KEYS_MISMATCH');
            }
            foreach(['jingo_catalog_locks','jingo_audit'] as $table){$r=$this->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$target,$table])->fetch(PDO::FETCH_ASSOC);if(!$r||$r['TABLE_TYPE']!=='BASE TABLE'||strtoupper($r['ENGINE']??'')!=='INNODB')$this->error($table,0,'SCHEMA_MISMATCH');}
            if($this->errors){$report['errors']=$this->errors;$report['errorCount']=$this->errorCount;$this->db->rollBack();return $report;}
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".($apply?' FOR UPDATE':''))->fetchColumn())throw new RuntimeException('Missing catalog lock.');
            $src=$this->rows($source,'jm_transfer_orders',$apply?' LOCK IN SHARE MODE':'');$held=$this->rows($target,'jingo_transfer_orders',$apply?' FOR UPDATE':'');$live=$this->rows($target,'jingo_orders',$apply?' FOR UPDATE':'');
            $pending=[];$hashes=['scope:order-core-holding-only'];$owners=['held'=>[],'live'=>[],'source'=>[]];
            foreach(['held'=>$held,'live'=>$live,'source'=>$src] as $side=>$rows)foreach($rows as $key=>$row){foreach(['code','request_id'] as $field){$value=strtoupper((string)$row[$field]);if(isset($owners[$side][$field][$value])&&$owners[$side][$field][$value]!==$key)$this->error($side,0,'DUPLICATE_'.strtoupper($field));$owners[$side][$field][$value]=$key;}}
            foreach($src as $key=>$row){
                $index=++$report['counts']['sourceRows'];try{self::valid($row);$canonical=self::canonical($row);}catch(Throwable){$this->error('orders',$index,'INVALID_ORDER_CORE');continue;}
                $hashes[]='source:'.hash('sha256',$canonical);$heldMatches=false;
                foreach(['held'=>$held,'live'=>$live] as $side=>$rows){
                    foreach(['code','request_id'] as $field){$owner=$owners[$side][$field][strtoupper($row[$field])]??null;if($owner!==null&&$owner!==$key)$this->error('orders',$index,'COLLISION_'.strtoupper($side).'_'.strtoupper($field));}
                    if(isset($rows[$key])){if(self::canonical($rows[$key])!==$canonical)$this->error('orders',$index,'EXISTING_'.strtoupper($side).'_DIFFERS');elseif($side==='live')$report['counts']['matchingLiveRows']++;else$heldMatches=true;}
                }
                if(isset($held[$key])){if($heldMatches)$report['counts']['matchingRows']++;}else{$pending[]=$row;$report['counts']['newRows']++;}
            }
            sort($hashes,SORT_STRING);$calculated=hash('sha256',implode("\n",$hashes));if($apply&&!hash_equals($fingerprint,$calculated))$this->error('orders',0,'FINGERPRINT_MISMATCH');$report['fingerprint']=$this->errorCount===0?$calculated:null;
            $report['errors']=$this->errors;$report['errorCount']=$this->errorCount;$report['ready']=$this->errorCount===0;
            if(!$apply||!$report['ready']){$this->db->rollBack();return $report;}
            $insert=$this->db->prepare('INSERT INTO jingo_transfer_orders (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');foreach($pending as $row)$insert->execute(array_map(static fn($c)=>$row[$c],$columns));
            if($pending)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','orders.hold-import','orders:'.$report['fingerprint']]);
            $this->db->commit();$report['counts']['insertedRows']=count($pending);$report['applied']=true;return $report;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
