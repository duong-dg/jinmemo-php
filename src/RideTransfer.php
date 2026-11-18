<?php
declare(strict_types=1);
require_once __DIR__.'/AdminAuth.php';
require_once __DIR__.'/TransferRelations.php';
require_once __DIR__.'/TransportPlan.php';
require_once __DIR__.'/Media.php';
require_once __DIR__.'/RideRequest.php';

/** Publishes the source plan and standalone unpaid private rides without replaying booking operations. */
final class RideTransfer
{
    private const TABLES=['settings','ride_bookings','ride_pools','ride_payment_events','payment_references','refunds','checkout_members','checkout_bundles'];
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function canonical(string $json):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as &$x)$x=$sort($x);return (object)$a;}return is_array($v)?array_map($sort,$v):$v;};return json_encode($sort(json_decode($json,false,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function integer(mixed $v,int $min,int $max):void{if(!is_int($v)||$v<$min||$v>$max)throw new RuntimeException();}
    private static function text(mixed $v,int $min,int $max,bool $trim=true):void
    {
        if(!is_string($v)||str_contains($v,"\0"))throw new RuntimeException();$n=strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2;if($n<$min||$n>$max||$trim&&preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$v)!==$v)throw new RuntimeException();
    }
    private static function clock(mixed $v):void{if(!is_string($v)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$v))throw new RuntimeException();}
    private static function uuid(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$v)===1;}
    private static function bundleRefs(string $json):array
    {
        $d=json_decode($json,false,512,JSON_THROW_ON_ERROR);if(!$d instanceof stdClass||!isset($d->refs)||!$d->refs instanceof stdClass||get_object_vars($d->refs)===[])throw new RuntimeException();$refs=get_object_vars($d->refs);foreach($refs as $key=>$id)if(!in_array($key,['cartId','rideId','giftId'],true)||!self::uuid($id))throw new RuntimeException();return $refs;
    }
    private static function validate(array $r,array $p,array $linked,array $financial):void
    {
        $d=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if(!self::uuid($r['id'])||!self::uuid($r['request_key'])||!preg_match('/^JM-X[A-F0-9]{12}$/D',$r['code'])||!preg_match('/^[1-9][0-9]{0,9}$/D',$r['version'])||(int)$r['version']>2147483646||!is_array($d))throw new RuntimeException('RIDE_SNAPSHOT_NOT_RUNTIME_COMPATIBLE');
        if(($d['mode']??null)!=='PRIVATE'||!empty($d['autoPool'])||$r['trip_id']!==null||isset($d['shareSize'])||isset($d['poolId']))throw new RuntimeException('SHARED_OR_ASSIGNED_RIDES_REQUIRE_ADAPTER');
        if(isset($linked[$r['id']])||isset($d['bundleCode']))throw new RuntimeException('RIDE_BUNDLE_REQUIRES_COMPONENT_ADAPTERS');
        if(($d['channel']??'WEB')!=='WEB'||isset($d['staffId']))throw new RuntimeException('POS_RIDES_REQUIRE_SHIFT_ADAPTER');
        if(($d['payment']??null)!=='PAY_LATER')throw new RuntimeException('RIDE_QR_RECONCILIATION_REQUIRED');
        if(isset($financial[$r['id']])||isset($financial[$r['code']])||(array_key_exists('paymentStatus',$d)?$d['paymentStatus']:'UNPAID')!=='UNPAID'||($d['paidAmount']??0)!==0||array_intersect(array_keys($d),['paidAt','paymentReference','paidBy','paidMethod','cashRequestHash']))throw new RuntimeException('PAID_RIDE_REQUIRES_FINANCIAL_ADAPTER');
        try{
            if(!in_array($r['status'],['PENDING','MATCHED','WAITING_PICKUP','PICKED_UP','RUNNING','COMPLETED','CANCELLED'],true)||array_diff(array_keys($d),['roundTrip','from','to','passengers','date','time','flexMinutes','mode','locale','returnTime','payment','vehicleId','contact','pickup','dropoff','note','autoPool','shareSize','total','vehicleName','vehicleNote','durationMinutes','fromName','toName','requestHash','paymentStatus','paidAmount','driver','driverPhone','plate','operatorNote','agencyCost','channel']))throw new RuntimeException();
            if(!is_bool($d['roundTrip']??null)||array_key_exists('autoPool',$d)&&$d['autoPool']!==false||array_key_exists('paidAmount',$d)&&$d['paidAmount']!==0)throw new RuntimeException();
            if(StaySchema::date($r['visit_date'])!==($d['date']??null))throw new RuntimeException();self::clock($d['time']??null);if(!in_array($d['flexMinutes']??null,[0,30,60],true))throw new RuntimeException();
            if(array_key_exists('locale',$d)&&!in_array($d['locale'],['vi','en'],true))throw new RuntimeException();if(array_key_exists('returnTime',$d)){self::clock($d['returnTime']);if(!$d['roundTrip']||$d['returnTime']<=$d['time'])throw new RuntimeException();}
            self::integer($d['passengers']??null,1,60);self::integer($d['total']??null,0,15000000000);
            foreach(['from','to','vehicleId'] as $key)self::text($d[$key]??null,1,80,false);if($d['from']===$d['to'])throw new RuntimeException();foreach(['vehicleName','fromName','toName'] as $key)self::text($d[$key]??null,1,150);
            self::text($d['vehicleNote']??'',0,3000,false);if(isset($d['durationMinutes']))self::integer($d['durationMinutes'],1,2880);
            $c=$d['contact']??null;if(!is_array($c)||count($c)!==3||array_diff(array_keys($c),['name','phone','email']))throw new RuntimeException();self::text($c['name'],2,120);self::text($c['phone'],8,25);self::text($c['email'],1,200,false);if(!preg_match('/^\+?[0-9 ()-]{8,25}$/D',$c['phone'])||!filter_var($c['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException();
            foreach(['pickup','dropoff'] as $key)self::text($d[$key]??null,3,300);self::text($d['note']??null,0,1000);if(!is_string($d['requestHash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$d['requestHash']))throw new RuntimeException();
            foreach(['driver'=>120,'driverPhone'=>25,'plate'=>30,'operatorNote'=>1000] as $key=>$max)if(array_key_exists($key,$d))self::text($d[$key],0,$max);if(isset($d['agencyCost']))self::integer($d['agencyCost'],0,15000000000);
            if(in_array($r['status'],['MATCHED','WAITING_PICKUP','PICKED_UP','RUNNING'],true)&&(($d['driver']??'')===''||!preg_match('/^\+?[0-9 ()-]{8,25}$/D',$d['driverPhone']??'')||($d['plate']??'')===''))throw new RuntimeException();
            if(!in_array($r['status'],['COMPLETED','CANCELLED'],true)){$v=array_column($p['vehicles'],null,'id')[$d['vehicleId']]??null;$areas=array_column($p['areas'],null,'id');if(!$v||!isset($areas[$d['from']],$areas[$d['to']])||$v['seats']<$d['passengers'])throw new RuntimeException();}
        }catch(Throwable){throw new RuntimeException('RIDE_SNAPSHOT_NOT_RUNTIME_COMPATIBLE');}
        if(!RideRequest::matchesPrivateSnapshot($r,$d))throw new RuntimeException('RIDE_REQUEST_SNAPSHOT_MISMATCH');
    }
    public function run(string $file,bool $apply=false,?string $fingerprint=null):array
    {
        if($apply&&(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)))throw new InvalidArgumentException('Cần fingerprint dry-run.');
        $r=['scope'=>'runtime-plan-and-standalone-unpaid-private-rides','mode'=>$apply?'apply':'dry-run','ready'=>false,'applied'=>false,'operationalReady'=>false,'fingerprint'=>null,'sourceSha256'=>null,'sourceRows'=>0,'matchingRows'=>0,'insertedRows'=>0,'planInserted'=>false,'errors'=>[],'errorCount'=>0];$error=static function(string $code)use(&$r):void{$r['errorCount']++;if(count($r['errors'])<100)$r['errors'][]=['code'=>$code];};
        if(TransferFormat::detect($file)!=='full-v2'){$error('FULL_V2_REQUIRED');return $r;}$audit=(new TransferRelations())->run($file);$r['sourceSha256']=$audit['sha256'];if(!$audit['profileMatches']||$audit['relations']['errorCount']){$error('SOURCE_FILE_OR_RELATIONS_INVALID');return $r;}
        $tables=[];$declared=[];$count=0;$bytes=0;$again=TransferFormat::audit($file)->run($file,static function(string $table,array $cols,array $values)use(&$tables,&$declared,&$count,&$bytes):void{
            $name=substr($table,12);if(!in_array($name,[...self::TABLES,'manifest'],true))return;$row=[];foreach($cols as $i=>$c){$v=$values[$i];$row[$c['name']]=$v==='NULL'?null:(str_starts_with($v,'CONVERT(')?hex2bin(substr($v,10,-16)):$v);}
            if($name==='manifest'){if(in_array($row['status'],['EXPORTED','FILTERED_BUSINESS_SETTINGS'],true))$declared[substr($row['source_table'],8)]=true;return;}
            if(++$count>50000||($bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR)))>33554432)throw new RuntimeException('Ride metadata limit.');if($name==='settings'&&$row['id']!=='transport-plan')return;$tables[$name][]=$row;
        });
        if(!$again['profileMatches']||$again['sha256']!==$audit['sha256']){$error('SOURCE_CHANGED_DURING_READ');return $r;}foreach(self::TABLES as $t)if(!isset($declared[$t]))$error('SOURCE_DEPENDENCY_NOT_EXPORTED');if($r['errorCount'])return $r;
        $plan=$tables['settings'][0]??null;$rides=$tables['ride_bookings']??[];$r['sourceRows']=count($rides);$normalized=null;
        try{if(!$plan||!preg_match('/^[1-9][0-9]{0,9}$/D',$plan['version'])||(int)$plan['version']>2147483646)throw new RuntimeException();$normalized=TransportPlan::validate(json_decode($plan['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING));}catch(Throwable){$error('TRANSPORT_PLAN_NOT_RUNTIME_COMPATIBLE');return $r;}
        if($tables['ride_pools']??[])$error('SHARED_POOLS_REQUIRE_ADAPTER');$linked=[];$financial=[];
        foreach($tables['checkout_members']??[] as $m)if($m['kind']==='rideId')$linked[$m['entity_id']]=true;
        foreach($tables['checkout_bundles']??[] as $b){try{$refs=self::bundleRefs($b['data']);if(isset($refs['rideId']))$linked[$refs['rideId']]=true;}catch(Throwable){$error('SOURCE_BUNDLE_REFS_INVALID');}}
        foreach($tables['payment_references']??[] as $p)$financial[$p['entity_id']]=true;foreach($tables['ride_payment_events']??[] as $e)$financial[$e['ride_id']]=true;foreach($tables['refunds']??[] as $f)if($f['root_kind']==='RIDE'){$financial[$f['root_id']]=true;$financial[$f['root_code']]=true;}
        $source=[];foreach($rides as $row){try{self::validate($row,$normalized,$linked,$financial);$source[$row['id']]=$row;}catch(RuntimeException $e){$error($e->getMessage());}}if($r['errorCount'])return $r;
        foreach(['settings','ride_bookings','ride_pools','ride_payment_attempts','ride_payment_events','checkout_members','checkout_bundles','payment_references','refunds','catalog_locks','audit','media'] as $t){$engine=$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'',['jingo_'.$t])->fetchColumn();if(strtoupper((string)$engine)!=='INNODB')$error('RUNTIME_SCHEMA_MISSING');}
        $specs=['jingo_settings'=>['id'=>'text:80:ascii','data'=>'json','version'=>'int'],'jingo_ride_bookings'=>['id'=>'text:36:ascii','code'=>'text:17:ascii','request_key'=>'text:36:ascii','visit_date'=>'text:10:ascii','status'=>'text:20:ascii','trip_id'=>'text:80:utf8mb4','data'=>'json','version'=>'int','created_at'=>'datetime6']];
        foreach($specs as $table=>$spec){$cols=$this->query('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,IS_NULLABLE,DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$table])->fetchAll(PDO::FETCH_ASSOC);if(array_column($cols,'COLUMN_NAME')!==array_keys($spec))$error('RUNTIME_SCHEMA_MISMATCH');foreach($cols as $c){$rule=$spec[$c['COLUMN_NAME']]??'';if(str_starts_with($rule,'text:')){[, $min,$charset]=explode(':',$rule);$ok=in_array($c['DATA_TYPE'],['char','varchar'],true)&&(int)$c['CHARACTER_MAXIMUM_LENGTH']>=(int)$min&&$c['CHARACTER_SET_NAME']===$charset;}else $ok=match($rule){'json'=>$c['DATA_TYPE']==='json'||$c['DATA_TYPE']==='longtext'&&$c['CHARACTER_SET_NAME']==='utf8mb4','int'=>$c['DATA_TYPE']==='int','datetime6'=>$c['DATA_TYPE']==='datetime'&&(int)$c['DATETIME_PRECISION']===6,default=>false};if(!$ok||$c['IS_NULLABLE']!==($c['COLUMN_NAME']==='trip_id'?'YES':'NO'))$error('RUNTIME_CAPACITY_MISMATCH');}
            $keys=[];foreach($this->query('SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$table])->fetchAll(PDO::FETCH_ASSOC) as $key)$keys[$key['INDEX_NAME']][]=$key['COLUMN_NAME'];if(($keys['PRIMARY']??[])!==['id']||$table==='jingo_ride_bookings'&&(!in_array(['code'],$keys,true)||!in_array(['request_key'],$keys,true)))$error('RUNTIME_KEYS_MISMATCH');
        }
        if($r['errorCount'])return $r;$this->db->exec("SET time_zone='+00:00'");$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');if(!$apply)$this->db->exec('SET TRANSACTION READ ONLY');$this->db->beginTransaction();
        try{
            $suffix=$apply?' FOR UPDATE':'';if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog'".$suffix)->fetchColumn())throw new RuntimeException('Missing catalog lock.');$target=$this->query("SELECT data,version FROM jingo_settings WHERE id='transport-plan'".$suffix)->fetch(PDO::FETCH_ASSOC);if($target&&((string)$target['version']!==$plan['version']||self::canonical($target['data'])!==self::canonical($plan['data'])))$error('EXISTING_TRANSPORT_PLAN_DIFFERS');
            $images=[];foreach($normalized['vehicles'] as $v)if(isset($v['image']['id']))$images[]=$v['image']['id'];(new Media($this->db,''))->validateIds($images);
            $states=[];$new=$source;$seen=0;$bytes=0;$buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=null;
            try{$q=$this->query('SELECT * FROM jingo_ride_bookings ORDER BY id LIMIT 50001'.$suffix);while($row=$q->fetch(PDO::FETCH_ASSOC)){if(++$seen>50000||($bytes+=strlen($row['data']))>67108864)throw new RuntimeException('Ride target limit.');$id=$row['id'];if(!isset($source[$id])){$error('EXTRA_RUNTIME_RIDE');continue;}$same=true;foreach($source[$id] as $key=>$value){if($key==='data')$ok=self::canonical($row[$key])===self::canonical($value);elseif($value===null)$ok=$row[$key]===null;else $ok=(string)$row[$key]===$value;if(!$ok)$same=false;}if(!$same)$error('EXISTING_RIDE_DIFFERS');else $r['matchingRows']++;$states[$id]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));unset($new[$id]);}}finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
            if(!$target&&$seen)$error('EXISTING_RIDES_WITHOUT_PLAN');if($this->query('SELECT 1 FROM jingo_ride_pools LIMIT 1'.$suffix)->fetchColumn())$error('EXISTING_RUNTIME_POOLS');
            $buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=null;$count=0;$bytes=0;
            try{$q=$this->query('SELECT data FROM jingo_checkout_bundles LIMIT 50001'.$suffix);while(($json=$q->fetchColumn())!==false){if(++$count>50000||($bytes+=strlen($json))>67108864)throw new RuntimeException('Runtime bundle reference limit.');try{$refs=self::bundleRefs($json);if(isset($refs['rideId'],$source[$refs['rideId']]))$error('RUNTIME_RIDE_HAS_FINANCIAL_OR_BUNDLE_LINK');}catch(Throwable){$error('RUNTIME_BUNDLE_REFS_INVALID');}}}finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
            foreach(['SELECT entity_id FROM jingo_checkout_members WHERE kind=\'rideId\'','SELECT entity_id FROM jingo_payment_references','SELECT ride_id AS entity_id FROM jingo_ride_payment_events',"SELECT root_id AS entity_id FROM jingo_refunds WHERE root_kind='RIDE'"] as $sql){$q=$this->query($sql.' LIMIT 100001'.$suffix);$count=0;while(($id=$q->fetchColumn())!==false){if(++$count>100000)throw new RuntimeException('Ride reference limit.');if(isset($source[$id]))$error('RUNTIME_RIDE_HAS_FINANCIAL_OR_BUNDLE_LINK');}$q->closeCursor();}
            // Attempts remain evidence even when expired or failed; importing must not erase that history.
            $q=$this->query('SELECT ride_id FROM jingo_ride_payment_attempts LIMIT 100001'.$suffix);$count=0;while(($id=$q->fetchColumn())!==false){if(++$count>100000)throw new RuntimeException('Ride payment attempt limit.');if(isset($source[$id]))$error('RUNTIME_RIDE_PAYMENT_RECONCILIATION_REQUIRED');}$q->closeCursor();
            $codes=array_fill_keys(array_column($source,'code'),true);$q=$this->query("SELECT root_code FROM jingo_refunds WHERE root_kind='RIDE' LIMIT 100001".$suffix);$count=0;while(($code=$q->fetchColumn())!==false){if(++$count>100000)throw new RuntimeException('Ride refund reference limit.');if(isset($codes[$code]))$error('RUNTIME_RIDE_HAS_FINANCIAL_OR_BUNDLE_LINK');}$q->closeCursor();
            ksort($states,SORT_STRING);$fp=hash('sha256','runtime-private-ride-v1:'.$audit['sha256'].':'.json_encode([$target?:null,$states],JSON_THROW_ON_ERROR));if($apply&&!hash_equals($fingerprint,$fp))$error('FINGERPRINT_OR_TARGET_CHANGED');if(!is_file($file)||is_link($file)||hash_file('sha256',$file)!==$audit['sha256'])$error('SOURCE_CHANGED_BEFORE_COMMIT');$r['ready']=$r['errorCount']===0;$r['fingerprint']=$r['ready']?$fp:null;
            if(!$apply||!$r['ready']){$this->db->rollBack();return $r;}
            if(!$target){$this->query('INSERT INTO jingo_settings(id,data,version) VALUES (?,?,?)',['transport-plan',$plan['data'],$plan['version']]);$r['planInserted']=true;}
            foreach($new as $row)$this->query('INSERT INTO jingo_ride_bookings(id,code,request_key,visit_date,status,trip_id,data,version,created_at) VALUES (?,?,?,?,?,?,?,?,?)',array_map(static fn($k)=>$row[$k],['id','code','request_key','visit_date','status','trip_id','data','version','created_at']));$r['insertedRows']=count($new);if($new||!$target)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'cli-import','transfer.private-rides',$fp]);$this->db->commit();$r['applied']=true;return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
