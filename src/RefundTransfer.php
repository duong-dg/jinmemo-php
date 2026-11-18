<?php
declare(strict_types=1);
require_once __DIR__.'/AdminAuth.php';
require_once __DIR__.'/TransferRelations.php';
require_once __DIR__.'/Refunds.php';

/** Imports historical ledger records only; never calls refund creation or payment providers. */
final class RefundTransfer
{
    private const MAX=9007199254740991;
    private const ROOTS=['ORDER'=>'orders','CART'=>'carts','BUNDLE'=>'checkout_bundles','HOTEL'=>'stay_bookings','RIDE'=>'ride_bookings','GIFT'=>'gift_orders'];
    private const TABLES=['refunds','orders','carts','cart_orders','checkout_bundles','checkout_members','stay_bookings','ride_bookings','gift_orders','order_payments','payment_references'];
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function json(mixed $v):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=$sort($x);return (object)$a;}return is_array($v)?array_map($sort,$v):$v;};return json_encode($sort($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function number(mixed $v,int $min=1):int
    {
        if(!is_int($v)&&(!is_string($v)||!preg_match('/^(?:0|[1-9][0-9]*)$/D',$v)))throw new RuntimeException();
        if(strlen((string)$v)>16||(int)$v<$min||(int)$v>self::MAX)throw new RuntimeException();return (int)$v;
    }
    private static function add(int $a,int $b):int{if($a>self::MAX-$b)throw new RuntimeException();return $a+$b;}
    private static function text(mixed $v,int $min,int $max):void
    {
        if(!is_string($v)||str_contains($v,"\0"))throw new RuntimeException();$length=strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2;
        if($length<$min||$length>$max||preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$v)!==$v)throw new RuntimeException();
    }
    private static function valid(array $r):void
    {
        if(!isset(self::ROOTS[$r['root_kind']])||!preg_match('/^(?:J[MGCBH][A-F0-9]{10}|JM-[XG][A-F0-9]{12})$/D',$r['root_code'])||self::number($r['amount'])>15000000000)throw new RuntimeException();
        $d=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if(!is_array($d)||count($d)!==8||array_diff(array_keys($d),['recipient','method','reference','note','payer','payeeType','actor','fingerprint'])||!in_array($d['method']??null,['BANK','CASH','GATEWAY'],true)||!in_array($d['payeeType']??null,['CUSTOMER','PARTNER'],true)||!is_string($d['fingerprint']??null)||!preg_match('/^[a-f0-9]{64}$/D',$d['fingerprint']))throw new RuntimeException();
        self::text($d['recipient'],2,150);self::text($d['note'],5,1000);self::text($d['payer'],0,200);self::text($d['actor'],1,512);self::text($d['reference'],3,500);
        if($r['reference_key']!==$d['method'].':'.$d['reference']||mb_strlen($r['reference_key'],'UTF-8')>512)throw new RuntimeException();
        if($d['method']==='CASH'){if(!str_starts_with($d['reference'],'CASH:')||strtolower(substr($d['reference'],5))!==strtolower($r['id']))throw new RuntimeException();}
        elseif(mb_strtoupper($d['reference'],'UTF-8')!==$d['reference'])throw new RuntimeException();
        $date=StaySchema::date($r['refunded_on']);
        if($date>(new DateTimeImmutable('now',new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d'))throw new RuntimeException();
    }
    /** Source parent/receipt graph. The caller has verified exported table declarations. */
    private function sourceRoot(array $r,array $tables,bool $lock):array
    {
        $kind=$r['root_kind'];$id=$r['root_id'];$sourceTable=self::ROOTS[$kind];$parent=$tables['_parents'][$sourceTable][$id]??null;
        if(!$parent||$parent['code']!==$r['root_code'])throw new RuntimeException('SOURCE_PARENT_MISSING_OR_DIFFERS');
        if(in_array($kind,['CART','GIFT','RIDE'],true)){
            $ref=['CART'=>'cartId','GIFT'=>'giftId','RIDE'=>'rideId'][$kind];if(!$tables['_checkoutRefsValid'])throw new RuntimeException('SOURCE_CHECKOUT_REFS_INVALID');if(isset($tables['_checkoutChildren'][$ref][$id]))throw new RuntimeException('SOURCE_PARENT_IS_CHECKOUT_CHILD');
        }
        $orderIds=[];$receipts=[];$paid=0;
        if($kind==='ORDER'){
            if(isset($tables['_cartByOrder'][$id]))throw new RuntimeException('SOURCE_PARENT_IS_CART_CHILD');$orderIds=[$id];
        }elseif($kind==='CART'){
            $orderIds=$tables['_cartOrders'][$id]??[];sort($orderIds,SORT_STRING);
            $actual=$this->query('SELECT order_id FROM jingo_cart_orders WHERE cart_id=? ORDER BY order_id'.($lock?' FOR UPDATE':''),[$id])->fetchAll(PDO::FETCH_COLUMN);
            if(!$orderIds||$actual!==$orderIds)throw new RuntimeException('RUNTIME_CART_MEMBERS_DIFFER');
        }
        if($orderIds){
            foreach($orderIds as $orderId)foreach($tables['_orderPayments'][$orderId]??[] as $payment){$amount=self::number($payment['amount']);$paid=self::add($paid,$amount);$receipts[]=['order'=>$payment,'amount'=>$amount];}
        }elseif(in_array($kind,['HOTEL','BUNDLE'],true)){
            if($kind==='BUNDLE'&&$parent['status']!=='PAID')throw new RuntimeException('SOURCE_PARENT_NOT_PAID');$receiptKind=$kind==='HOTEL'?'HOTEL_PAYMENT':'BUNDLE_PAYMENT';
            foreach($tables['_references'][$id]??[] as $p)if($p['kind']===$receiptKind){$amount=self::number($p['amount']);$paid=self::add($paid,$amount);$receipts[]=['reference'=>$p,'amount'=>$amount];}
        }else{
            $d=json_decode($parent['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(($d['paymentStatus']??'UNPAID')!=='PAID')throw new RuntimeException('SOURCE_PARENT_NOT_PAID');$paid=self::number($d['paidAmount']??null);
            foreach($tables['_references'][$id]??[] as $p)$receipts[]=['reference'=>$p,'amount'=>self::number($p['amount'])];
        }
        if($paid<1)throw new RuntimeException('SOURCE_HAS_NO_ACTUAL_RECEIPT');
        foreach($receipts as $proof){
            if(isset($proof['order'])){$p=$proof['order'];$native=$this->query('SELECT order_id,reference_hash,amount FROM jingo_order_payments WHERE id=?'.($lock?' FOR UPDATE':''),[$p['id']])->fetch(PDO::FETCH_ASSOC);if(!$native||$native['order_id']!==$p['order_id']||$native['reference_hash']!==hash('sha256',$p['reference'])||(string)$native['amount']!==$p['amount'])throw new RuntimeException('RUNTIME_RECEIPT_DIFFERS');}
            else{$p=$proof['reference'];$native=$this->query('SELECT reference,kind,entity_id,amount FROM jingo_payment_references WHERE reference_hash=?'.($lock?' FOR UPDATE':''),[hash('sha256',$p['reference'])])->fetch(PDO::FETCH_ASSOC);if(!$native||$native['reference']!==$p['reference']||$native['kind']!==$p['kind']||$native['entity_id']!==$p['entity_id']||(string)$native['amount']!==$p['amount'])throw new RuntimeException('RUNTIME_RECEIPT_DIFFERS');}
        }
        if(in_array($kind,['GIFT','RIDE','BUNDLE'],true)){
            $native=$this->query('SELECT data FROM jingo_'.$sourceTable.' WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->fetchColumn();if($native===false)throw new RuntimeException('RUNTIME_PARENT_MISSING');
            $a=json_decode($parent['data'],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$b=json_decode($native,false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
            foreach($kind==='BUNDLE'?['refs','amounts','codes','reference','paidAt','paidMethod']:['paymentStatus','paidAmount','paymentReference','paidAt','bundleCode'] as $key)if(property_exists($a,$key)!==property_exists($b,$key)||property_exists($a,$key)&&self::json($a->$key)!==self::json($b->$key))throw new RuntimeException('RUNTIME_PARENT_FINANCIAL_DATA_DIFFERS');
        }
        return ['paid'=>$paid,'receiptDigest'=>hash('sha256',self::json($receipts))];
    }
    public function run(string $file,bool $apply=false,?string $fingerprint=null):array
    {
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)))throw new InvalidArgumentException('Cần fingerprint từ dry-run.');
        $r=['scope'=>'runtime-refund-history','mode'=>$apply?'apply':'dry-run','ready'=>false,'applied'=>false,'operationalReady'=>false,'sourceSha256'=>null,'fingerprint'=>null,'sourceRows'=>0,'matchingRows'=>0,'insertedRows'=>0,'rootCount'=>0,'errors'=>[],'errorCount'=>0];
        $error=static function(string $code)use(&$r):void{$r['errorCount']++;if(count($r['errors'])<100)$r['errors'][]=['code'=>$code];};
        if(TransferFormat::detect($file)!=='full-v2'){$error('FULL_V2_REQUIRED');return $r;}$audit=(new TransferRelations())->run($file);$r['sourceSha256']=$audit['sha256'];
        if(!$audit['profileMatches']||$audit['relations']['errorCount']){$error('SOURCE_FILE_OR_RELATIONS_INVALID');return $r;}
        $tables=[];$declared=[];$count=0;$bytes=0;$again=TransferFormat::audit($file)->run($file,static function(string $table,array $cols,array $values)use(&$tables,&$declared,&$count,&$bytes):void{
            $name=substr($table,12);if(!in_array($name,[...self::TABLES,'manifest'],true))return;$row=[];foreach($cols as $i=>$c){$v=$values[$i];$row[$c['name']]=$v==='NULL'?null:(str_starts_with($v,'CONVERT(')?hex2bin(substr($v,10,-16)):$v);}
            if($name==='manifest'){if($row['status']==='EXPORTED')$declared[substr($row['source_table'],8)]=true;return;}
            if(++$count>100000||($bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR)))>67108864)throw new RuntimeException('Refund source limit.');$tables[$name][]=$row;
        });
        if(!$again['profileMatches']||$again['sha256']!==$audit['sha256']){$error('SOURCE_CHANGED_DURING_READ');return $r;}
        foreach(self::TABLES as $table)if(!isset($declared[$table]))$error('SOURCE_DEPENDENCY_NOT_EXPORTED');if($r['errorCount'])return $r;
        $tables['_parents']=[];foreach(self::ROOTS as $table)$tables['_parents'][$table]=array_column($tables[$table]??[],null,'id');
        foreach($tables['cart_orders']??[] as $m){$tables['_cartOrders'][$m['cart_id']][]=$m['order_id'];$tables['_cartByOrder'][$m['order_id']]=true;}
        foreach($tables['order_payments']??[] as $p)$tables['_orderPayments'][$p['order_id']][]=$p;
        foreach($tables['payment_references']??[] as $p)$tables['_references'][$p['entity_id']][]=$p;
        $tables['_checkoutRefsValid']=true;foreach($tables['checkout_members']??[] as $m)$tables['_checkoutChildren'][$m['kind']][$m['entity_id']]=true;
        foreach($tables['checkout_bundles']??[] as $bundle){$d=json_decode($bundle['data'],false,512,JSON_THROW_ON_ERROR);if(!$d instanceof stdClass||!isset($d->refs)||!$d->refs instanceof stdClass){$tables['_checkoutRefsValid']=false;continue;}foreach(get_object_vars($d->refs) as $kind=>$id){if(!in_array($kind,['cartId','rideId','giftId'],true)||!is_string($id)){$tables['_checkoutRefsValid']=false;continue;}$tables['_checkoutChildren'][$kind][$id]=true;}}
        $source=[];$roots=[];$totals=[];$r['sourceRows']=count($tables['refunds']??[]);if($r['sourceRows']>50000||array_sum(array_map(static fn($row)=>strlen($row['data']),$tables['refunds']??[]))>33554432){$error('SOURCE_REFUND_LIMIT');return $r;}
        foreach($tables['refunds']??[] as $row){try{self::valid($row);$source[$row['id']]=$row;$key=$row['root_kind'].':'.$row['root_id'];if(isset($roots[$key])&&$roots[$key]['root_code']!==$row['root_code'])throw new RuntimeException();$roots[$key]=$row;$totals[$key]=self::add($totals[$key]??0,self::number($row['amount']));}catch(Throwable){$error('REFUND_RECORD_INVALID');}}
        $r['rootCount']=count($roots);if($r['errorCount'])return $r;
        foreach(['jingo_refunds','jingo_audit','jingo_catalog_locks',...array_map(static fn($t)=>'jingo_'.$t,array_values(self::ROOTS)),'jingo_order_payments','jingo_payment_references','jingo_cart_orders','jingo_checkout_members'] as $table){$engine=$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'',[$table])->fetchColumn();if(strtoupper((string)$engine)!=='INNODB')$error('RUNTIME_SCHEMA_MISSING');}
        $columns=$this->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME,DATETIME_PRECISION,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_refunds' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        if(array_column($columns,'COLUMN_NAME')!==['id','root_kind','root_id','root_code','amount','refunded_on','reference_key','data','created_at'])$error('RUNTIME_REFUND_SCHEMA_MISMATCH');
        foreach($columns as $c){$ok=match($c['COLUMN_NAME']){'amount'=>$c['DATA_TYPE']==='bigint','data'=>$c['DATA_TYPE']==='json'||$c['DATA_TYPE']==='longtext'&&$c['CHARACTER_SET_NAME']==='utf8mb4','created_at'=>$c['DATA_TYPE']==='datetime'&&(int)$c['DATETIME_PRECISION']===6,default=>in_array($c['DATA_TYPE'],['char','varchar'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']>=(['id'=>36,'root_kind'=>16,'root_id'=>80,'root_code'=>80,'refunded_on'=>10,'reference_key'=>512][$c['COLUMN_NAME']]??PHP_INT_MAX)};if($c['COLUMN_NAME']==='reference_key'&&$c['COLLATION_NAME']!=='utf8mb4_bin')$ok=false;if(!$ok||$c['IS_NULLABLE']!=='NO')$error('RUNTIME_REFUND_CAPACITY_MISMATCH');}
        $keys=[];foreach($this->query("SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_refunds' AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC) as $k)$keys[$k['INDEX_NAME']][]=$k['COLUMN_NAME'];if(($keys['PRIMARY']??[])!==['id']||!in_array(['reference_key'],$keys,true))$error('RUNTIME_REFUND_KEYS_MISMATCH');if($r['errorCount'])return $r;
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".($apply?' FOR UPDATE':''))->fetchColumn())throw new RuntimeException('Missing catalog lock.');$states=[];$new=$source;$seen=0;$bytes=0;
            $buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=null;
            try{$q=$this->query('SELECT * FROM jingo_refunds ORDER BY id LIMIT 50001'.($apply?' FOR UPDATE':''));while($row=$q->fetch(PDO::FETCH_ASSOC)){
                if(++$seen>50000||($bytes+=strlen($row['data']))>67108864)throw new RuntimeException('Refund target limit.');$id=$row['id'];if(!isset($source[$id])){$error('EXTRA_RUNTIME_REFUND');continue;}$same=true;
                foreach($source[$id] as $key=>$value){if($key==='data'){$ok=self::json(json_decode($row[$key],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING))===self::json(json_decode($value,false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING));}else $ok=(string)$row[$key]===$value;if(!$ok)$same=false;}
                if(!$same)$error('EXISTING_REFUND_DIFFERS');else $r['matchingRows']++;$states[$id]=hash('sha256',self::json($row));unset($new[$id]);
            }}finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
            $parents=[];
            foreach($roots as $key=>$row){try{$proof=$this->sourceRoot($row,$tables,$apply);$state=(new Refunds($this->db))->transferState($row['root_code'],$apply);$data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);
                if($state['id']!==$row['root_id']||$state['kind']!==$row['root_kind']||$state['paid']!==$proof['paid']||$state['payeeType']!==$data['payeeType']||$totals[$key]>$state['paid'])throw new RuntimeException('ROOT_RECEIPT_OR_REFUND_TOTAL_DIFFERS');
                $parents[$key]=['paid'=>$state['paid'],'refunded'=>$state['refunded'],'receiptDigest'=>$proof['receiptDigest']];
            }catch(Throwable $e){$allowed=['SOURCE_PARENT_MISSING_OR_DIFFERS','SOURCE_PARENT_IS_CHECKOUT_CHILD','SOURCE_PARENT_IS_CART_CHILD','SOURCE_CHECKOUT_REFS_INVALID','RUNTIME_CART_MEMBERS_DIFFER','SOURCE_PARENT_NOT_PAID','SOURCE_HAS_NO_ACTUAL_RECEIPT','RUNTIME_RECEIPT_DIFFERS','RUNTIME_PARENT_MISSING','RUNTIME_PARENT_FINANCIAL_DATA_DIFFERS','ROOT_RECEIPT_OR_REFUND_TOTAL_DIFFERS'];$error(in_array($e->getMessage(),$allowed,true)?$e->getMessage():'PARENT_NOT_READY_FOR_REFUND_IMPORT');}}
            ksort($states,SORT_STRING);ksort($parents,SORT_STRING);$fp=hash('sha256','runtime-refund-history-v1:'.$audit['sha256'].':'.self::json([$states,$parents]));if($apply&&!hash_equals($fingerprint,$fp))$error('FINGERPRINT_OR_TARGET_CHANGED');
            if(!is_file($file)||is_link($file)||hash_file('sha256',$file)!==$audit['sha256'])$error('SOURCE_CHANGED_BEFORE_COMMIT');$r['ready']=$r['errorCount']===0;$r['fingerprint']=$r['ready']?$fp:null;
            if(!$apply||!$r['ready']){$this->db->rollBack();return $r;}
            foreach($new as $row)$this->query('INSERT INTO jingo_refunds(id,root_kind,root_id,root_code,amount,refunded_on,reference_key,data,created_at) VALUES (?,?,?,?,?,?,?,?,?)',array_map(static fn($key)=>$row[$key],['id','root_kind','root_id','root_code','amount','refunded_on','reference_key','data','created_at']));$r['insertedRows']=count($new);
            if($new)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','transfer.refunds',$fp]);$this->db->commit();$r['applied']=true;return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
