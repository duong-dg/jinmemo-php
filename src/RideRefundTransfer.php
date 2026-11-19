<?php
declare(strict_types=1);
require_once __DIR__.'/RefundTransfer.php';

/** Composes historical private ride refunds inside the parent ride import transaction. */
final class RideRefundTransfer
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public static function prepare(array $rows,array $rides,array $paid):array
    {
        $parents=array_column($rides,null,'id');$codes=array_fill_keys(array_column($rides,'code'),true);$result=[];$totals=[];$references=[];$sourceKeys=array_count_values(array_column($rows,'reference_key'));
        foreach($rows as $row){
            if($row['root_kind']!=='RIDE'){if(isset($codes[$row['root_code']]))throw new RuntimeException('RIDE_REFUND_KIND_MISMATCH');continue;}$parent=$parents[$row['root_id']]??null;
            if(!$parent||$parent['code']!==$row['root_code']||!isset($paid[$row['root_id']]))throw new RuntimeException('RIDE_REFUND_PARENT_NOT_READY');
            try{RefundTransfer::validateRecord($row);if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$row['id']))throw new RuntimeException();$data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);if($data['payeeType']!=='CUSTOMER'||isset($result[$row['id']])||isset($references[$row['reference_key']])||($sourceKeys[$row['reference_key']]??0)!==1)throw new RuntimeException();}catch(Throwable){throw new RuntimeException('RIDE_REFUND_RECORD_INVALID');}
            $totals[$row['root_id']]=($totals[$row['root_id']]??0)+(int)$row['amount'];if($totals[$row['root_id']]>(int)$paid[$row['root_id']]['receipt']['amount'])throw new RuntimeException('RIDE_REFUND_EXCEEDS_PAID');
            $references[$row['reference_key']]=true;$result[$row['id']]=$row;
        }return $result;
    }
    private static function canonical(mixed $v):string
    {
        $sort=static function(mixed $x)use(&$sort):mixed{if($x instanceof stdClass){$a=get_object_vars($x);ksort($a,SORT_STRING);foreach($a as &$value)$value=$sort($value);return (object)$a;}return is_array($x)?array_map($sort,$x):$x;};return json_encode($sort($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public function inspect(array $source,array $rides,bool $lock):array
    {
        $codes=array_fill_keys(array_column($rides,'code'),true);$references=array_fill_keys(array_column($source,'reference_key'),true);$errors=[];$states=[];$new=$source;$matching=0;$seen=0;$bytes=0;$q=null;$buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
        try{$q=$this->query('SELECT * FROM jingo_refunds ORDER BY id LIMIT 50001'.($lock?' FOR UPDATE':''));while($row=$q->fetch(PDO::FETCH_ASSOC)){
            if(++$seen>50000||($bytes+=strlen($row['data']))>67108864)throw new RuntimeException('Ride refund target limit.');$id=$row['id'];$relevant=isset($source[$id])||isset($references[$row['reference_key']])||isset($codes[$row['root_code']])||$row['root_kind']==='RIDE'&&isset($rides[$row['root_id']]);if(!$relevant)continue;
            if(!isset($source[$id])){$errors[]='EXTRA_RUNTIME_RIDE_REFUND';continue;}$same=true;foreach($source[$id] as $key=>$value){$ok=$key==='data'?self::canonical(json_decode($row[$key],false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING))===self::canonical(json_decode($value,false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING)):(string)$row[$key]===$value;if(!$ok)$same=false;}
            if(!$same)$errors[]='EXISTING_RIDE_REFUND_DIFFERS';else $matching++;$states[$id]=hash('sha256',self::canonical($row));unset($new[$id]);
        }}finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
        ksort($states,SORT_STRING);return ['errors'=>array_values(array_unique($errors)),'state'=>$states,'new'=>$new,'matchingRows'=>$matching];
    }
    public function insert(array $rows):void
    {
        if(!$this->db->inTransaction())throw new LogicException('Historical ride refunds require the parent import transaction.');$keys=['id','root_kind','root_id','root_code','amount','refunded_on','reference_key','data','created_at'];foreach($rows as $row)$this->query('INSERT INTO jingo_refunds('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_map(static fn($key)=>$row[$key],$keys));
    }
}
