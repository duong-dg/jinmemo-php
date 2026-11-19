<?php
declare(strict_types=1);
require_once __DIR__.'/TransferRelations.php';
require_once __DIR__.'/OrderCoreTransfer.php';

final class OrderHistoryTransfer
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function stable(mixed $v):mixed
    {
        if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=self::stable($x);return (object)$a;}return is_array($v)?array_map([self::class,'stable'],$v):$v;
    }
    private static function canonical(array $row):string
    {
        foreach($row as $key=>&$value){if(in_array($key,['snapshot','contact'],true))$value=json_decode($value,false,512,JSON_THROW_ON_ERROR);elseif($value!==null)$value=(string)$value;}return json_encode(self::stable((object)$row),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function uuid(mixed $s):bool{return is_string($s)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$s)===1;}
    private static function text(mixed $s,int $min,int $max):bool{return is_string($s)&&!str_contains($s,"\0")&&preg_match('//u',$s)===1&&mb_strlen($s,'UTF-8')>=$min&&mb_strlen($s,'UTF-8')<=$max;}
    public static function validate(array $row):void
    {
        if(!self::uuid($row['id']??null)||!self::uuid($row['order_id']??null)||!self::text($row['admin_id']??null,1,64)||!in_array($row['action']??null,['ARCHIVE','RESTORE','INSTRUCTIONS','CONFIRM','CANCEL','CREDIT','PAY','DELIVER','ATTACH_FILE','REVOKE_FILE'],true)||!is_string($row['request_hash']??null)||!(preg_match('/^[a-f0-9]{64}$/D',$row['request_hash'])||$row['action']==='REVOKE_FILE'&&self::uuid($row['request_hash']))||!self::text($row['note']??null,0,65535)||strlen($row['note'])>65535)throw new RuntimeException('Invalid source action.');
        if(!preg_match('/^[1-9][0-9]{3}-/D',$row['created_at']))throw new RuntimeException('Invalid source timestamp.');$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$row['created_at'],new DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d H:i:s.u')!==$row['created_at'])throw new RuntimeException('Invalid source timestamp.');
    }
    private function schema():bool
    {
        $rows=$this->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME,IS_NULLABLE,DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_source_order_actions' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        if(array_column($rows,'COLUMN_NAME')!==['id','order_id','request_hash','admin_id','action','note','created_at'])return false;
        foreach($rows as $c){if($c['IS_NULLABLE']!=='NO')return false;$ok=match($c['COLUMN_NAME']){'id','order_id'=>in_array($c['DATA_TYPE'],['char','varchar'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']===36&&$c['COLLATION_NAME']==='ascii_general_ci','request_hash','action'=>$c['DATA_TYPE']==='varchar'&&(int)$c['CHARACTER_MAXIMUM_LENGTH']===($c['COLUMN_NAME']==='action'?24:64)&&$c['COLLATION_NAME']==='ascii_bin','admin_id'=>$c['DATA_TYPE']==='varchar'&&(int)$c['CHARACTER_MAXIMUM_LENGTH']===64&&$c['CHARACTER_SET_NAME']==='utf8mb4','note'=>$c['DATA_TYPE']==='text'&&$c['CHARACTER_SET_NAME']==='utf8mb4','created_at'=>$c['DATA_TYPE']==='datetime'&&(int)$c['DATETIME_PRECISION']===6,default=>false};if(!$ok)return false;}
        return $this->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_source_order_actions' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN)===['id'];
    }
    public function run(string $file,bool $apply=false,?string $fingerprint=null):array
    {
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)))throw new InvalidArgumentException('Cần fingerprint dry-run.');$r=['scope'=>'readable-original-order-history','ready'=>false,'applied'=>false,'operationalReady'=>false,'fingerprint'=>null,'sourceRows'=>0,'matchingRows'=>0,'insertedRows'=>0,'errors'=>[]];$error=static function(string $code)use(&$r):void{if(count($r['errors'])<100)$r['errors'][]=['code'=>$code];};
        if(TransferFormat::detect($file)!=='full-v2'){$error('FULL_V2_REQUIRED');return $r;}$audit=(new TransferRelations())->run($file);if(!$audit['profileMatches']||$audit['relations']['errorCount']){$error('SOURCE_INVALID');return $r;}$r['sourceSha256']=$audit['sha256'];$orders=[];$source=[];$declared=[];$bytes=0;$count=0;
        $again=TransferFormat::audit($file)->run($file,static function($table,$cols,$values)use(&$orders,&$source,&$declared,&$bytes,&$count,$error):void{
            if(!in_array($table,['jm_transfer_orders','jm_transfer_order_actions','jm_transfer_manifest'],true))return;$row=[];foreach($cols as $i=>$c){$v=$values[$i];$row[$c['name']]=$v==='NULL'?null:(str_starts_with($v,'CONVERT(')?hex2bin(substr($v,10,-16)):$v);}if($table==='jm_transfer_manifest'){$declared[substr($row['source_table'],8)]=$row['status'];return;}
            if(++$count>50000||($bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR)))>33554432)throw new RuntimeException('History source limit.');if($table==='jm_transfer_orders'){$orders[$row['id']]=$row;return;}try{self::validate($row);}catch(Throwable){$error('SOURCE_ACTION_INVALID');}if(isset($source[$row['id']]))$error('DUPLICATE_SOURCE_ACTION');$source[$row['id']]=$row;
        });$r['sourceRows']=count($source);foreach(['orders','order_actions'] as $t)if(($declared[$t]??null)!=='EXPORTED')$error('SOURCE_TABLE_NOT_EXPORTED');if(!$again['profileMatches']||$again['sha256']!==$audit['sha256'])$error('SOURCE_CHANGED');$seen=[];foreach($source as $row){$key=strtolower($row['id']);if(isset($seen[$key]))$error('DUPLICATE_SOURCE_ACTION');$seen[$key]=true;}$parentIds=[];foreach($source as $row){if(!isset($orders[$row['order_id']])){$error('SOURCE_PARENT_MISSING');continue;}$parentIds[$row['order_id']]=true;}foreach($parentIds as $id=>$_)try{OrderCoreTransfer::validateRecord($orders[$id],true);}catch(Throwable){$error('SOURCE_ORDER_INVALID');}
        foreach(['orders','source_order_actions','order_actions','catalog_locks','audit'] as $t)if(strtoupper((string)$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['jingo_'.$t])->fetchColumn())!=='INNODB')$error('RUNTIME_SCHEMA_MISSING');if(!$this->schema())$error('HISTORY_SCHEMA_MISMATCH');if($r['errors'])return $r;
        $this->db->exec("SET time_zone='+00:00'");$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{$suffix=$apply?' FOR UPDATE':'';if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".$suffix)->fetchColumn())throw new RuntimeException('Catalog lock missing.');$states=[];ksort($parentIds,SORT_STRING);foreach($parentIds as $id=>$_){$target=$this->query('SELECT * FROM jingo_orders WHERE id=?'.$suffix,[$id])->fetch(PDO::FETCH_ASSOC);if(!$target){$error('NATIVE_ORDER_MISSING');continue;}if(self::canonical(array_intersect_key($target,$orders[$id]))!==self::canonical($orders[$id]))$error('NATIVE_ORDER_DIFFERS');$states['orders'][$id]=hash('sha256',self::canonical($target));}
            $new=$source;$q=null;$count=0;$bytes=0;$buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);try{$q=$this->query('SELECT * FROM jingo_source_order_actions ORDER BY id LIMIT 50001'.$suffix);while($row=$q->fetch(PDO::FETCH_ASSOC)){if(++$count>50000||($bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR)))>67108864)throw new RuntimeException('History target limit.');$id=$row['id'];$states['history'][$id]=hash('sha256',self::canonical($row));if(!isset($source[$id]))$error('EXTRA_RUNTIME_SOURCE_HISTORY');elseif(self::canonical($source[$id])!==self::canonical($row))$error('EXISTING_HISTORY_DIFFERS');else $r['matchingRows']++;unset($new[$id]);}}finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
            $ids=array_keys($source);sort($ids,SORT_STRING);foreach($ids as $id)if($this->query('SELECT id FROM jingo_order_actions WHERE id=?'.$suffix,[$id])->fetchColumn()!==false)$error('NATIVE_ACTION_ID_COLLISION');
            $fp=hash('sha256','original-order-history-v1:'.$audit['sha256'].':'.json_encode($states,JSON_THROW_ON_ERROR));if($apply&&!hash_equals($fingerprint,$fp))$error('FINGERPRINT_OR_TARGET_CHANGED');if(!is_file($file)||is_link($file)||hash_file('sha256',$file)!==$audit['sha256'])$error('SOURCE_CHANGED_BEFORE_COMMIT');$r['ready']=!$r['errors'];$r['fingerprint']=$r['ready']?$fp:null;if(!$apply||!$r['ready']){$this->db->rollBack();return $r;}
            foreach($new as $row)$this->query('INSERT INTO jingo_source_order_actions(id,order_id,request_hash,admin_id,action,note,created_at) VALUES (?,?,?,?,?,?,?)',[$row['id'],$row['order_id'],$row['request_hash'],$row['admin_id'],$row['action'],$row['note'],$row['created_at']]);if($new)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','transfer.order-history',$fp]);$this->db->commit();$r['insertedRows']=count($new);$r['applied']=true;return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
