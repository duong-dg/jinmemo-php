<?php
declare(strict_types=1);
require_once __DIR__.'/PaymentSettings.php';
require_once __DIR__.'/StayMail.php';
final class HotelPayments
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public static function online(string $method):bool{return in_array($method,['VNPAY','VNPAY_QR','VNPAY_CARD'],true);}
    public function methods():array
    {
        $list=(new ManualMethods($this->db))->active();$c=(new PaymentSettings($this->db))->config();if($c['mode']==='LIVE'&&$c['vnpayEnabled']&&!empty($c['vnpayEncrypted'])&&$c['tmnCode']!=='')foreach(['VNPAY','VNPAY_QR',...($c['vnpayCardEnabled']?['VNPAY_CARD']:[])] as $id)$list[]=['id'=>$id,'name'=>$id==='VNPAY_CARD'?'Thẻ quốc tế qua VNPAY':($id==='VNPAY_QR'?'VNPAY QR':'VNPAY'),'englishName'=>$id==='VNPAY_CARD'?'International card via VNPAY':($id==='VNPAY_QR'?'VNPAY QR':'VNPAY'),'instructions'=>'Giữ phòng 30 phút. Mở tra cứu đơn để thanh toán; chỉ ghi nhận sau xác nhận hợp lệ từ cổng.','englishInstructions'=>'Room hold: 30 minutes. Open your booking to pay; payment is recorded after a verified gateway confirmation.'];return $list;
    }
    public function assertProvider(string $method):void{$c=(new PaymentSettings($this->db))->config();if(!self::online($method)||$c['mode']!=='LIVE'||!$c['vnpayEnabled']||empty($c['vnpayEncrypted'])||$c['tmnCode']===''||$method==='VNPAY_CARD'&&!$c['vnpayCardEnabled'])throw new HttpError(400,'Phương thức thanh toán phòng chưa bật. Vui lòng chọn lại.');}
    public static function eligible(array $r,array $d):bool{return in_array($r['status'],['PENDING','CONFIRMED','CHECKED_IN'],true)&&$d['paymentStatus']==='UNPAID'&&is_int($d['total'])&&$d['total']>0&&$d['total']<=15000000000&&($r['expires_at']===null||strtotime($r['expires_at'].' UTC')>time());}
    public function locked(string $code):array
    {
        if(!$this->db->inTransaction())throw new LogicException('Hotel payment requires a transaction.');$r=$this->query('SELECT * FROM jingo_stay_bookings WHERE code=? FOR UPDATE',[$code])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new HttpError(404,'Không tìm thấy đơn phòng.');$d=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);if(!self::eligible($r,$d))throw new HttpError(409,'Đơn phòng đã thu, hủy, hết giữ phòng hoặc không còn được thanh toán.');return ['row'=>$r,'data'=>$d,'total'=>$d['total']];
    }
    public function authorize(string $code,array $in):void
    {
        $r=$this->query('SELECT access_hash,data FROM jingo_stay_bookings WHERE code=?',[$code])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new HttpError(404,'Không tìm thấy đơn phòng.');$d=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);$ok=isset($in['accessKey'])?hash_equals($r['access_hash'],hash('sha256',$in['accessKey'])):isset($in['email'])&&strcasecmp(trim($d['contact']['email']),trim($in['email']))===0;if(!$ok)throw new HttpError(404,'Không tìm thấy đơn phòng.');
    }
    public function credit(array $s,string $reference):void
    {
        if(!$this->db->inTransaction())throw new LogicException('Hotel credit requires a transaction.');$r=$s['row'];$d=$s['data'];$this->query("INSERT INTO jingo_payment_references(reference_hash,reference,kind,entity_id,amount) VALUES (?,?,'HOTEL_PAYMENT',?,?)",[hash('sha256',$reference),$reference,$r['id'],$s['total']]);$d=array_replace($d,['paymentStatus'=>'PAID','paidAmount'=>$s['total'],'paidAt'=>gmdate('Y-m-d\TH:i:s\Z'),'paymentReference'=>$reference,'paidMethod'=>'VNPAY']);$this->query('UPDATE jingo_stay_bookings SET data=?,expires_at=NULL,version=version+1 WHERE id=?',[json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$r['id']]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'gateway:VNPAY','stay.pay',$r['id']]);StayMail::enqueue($this->db,$r['id'],'PAID',(int)$r['version']+1);
    }
}
