<?php
declare(strict_types=1);

/** Historical snapshots: never invokes create/update, subtracts stock or emits notifications. */
final class GiftOrderTransfer
{
    private const MAX=9007199254740991;
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function uuid(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$v)===1;}
    private static function number(mixed $v,int $min,int $max):int{if(!is_int($v)||$v<$min||$v>$max)throw new RuntimeException();return $v;}
    private static function text(mixed $v,int $min,int $max,bool $trim=true):void
    {
        if(!is_string($v)||str_contains($v,"\0"))throw new RuntimeException();$n=strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2;
        if($n<$min||$n>$max||$trim&&preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$v)!==$v)throw new RuntimeException();
    }
    private static function canonical(string $json):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=$sort($x);return (object)$a;}return is_array($v)?array_map($sort,$v):$v;};
        return json_encode($sort(json_decode($json,false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function validate(array $rows,array $catalog,array $bundledIds,bool $membersDeclared,array $financialRefs=[],array $bundleContexts=[]):array
    {
        $errors=[];$error=static function(string $code)use(&$errors):void{if(count($errors)<100)$errors[]=$code;};$items=array_column($catalog['items'],null,'id');$returns=[];
        if($rows&&!$membersDeclared)$error('GIFT_CHECKOUT_MEMBERS_NOT_EXPORTED');
        foreach($rows as $row){
            try{
                if(!self::uuid($row['id'])||!preg_match('/^JM-G[A-F0-9]{12}$/D',$row['code'])||!in_array($row['status'],['PENDING','SHIPPING','COMPLETED','CANCELLED'],true))throw new RuntimeException();
                if(isset($financialRefs[$row['id']])||isset($financialRefs[$row['code']])){$error('GIFT_FINANCIAL_HISTORY_ADAPTER_REQUIRED');continue;}
                $d=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
                if(!is_array($d))throw new RuntimeException();
                if(!isset($bundleContexts[$row['id']])&&(isset($bundledIds[$row['id']])||array_intersect(array_keys($d),['paymentStatus','paidAmount','paidAt','paymentReference','bundleCode']))){$error('GIFT_PAYMENT_OR_BUNDLE_ADAPTER_REQUIRED');continue;}
                if(isset($bundleContexts[$row['id']]))foreach(['paymentStatus','paidAmount','paidAt','paymentReference','bundleCode'] as $key)unset($d[$key]);
                if(array_diff(array_keys($d),['requestId','fulfillment','expectedTotal','name','phone','pickupCounterId','address','lines','pickupCounterName','shippingFee','total','fingerprint'])||($d['requestId']??null)!==$row['id']||!in_array($d['fulfillment']??null,['PICKUP','DELIVERY'],true)||!is_string($d['fingerprint']??null)||!preg_match('/^[a-f0-9]{64}$/D',$d['fingerprint']))throw new RuntimeException();
                self::text($d['name']??null,2,120);self::text($d['phone']??null,8,25);if(!preg_match('/^\+?[0-9 ()-]{8,25}$/D',$d['phone']))throw new RuntimeException();
                self::text($d['address']??null,$d['fulfillment']==='DELIVERY'?8:0,500);
                if(array_key_exists('pickupCounterId',$d)&&(!is_string($d['pickupCounterId'])||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$d['pickupCounterId'])))throw new RuntimeException();
                if(array_key_exists('pickupCounterName',$d))self::text($d['pickupCounterName'],1,200);
                if($row['status']==='SHIPPING'&&$d['fulfillment']!=='DELIVERY')throw new RuntimeException();
                $fee=self::number($d['shippingFee']??null,0,10000000);if($d['fulfillment']==='PICKUP'&&$fee!==0)throw new RuntimeException();$total=$fee;
                if(!is_array($d['lines']??null)||!array_is_list($d['lines'])||count($d['lines'])<1||count($d['lines'])>20)throw new RuntimeException();$seen=[];$pending=[];
                foreach($d['lines'] as $line){
                    if(!is_array($line)||count($line)!==4||array_diff(array_keys($line),['id','quantity','name','price'])||!self::uuid($line['id']??null)||isset($seen[$line['id']]))throw new RuntimeException();$seen[$line['id']]=true;
                    self::text($line['name']??null,1,150);$qty=self::number($line['quantity']??null,1,50);$price=self::number($line['price']??null,1,100000000);$amount=$qty*$price;if($total>self::MAX-$amount)throw new RuntimeException();$total+=$amount;
                    if(in_array($row['status'],['PENDING','SHIPPING'],true)){
                        if(!isset($items[$line['id']])){$error('OPEN_GIFT_ITEM_MISSING_FROM_CATALOG');continue;}$pending[$line['id']]=$qty;
                    }
                }
                if(self::number($d['total']??null,1,self::MAX)!==$total||self::number($d['expectedTotal']??null,1,self::MAX)!==$total)throw new RuntimeException();
                foreach($pending as $id=>$qty){$current=$returns[$id]??0;if($current>self::MAX-$qty)throw new RuntimeException();$returns[$id]=$current+$qty;}
            }catch(Throwable){$error('GIFT_ORDER_SNAPSHOT_INVALID');}
        }
        foreach($returns as $id=>$qty)if($items[$id]['stock']>self::MAX-$qty)$error('GIFT_CANCELLATION_STOCK_OVERFLOW');
        return $errors;
    }
    public function schema():array
    {
        $errors=[];$columns=$this->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,IS_NULLABLE,DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_gift_orders' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        if(array_column($columns,'COLUMN_NAME')!==['id','code','status','data','created_at'])$errors[]='RUNTIME_GIFT_ORDER_SCHEMA_MISMATCH';
        foreach($columns as $c){$valid=match($c['COLUMN_NAME']){'id','code','status'=>in_array($c['DATA_TYPE'],['char','varchar'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']>=['id'=>36,'code'=>80,'status'=>16][$c['COLUMN_NAME']]&&$c['CHARACTER_SET_NAME']==='ascii','data'=>$c['DATA_TYPE']==='json'||$c['DATA_TYPE']==='longtext'&&$c['CHARACTER_SET_NAME']==='utf8mb4','created_at'=>$c['DATA_TYPE']==='datetime'&&(int)$c['DATETIME_PRECISION']===6,default=>false};if(!$valid||$c['IS_NULLABLE']!=='NO')$errors[]='RUNTIME_GIFT_ORDER_CAPACITY_MISMATCH';}
        $keys=[];foreach($this->query("SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_gift_orders' AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC) as $k)$keys[$k['INDEX_NAME']][]=$k['COLUMN_NAME'];
        if(($keys['PRIMARY']??[])!==['id']||!in_array(['code'],$keys,true))$errors[]='RUNTIME_GIFT_ORDER_KEYS_MISMATCH';return array_values(array_unique($errors));
    }
    /** Caller holds the catalog lock; only returns private plan to the importer, never API output. */
    public function plan(array $rows,bool $lock,array $bundleContexts=[]):array
    {
        if(!$this->db->inTransaction())throw new LogicException('Transaction required.');$source=array_column($rows,null,'id');$new=$source;$matched=0;$errors=[];$states=[];$bytes=0;$count=0;
        $buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=null;
        try{$q=$this->query('SELECT id,code,status,data,created_at FROM jingo_gift_orders ORDER BY id LIMIT 50001'.($lock?' FOR UPDATE':''));
        while($target=$q->fetch(PDO::FETCH_ASSOC)){
            if(++$count>50000||($bytes+=strlen($target['data']))>33554432)throw new RuntimeException('Target gift limit.');$id=$target['id'];
            if(!isset($source[$id])){$errors[]='EXTRA_RUNTIME_GIFT_ORDER';continue;}$row=$source[$id];$same=true;
            foreach(['code','status','created_at'] as $key)if($target[$key]!==$row[$key])$same=false;
            if(self::canonical($target['data'])!==self::canonical($row['data']))$same=false;
            if(!$same)$errors[]='EXISTING_GIFT_ORDER_DIFFERS';else $matched++;
            $states[$id]=hash('sha256',json_encode($target,JSON_THROW_ON_ERROR));unset($new[$id]);
        }
        }finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
        $q=$this->query("SELECT entity_id,bundle_id FROM jingo_checkout_members WHERE kind='giftId' LIMIT 50001".($lock?' FOR UPDATE':''));$members=0;while($member=$q->fetch(PDO::FETCH_ASSOC)){$id=$member['entity_id'];if(++$members>50000)throw new RuntimeException('Runtime checkout member limit.');if(isset($source[$id])&&($bundleContexts[$id]??null)!==$member['bundle_id'])$errors[]='RUNTIME_GIFT_ALREADY_IN_BUNDLE';}$q->closeCursor();
        $codes=array_fill_keys(array_column($rows,'code'),true);
        foreach(["SELECT root_id AS entity_id,root_code FROM jingo_refunds WHERE root_kind='GIFT' LIMIT 100001","SELECT entity_id,'' AS root_code FROM jingo_payment_references LIMIT 100001"] as $sql){$q=$this->query($sql.($lock?' FOR UPDATE':''));$seen=0;while($ref=$q->fetch(PDO::FETCH_ASSOC)){if(++$seen>100000)throw new RuntimeException('Runtime financial reference limit.');if(isset($source[$ref['entity_id']])||isset($codes[$ref['root_code']]))$errors[]='RUNTIME_GIFT_FINANCIAL_HISTORY_EXISTS';}$q->closeCursor();}
        ksort($states,SORT_STRING);
        return ['new'=>$new,'digest'=>hash('sha256',json_encode($states,JSON_THROW_ON_ERROR)),'source'=>count($rows),'matching'=>$matched,'errors'=>array_values(array_unique($errors))];
    }
    public function insert(array $rows):int
    {
        if(!$this->db->inTransaction())throw new LogicException('Transaction required.');
        foreach($rows as $row)$this->query('INSERT INTO jingo_gift_orders(id,code,status,data,created_at) VALUES (?,?,?,?,?)',[$row['id'],$row['code'],$row['status'],$row['data'],$row['created_at']]);return count($rows);
    }
}
