<?php
declare(strict_types=1);
require_once __DIR__.'/TransferRelations.php';
require_once __DIR__.'/OrderCoreTransfer.php';
require_once __DIR__.'/Inventory.php';
require_once __DIR__.'/Schedule.php';

/** Restores original allocations only after their native product/order parents match. */
final class InventoryTransfer
{
    private const KEYS=['inventory_configs'=>['product_id'],'inventory_buckets'=>['product_id','variant_id','visit_date','slot_id'],'inventory_allocations'=>['order_id']];
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function stable(mixed $v):mixed
    {
        if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=self::stable($x);return (object)$a;}
        return is_array($v)?array_map([self::class,'stable'],$v):$v;
    }
    private static function canonical(array $row):string
    {
        foreach($row as $key=>&$value){if(in_array($key,['data','snapshot','contact'],true))$value=json_decode($value,false,512,JSON_THROW_ON_ERROR);elseif($value!==null)$value=(string)$value;}
        return json_encode(self::stable((object)$row),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function key(array $row,array $keys):string{return json_encode(array_map(static fn($k)=>(string)$row[$k],$keys),JSON_THROW_ON_ERROR);}
    private static function number(mixed $n,int $min,int $max):void{if(!is_string($n)||!preg_match('/^(?:0|[1-9][0-9]*)$/D',$n)||(int)$n<$min||(int)$n>$max)throw new RuntimeException('Invalid inventory number.');}
    private function schemaTypes(string $table):bool
    {
        $cols=$this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['jingo_'.$table])->fetchAll(PDO::FETCH_ASSOC);
        foreach($cols as $c){if($c['IS_NULLABLE']!=='NO')return false;$name=$c['COLUMN_NAME'];if(in_array($name,['enabled','released'],true)){if($c['DATA_TYPE']!=='tinyint')return false;}elseif(in_array($name,['version','capacity','low_stock_threshold','quantity'],true)){if($c['DATA_TYPE']!=='int')return false;}else{$length=match($name){'order_id'=>36,'visit_date'=>10,default=>80};if(!in_array($c['DATA_TYPE'],['char','varchar'],true)||(int)$c['CHARACTER_MAXIMUM_LENGTH']!==$length||$c['CHARACTER_SET_NAME']!=='ascii'||$c['COLLATION_NAME']!=='ascii_bin')return false;}}
        return true;
    }
    public static function validateGraph(array $source):void
    {
        $products=[];$orders=[];$configs=[];$buckets=[];$allocations=[];
        foreach($source['products'] as $p)$products[$p['id']]=json_decode($p['data'],true,512,JSON_THROW_ON_ERROR);
        foreach($source['orders'] as $o)$orders[$o['id']]=$o;
        foreach(self::KEYS as $table=>$keys){$seen=[];foreach($source[$table] as $row){$key=self::key($row,$keys);if(isset($seen[$key]))throw new RuntimeException('Duplicate inventory key.');$seen[$key]=true;
            foreach(['product_id','variant_id','slot_id'] as $field)if(isset($row[$field])&&!($field==='slot_id'&&$row[$field]==='')&&!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$row[$field]))throw new RuntimeException('Invalid inventory scope.');
            if(!isset($products[$row['product_id']]))throw new RuntimeException('Missing source product.');
            if(isset($row['version']))self::number($row['version'],1,2147483646);
            if(isset($row['visit_date']))Pricing::validateDate($row['visit_date']);
            if($table==='inventory_configs'){self::number($row['enabled'],0,1);$configs[$row['product_id']]=$row;continue;}
            if($table==='inventory_buckets'){self::number($row['capacity'],0,1000000);self::number($row['low_stock_threshold'],0,1000000);$buckets[]=$row;continue;}
            self::number($row['quantity'],1,150);self::number($row['released'],0,1);$order=$orders[$row['order_id']]??null;if(!$order)throw new RuntimeException('Missing source order.');OrderCoreTransfer::validateRecord($order,true);$snapshot=json_decode($order['snapshot'],true,512,JSON_THROW_ON_ERROR);
            if($row['product_id']!==$snapshot['productId']||$row['variant_id']!==($snapshot['variantId']??'')||$row['visit_date']!==$snapshot['date']||$row['slot_id']!==($snapshot['timeSlot']['id']??'')||(int)$row['quantity']!==array_sum(array_column($snapshot['lines'],'quantity'))||($row['released']==='1')!==($order['booking_status']==='CANCELLED'))throw new RuntimeException('Allocation/order mismatch.');
            if($row['released']==='0'){$variant=null;foreach($products[$row['product_id']]['variants']??[] as $v)if($v['id']===$row['variant_id'])$variant=$v;if(!$variant||$row['slot_id']!==''&&!in_array($row['slot_id'],array_column(Schedule::allSlots($variant),'id'),true))throw new RuntimeException('Active allocation scope missing.');}$allocations[]=$row;
        }}
        $usage=[];$capacity=[];
        foreach($allocations as $a)if($a['released']==='0'){$k=self::key($a,['product_id','variant_id','visit_date']);$usage[$k]['total']=($usage[$k]['total']??0)+(int)$a['quantity'];$usage[$k]['slots'][$a['slot_id']]=($usage[$k]['slots'][$a['slot_id']]??0)+(int)$a['quantity'];}
        foreach($buckets as $b){$k=self::key($b,['product_id','variant_id','visit_date']);$capacity[$k][$b['slot_id']]=true;$used=$b['slot_id']===''?($usage[$k]['total']??0):(($usage[$k]['slots']['']??0)+($usage[$k]['slots'][$b['slot_id']]??0));if(($configs[$b['product_id']]['enabled']??'0')==='1'&&$used>(int)$b['capacity'])throw new RuntimeException('Inventory oversold.');}
        foreach($allocations as $a)if($a['released']==='0'&&($configs[$a['product_id']]['enabled']??'0')==='1'){$k=self::key($a,['product_id','variant_id','visit_date']);if(!isset($capacity[$k][''])&&!isset($capacity[$k][$a['slot_id']]))throw new RuntimeException('Allocation has no capacity.');}
    }
    public function run(string $file,bool $apply=false,?string $fingerprint=null):array
    {
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)))throw new InvalidArgumentException('Cần fingerprint dry-run.');
        $r=['scope'=>'runtime-inventory-with-matching-native-parents','ready'=>false,'applied'=>false,'operationalReady'=>false,'fingerprint'=>null,'sourceRows'=>0,'matchingRows'=>0,'insertedRows'=>0,'errors'=>[]];$error=static function(string $code)use(&$r):void{if(count($r['errors'])<100)$r['errors'][]=['code'=>$code];};
        if(TransferFormat::detect($file)!=='full-v2'){$error('FULL_V2_REQUIRED');return $r;}$audit=(new TransferRelations())->run($file);if(!$audit['profileMatches']||$audit['relations']['errorCount']){$error('SOURCE_INVALID');return $r;}$r['sourceSha256']=$audit['sha256'];$source=array_fill_keys([...array_keys(self::KEYS),'products','orders'],[]);$declared=[];$bytes=0;$count=0;
        $again=TransferFormat::audit($file)->run($file,static function($table,$cols,$values)use(&$source,&$declared,&$bytes,&$count):void{
            $name=substr($table,12);if($name!=='manifest'&&!isset($source[$name]))return;$row=[];foreach($cols as $i=>$c){$v=$values[$i];$row[$c['name']]=$v==='NULL'?null:(str_starts_with($v,'CONVERT(')?hex2bin(substr($v,10,-16)):$v);}
            if($name==='manifest'){$declared[substr($row['source_table'],8)]=$row['status'];return;}if(++$count>50000||($bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR)))>33554432)throw new RuntimeException('Inventory source limit.');$source[$name][]=$row;
        });foreach($source as $name=>$rows)if(($declared[$name]??null)!=='EXPORTED')$error('SOURCE_TABLE_NOT_EXPORTED');if(!$again['profileMatches']||$again['sha256']!==$audit['sha256'])$error('SOURCE_CHANGED');try{self::validateGraph($source);}catch(Throwable){$error('SOURCE_INVENTORY_GRAPH_INVALID');}foreach(self::KEYS as $t=>$keys)$r['sourceRows']+=count($source[$t]);
        foreach(['products','orders','catalog_locks','audit',...array_keys(self::KEYS)] as $t)if(strtoupper((string)$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['jingo_'.$t])->fetchColumn())!=='INNODB')$error('RUNTIME_SCHEMA_MISSING');
        foreach(self::KEYS as $t=>$keys){$cols=$this->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',['jingo_'.$t])->fetchAll(PDO::FETCH_COLUMN);$expected=match($t){'inventory_configs'=>['product_id','enabled','version'],'inventory_buckets'=>['product_id','variant_id','visit_date','slot_id','capacity','low_stock_threshold'],default=>['order_id','product_id','variant_id','visit_date','slot_id','quantity','released','version']};$pk=$this->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX",['jingo_'.$t])->fetchAll(PDO::FETCH_COLUMN);if($cols!==$expected||$pk!==$keys)$error('INVENTORY_SCHEMA_MISMATCH');}if($r['errors'])return $r;
        foreach(array_keys(self::KEYS) as $table)if(!$this->schemaTypes($table))$error('INVENTORY_SCHEMA_MISMATCH');if($r['errors'])return $r;
        $this->db->exec("SET time_zone='+00:00'");$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{$suffix=$apply?' FOR UPDATE':'';if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".$suffix)->fetchColumn())throw new RuntimeException('Catalog lock missing.');$state=[];$parents=['products'=>[],'orders'=>[]];foreach(self::KEYS as $t=>$keys)foreach($source[$t] as $row){$parents['products'][$row['product_id']]=true;if(isset($row['order_id']))$parents['orders'][$row['order_id']]=true;}
            foreach($parents as $table=>$ids){$records=array_column($source[$table],null,'id');ksort($ids,SORT_STRING);foreach($ids as $id=>$_){$target=$this->query('SELECT * FROM jingo_'.$table.' WHERE id=?'.$suffix,[$id])->fetch(PDO::FETCH_ASSOC);$original=$records[$id]??null;if(!$target||!$original){$error('NATIVE_PARENT_MISSING');continue;}$selected=array_intersect_key($target,$original);if(self::canonical($selected)!==self::canonical($original))$error('NATIVE_PARENT_DIFFERS');$state[$table][$id]=hash('sha256',self::canonical($target));}}
            $new=[];$targetBytes=0;$targetCount=0;foreach(self::KEYS as $table=>$keys){$new[$table]=[];foreach($source[$table] as $row)$new[$table][self::key($row,$keys)]=$row;$old=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=null;try{$q=$this->query('SELECT * FROM jingo_'.$table.' ORDER BY '.implode(',',$keys).' LIMIT 50001'.$suffix);while($row=$q->fetch(PDO::FETCH_ASSOC)){if(++$targetCount>50000||($targetBytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR)))>67108864)throw new RuntimeException('Inventory target limit.');$key=self::key($row,$keys);$canonical=self::canonical($row);$state[$table][$key]=hash('sha256',$canonical);if(!isset($new[$table][$key]))$error('EXTRA_RUNTIME_INVENTORY');elseif(self::canonical($new[$table][$key])!==$canonical)$error('EXISTING_INVENTORY_DIFFERS');else $r['matchingRows']++;unset($new[$table][$key]);}}finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$old);}}
            $fp=hash('sha256','inventory-transfer-v1:'.$audit['sha256'].':'.json_encode($state,JSON_THROW_ON_ERROR));if($apply&&!hash_equals($fingerprint,$fp))$error('FINGERPRINT_OR_TARGET_CHANGED');if(!is_file($file)||is_link($file)||hash_file('sha256',$file)!==$audit['sha256'])$error('SOURCE_CHANGED_BEFORE_COMMIT');$r['ready']=!$r['errors'];$r['fingerprint']=$r['ready']?$fp:null;if(!$apply||!$r['ready']){$this->db->rollBack();return $r;}
            foreach($new as $table=>$rows)foreach($rows as $row){$this->query('INSERT INTO jingo_'.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));$r['insertedRows']++;}if($r['insertedRows'])$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','transfer.inventory',$fp]);$this->db->commit();$r['applied']=true;return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
