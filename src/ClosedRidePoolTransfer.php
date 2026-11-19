<?php
declare(strict_types=1);
/** Unpaid pool graphs; active states require an explicit opt-in and compatible current plan. */
final class ClosedRidePoolTransfer
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function uuid(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$v)===1;}
    public static function prepare(array $rows,array $rides,bool $withActive=false,array $plan=[],bool $withPaid=false,array $paidGraph=[]):array
    {
        $pools=[];$members=[];
        foreach($rows as $row){
            if(!self::uuid($row['id'])||!in_array($row['status'],$withActive?['OPEN','LOCKED','PICKUP','PICKED_UP','RUNNING','CANCELLED','COMPLETED']:['CANCELLED','COMPLETED'],true)||!preg_match('/^[1-9][0-9]{0,9}$/D',$row['version'])||(int)$row['version']>2147483646||!preg_match('/^(?:[2-9]|[1-5][0-9]|60)$/D',$row['target'])||!preg_match('/^[1-9][0-9]{0,10}$/D',$row['total'])||(int)$row['total']>15000000000)throw new RuntimeException('CLOSED_POOL_NOT_RUNTIME_COMPATIBLE');
            try{StaySchema::date($row['visit_date']);foreach(['from_id','to_id','vehicle_id'] as $key)if(!is_string($row[$key])||$row[$key]===''||strlen(mb_convert_encoding($row[$key],'UTF-16LE','UTF-8'))/2>80||str_contains($row[$key],"\0"))throw new RuntimeException();$data=json_decode($row['data'],false,512,JSON_THROW_ON_ERROR);if(!$data instanceof stdClass||get_object_vars($data)!==[]||$row['from_id']===$row['to_id']||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$row['departure']))throw new RuntimeException();}catch(Throwable){throw new RuntimeException('CLOSED_POOL_NOT_RUNTIME_COMPATIBLE');}$pools[$row['id']]=$row;
        }
        foreach($rides as $ride){$d=json_decode($ride['data'],true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(($d['mode']??null)!=='SHARED'&&empty($d['autoPool']))continue;$p=$pools[$ride['trip_id']??'']??null;
            if(!$p||($d['autoPool']??null)!==true||($d['mode']??null)!=='SHARED'||!in_array($ride['status'],$withActive?['WAITING_MATCH','MATCHED','WAITING_PICKUP','PICKED_UP','RUNNING','COMPLETED','CANCELLED']:['COMPLETED','CANCELLED'],true)||(($d['paymentStatus']??'UNPAID')==='PAID'?(!$withPaid||!isset($paidGraph[$ride['id']])):(($d['paymentStatus']??'UNPAID')!=='UNPAID'||($d['paidAmount']??0)!==0))||($d['channel']??'WEB')!=='WEB'||isset($d['staffId'])||$ride['visit_date']!==$p['visit_date']||($d['from']??null)!==$p['from_id']||($d['to']??null)!==$p['to_id']||($d['vehicleId']??null)!==$p['vehicle_id']||($d['shareSize']??null)!==(int)$p['target']||isset($d['returnTime'])||($d['roundTrip']??false)!==false||isset($d['poolId'])&&$d['poolId']!==$p['id']||array_key_exists('manual',$d)&&$d['manual']!==true)throw new RuntimeException('CLOSED_POOL_MEMBER_NOT_SUPPORTED');
            if(!is_int($d['passengers']??null)||$d['passengers']<1||$d['passengers']>(int)$p['target']||!is_int($d['total']??null))throw new RuntimeException('CLOSED_POOL_MEMBER_NOT_SUPPORTED');
            $base=intdiv((int)$p['total'],(int)$p['target'])*$d['passengers'];$rem=(int)$p['total']%(int)$p['target'];if($d['total']<0||$d['total']>(int)$p['total']||(!$withPaid&&($d['total']<$base+max(0,$rem-((int)$p['target']-$d['passengers']))||$d['total']>$base+min($rem,$d['passengers']))))throw new RuntimeException('CLOSED_POOL_ALLOCATION_INVALID');
            if(!empty($d['manual'])&&(($d['time']??null)!==$p['departure']||($d['flexMinutes']??null)!==0||isset($d['poolId'])))throw new RuntimeException('CLOSED_POOL_MEMBER_NOT_SUPPORTED');
            if(!is_string($d['time']??null)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$d['time'])||!in_array($d['flexMinutes']??null,[0,30,60],true)||abs((int)substr($d['time'],0,2)*60+(int)substr($d['time'],3,2)-((int)substr($p['departure'],0,2)*60+(int)substr($p['departure'],3,2)))>$d['flexMinutes'])throw new RuntimeException('CLOSED_POOL_MEMBER_NOT_SUPPORTED');
            if(in_array($ride['status'],['WAITING_PICKUP','PICKED_UP','RUNNING','COMPLETED'],true)&&(($d['driver']??'')===''||!preg_match('/^\+?[0-9 ()-]{8,25}$/D',$d['driverPhone']??'')||($d['plate']??'')===''))throw new RuntimeException('CLOSED_POOL_DISPATCH_INVALID');$members[$p['id']][]=['row'=>$ride,'data'=>$d];
        }
        $vehicles=array_column($plan['vehicles']??[],null,'id');$areas=array_column($plan['areas']??[],null,'id');
        foreach($pools as $id=>$p){
            $active=array_values(array_filter($members[$id]??[],static fn($m)=>$m['row']['status']!=='CANCELLED'));$state=$p['status'];$dispatch=in_array($state,['PICKUP','PICKED_UP','RUNNING','COMPLETED'],true);
            if($state==='CANCELLED'&&$active)throw new RuntimeException('CLOSED_POOL_STATE_DIFFERS');
            if(!in_array($state,['COMPLETED','CANCELLED'],true)&&(!isset($vehicles[$p['vehicle_id']],$areas[$p['from_id']],$areas[$p['to_id']])||$vehicles[$p['vehicle_id']]['seats']<(int)$p['target']))throw new RuntimeException('ACTIVE_POOL_PLAN_NOT_COMPATIBLE');
            $expectedState=['OPEN'=>'WAITING_MATCH','LOCKED'=>'MATCHED','PICKUP'=>'WAITING_PICKUP','PICKED_UP'=>'PICKED_UP','RUNNING'=>'RUNNING','COMPLETED'=>'COMPLETED'][$state]??null;
            usort($active,static fn($a,$b)=>strcmp($a['row']['created_at'],$b['row']['created_at'])?:strcmp($a['row']['id'],$b['row']['id']));$offset=0;$driver=null;$paidSeats=0;$paidTotal=0;foreach($active as $m)if(($m['data']['paymentStatus']??'UNPAID')==='PAID'){$paidSeats+=$m['data']['passengers'];$paidTotal+=$m['data']['total'];}$shareTarget=(int)$p['target']-$paidSeats;$shareTotal=(int)$p['total']-$paidTotal;if($shareTarget<0||$shareTotal<0||$shareTarget===0&&$shareTotal!==0)throw new RuntimeException('POOL_PAID_ALLOCATION_INVALID');$used=0;
            foreach($active as $m){$d=$m['data'];if($m['row']['status']!==$expectedState)throw new RuntimeException('CLOSED_POOL_STATE_DIFFERS');
                if($dispatch){$tuple=[$d['driver'],$d['driverPhone'],$d['plate'],$d['operatorNote']??''];if($driver!==null&&$driver!==$tuple)throw new RuntimeException('CLOSED_POOL_DISPATCH_INVALID');$driver=$tuple;}
                $used+=$d['passengers'];if(($d['paymentStatus']??'UNPAID')!=='PAID'){if($shareTarget<1)throw new RuntimeException('POOL_PAID_ALLOCATION_INVALID');$expected=intdiv($shareTotal,$shareTarget)*$d['passengers']+min($d['passengers'],max(0,$shareTotal%$shareTarget-$offset));if($d['total']!==$expected)throw new RuntimeException('CLOSED_POOL_ALLOCATION_INVALID');$offset+=$d['passengers'];}
            }
            if($state==='OPEN'?($used<1||$used>=(int)$p['target']):($state!=='CANCELLED'&&$used!==(int)$p['target']))throw new RuntimeException('CLOSED_POOL_CAPACITY_DIFFERS');
        }return $pools;
    }
    private static function canonical(string $json):string{$d=json_decode($json,false,512,JSON_THROW_ON_ERROR);return json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public function inspect(array $source,bool $lock):array
    {
        $errors=[];$new=$source;$state=[];$matching=0;$count=0;$bytes=0;$q=null;$buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
        try{$q=$this->query('SELECT * FROM jingo_ride_pools ORDER BY id LIMIT 50001'.($lock?' FOR UPDATE':''));while($row=$q->fetch(PDO::FETCH_ASSOC)){if(++$count>50000||($bytes+=strlen($row['data']))>67108864)throw new RuntimeException('Pool target limit.');$id=$row['id'];if(!isset($source[$id])){$errors[]='EXTRA_RUNTIME_POOL';continue;}$same=true;foreach($source[$id] as $key=>$value)if(($key==='data'?self::canonical($row[$key])!==self::canonical($value):(string)$row[$key]!==$value))$same=false;if(!$same)$errors[]='EXISTING_POOL_DIFFERS';else $matching++;$state[$id]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));unset($new[$id]);}}finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}return ['errors'=>array_values(array_unique($errors)),'new'=>$new,'state'=>$state,'matchingRows'=>$matching];
    }
    public function insert(array $rows):void{if(!$this->db->inTransaction())throw new LogicException('Pools require the ride import transaction.');$keys=['id','visit_date','from_id','to_id','vehicle_id','departure','target','total','status','data','version','created_at'];foreach($rows as $row)$this->query('INSERT INTO jingo_ride_pools('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_map(static fn($key)=>$row[$key],$keys));}
}
