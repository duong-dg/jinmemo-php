<?php
declare(strict_types=1);

/** Node/Zod request serialization, including nested property order lost by PostgreSQL JSONB. */
final class RideRequest
{
    public static function hash(array $input,?string $staffId=null):string
    {
        $ordered=[];foreach(['roundTrip','from','to','passengers','date','time','flexMinutes','mode','locale','returnTime','payment','shareSize','poolId','requestKey','vehicleId','expectedTotal','contact','pickup','dropoff','note'] as $key)if(array_key_exists($key,$input))$ordered[$key]=$input[$key];
        if(isset($ordered['contact'])&&is_array($ordered['contact'])){$c=$ordered['contact'];$ordered['contact']=['name'=>$c['name'],'phone'=>$c['phone'],'email'=>$c['email']];}
        return hash('sha256',json_encode($staffId===null?$ordered:['input'=>$ordered,'staffId'=>$staffId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
    }
    public static function matchesPrivateSnapshot(array $row,array $data):bool
    {
        $input=[];foreach(['from','to','passengers','date','time','flexMinutes','mode','locale','returnTime','payment','vehicleId','contact','pickup','dropoff','note'] as $key)if(array_key_exists($key,$data))$input[$key]=$data[$key];$input['requestKey']=$row['request_key'];$input['expectedTotal']=$data['total'];
        // A round-trip-only fare can override a false/omitted request flag in the stored snapshot.
        foreach($data['roundTrip']?[true,false,null]:[false,null] as $flag){if($flag===null)unset($input['roundTrip']);else $input['roundTrip']=$flag;if(hash_equals($data['requestHash'],self::hash($input)))return true;}return false;
    }
    public static function manualGuestHash(string $poolId,array $input):string
    {
        $ordered=[];foreach(['requestKey','name','phone','passengers','note'] as $key)$ordered[$key]=$input[$key];
        return hash('sha256',json_encode(['id'=>$poolId,'input'=>$ordered],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
    }
    public static function matchesClosedSharedSnapshot(array $row,array $data,array $pool):bool
    {
        if(!empty($data['manual']))return hash_equals($data['requestHash'],self::manualGuestHash($pool['id'],['requestKey'=>$row['request_key'],'name'=>$data['contact']['name'],'phone'=>$data['contact']['phone'],'passengers'=>$data['passengers'],'note'=>$data['note']]));
        $input=[];foreach(['from','to','passengers','date','time','flexMinutes','mode','locale','payment','poolId','vehicleId','contact','pickup','dropoff','note'] as $key)if(array_key_exists($key,$data))$input[$key]=$data[$key];$input['requestKey']=$row['request_key'];
        // An unpaid pool's frozen total/target bounds all possible original quote offsets.
        $totals=[];for($offset=0;$offset<=(int)$pool['target']-$data['passengers'];$offset++)$totals[intdiv((int)$pool['total'],(int)$pool['target'])*$data['passengers']+min($data['passengers'],max(0,(int)$pool['total']%(int)$pool['target']-$offset))]=true;
        foreach(array_keys($totals) as $total)foreach([false,null] as $flag)foreach(((int)$pool['target']===2?[true,false]:[true]) as $size){$input['expectedTotal']=$total;if($flag===null)unset($input['roundTrip']);else $input['roundTrip']=$flag;if($size)$input['shareSize']=(int)$pool['target'];else unset($input['shareSize']);if(hash_equals($data['requestHash'],self::hash($input)))return true;}return false;
    }
}
