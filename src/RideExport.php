<?php
declare(strict_types=1);
final class RideExport
{
    public static function filter(array $in):array
    {
        if(array_diff(array_keys($in),['date','status','q']))throw new HttpError(400,'Bộ lọc đơn xe không hợp lệ.');
        $date=$in['date']??'';$status=$in['status']??'';$q=$in['q']??'';
        if(!is_string($date)||!is_string($status)||!is_string($q)||!preg_match('//u',$q)||str_contains($q,"\0"))throw new HttpError(400,'Bộ lọc đơn xe không hợp lệ.');
        $q=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$q);
        if(strlen(mb_convert_encoding($q,'UTF-16LE','UTF-8'))/2>100||$status!==''&&!in_array($status,Rides::STATES,true))throw new HttpError(400,'Bộ lọc đơn xe không hợp lệ.');
        if($date!=='')StaySchema::date($date);
        $where=[];$args=[];
        if($date!==''){$where[]='visit_date=?';$args[]=$date;}
        if($status!==''){$where[]='status=?';$args[]=$status;}
        if($q!==''){$parts=[];foreach(['code',"JSON_UNQUOTE(JSON_EXTRACT(data,'$.contact.name'))","JSON_UNQUOTE(JSON_EXTRACT(data,'$.contact.phone'))","JSON_UNQUOTE(JSON_EXTRACT(data,'$.contact.email'))"] as $column){$parts[]='LOCATE(LOWER(CONVERT(? USING utf8mb4)) COLLATE utf8mb4_bin,LOWER(CONVERT('.$column.' USING utf8mb4)) COLLATE utf8mb4_bin)>0';$args[]=$q;}$where[]='('.implode(' OR ',$parts).')';}
        return ['where'=>$where?' WHERE '.implode(' AND ',$where):'','args'=>$args];
    }
    private static function cell(mixed $value):string
    {
        $text=str_replace("\0",'',(string)($value??''));
        if(preg_match('/^[\s\x{FEFF}]*[=+@-]/u',$text)||preg_match('/^[\t\r\n]/',$text))$text="'".$text;
        return '"'.str_replace('"','""',$text).'"';
    }
    public static function csv(array $rows):string
    {
        $headers=['Mã booking','Ngày đi','Giờ đón','Trạng thái','Hình thức xe','Loại xe','Số khách','Nơi đi','Nơi đến','Địa chỉ đón','Địa chỉ trả','Khách hàng','Điện thoại','Email','Giá trị đơn (VND)','Giá vốn đại lý (VND)','Thanh toán','Tiền đã ghi thu (chưa trừ hoàn) (VND)','Khứ hồi','Giờ về','Tài xế','Điện thoại tài xế','Biển số','Ghi chú khách','Ghi chú nội bộ'];
        $states=['PENDING'=>'Chờ xác nhận','WAITING_MATCH'=>'Chờ ghép','MATCHED'=>'Đã chốt xe','WAITING_PICKUP'=>'Chờ đón','PICKED_UP'=>'Đã đón','RUNNING'=>'Đang đi','COMPLETED'=>'Hoàn tất','CANCELLED'=>'Đã hủy'];
        $lines=[$headers];$phone=static fn($v)=>$v!==null&&$v!==''?"'".$v:'';
        foreach($rows as $r){$d=is_array($r['data'])?$r['data']:json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);$lines[]=[$r['code'],substr($r['visit_date'],0,10),$d['time']??null,$states[$r['status']]??$r['status'],($d['mode']??'')==='SHARED'?'Xe ghép':'Xe riêng',$d['vehicleName']??null,$d['passengers']??null,$d['fromName']??null,$d['toName']??null,$d['pickup']??null,$d['dropoff']??null,$d['contact']['name']??null,$phone($d['contact']['phone']??null),$d['contact']['email']??null,$d['total']??null,$d['agencyCost']??'',($d['paymentStatus']??'')==='PAID'?'Đã thu tiền':'Chưa thu tiền',$d['paidAmount']??'',!empty($d['roundTrip'])?'Có':'Không',$d['returnTime']??null,$d['driver']??null,$phone($d['driverPhone']??null),$d['plate']??null,$d['note']??null,$d['operatorNote']??null];}
        return "\xEF\xBB\xBF".implode("\r\n",array_map(static fn($line)=>implode(',',array_map([self::class,'cell'],$line)),$lines))."\r\n";
    }
    public function __construct(private PDO $db){}
    public function run(array $in,string $actor):array
    {
        $filter=self::filter($in);$q=$this->db->prepare('SELECT code,visit_date,status,data FROM jingo_ride_bookings'.$filter['where'].' ORDER BY visit_date,created_at,id LIMIT 10001');$q->execute($filter['args']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>10000)throw new HttpError(400,'Có hơn 10.000 booking phù hợp. Thu hẹp ngày, trạng thái hoặc từ khóa rồi xuất lại.');
        $csv=self::csv($rows);$q=$this->db->prepare('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)');$q->execute([bin2hex(random_bytes(16)),$actor,'ride.export',(string)count($rows)]);
        return ['csv'=>$csv,'count'=>count($rows)];
    }
}
