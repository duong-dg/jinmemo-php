<?php
declare(strict_types=1);
require_once __DIR__.'/StayPricing.php';
require_once __DIR__.'/StayVouchers.php';
require_once __DIR__.'/FeatureSettings.php';
final class StayQuotes
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function preview(array $input):array{return $this->calculate($input,false);}
    public function quote(array $input):array{return $this->calculate($input,true);}
    private function calculate(array $input,bool $public):array
    {
        $in=StayPricing::selection($input);$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');if($public&&!(new FeatureSettings($this->db))->read(true)['staysEnabled'])throw new HttpError(409,'Lưu trú đang tắt.');$row=$this->query('SELECT data,version FROM jingo_stays WHERE id=?',[$in['hotelId']])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new HttpError(404,'Không tìm thấy cơ sở lưu trú.');$p=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);if($public&&$p['status']!=='PUBLISHED')throw new HttpError(404,'Cơ sở chưa mở xem giá.');if($p['status']==='ARCHIVED')throw new HttpError(409,'Cơ sở đã lưu trữ. Khôi phục trước khi tính thử.');$base=StayPricing::calculate($p,$in);$this->query("UPDATE jingo_stay_bookings SET status='EXPIRED',version=version+1 WHERE hotel_id=? AND status='PENDING' AND expires_at<=UTC_TIMESTAMP(6) AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.paymentStatus'))='UNPAID'",[$in['hotelId']]);$held=$this->query("SELECT check_in,check_out,rooms FROM jingo_stay_bookings WHERE hotel_id=? AND room_id=? AND check_in<? AND check_out>? AND status NOT IN ('CANCELLED','EXPIRED')",[$in['hotelId'],$in['roomId'],$in['checkOut'],$in['checkIn']])->fetchAll(PDO::FETCH_ASSOC);$nights=[];foreach($base['nights'] as $n){$used=0;foreach($held as $b)if($b['check_in']<=$n['date']&&$b['check_out']>$n['date'])$used+=(int)$b['rooms'];$remaining=max(0,$n['stock']-$used);if($remaining<$in['rooms'])throw new HttpError(409,'Đêm '.$n['date'].' chỉ còn '.$remaining.' phòng.');unset($n['stock']);$n['remaining']=$remaining;$nights[]=$n;}$voucher=(new StayVouchers($this->db))->discount($in,$base);$out=['preview'=>true,'hotelId'=>$in['hotelId'],'roomId'=>$in['roomId'],'hotelStatus'=>$p['status'],'hotelVersion'=>(int)$row['version'],'nights'=>$nights,'subtotal'=>$base['subtotal'],'originalTotal'=>$base['originalTotal'],'discount'=>$voucher['discount'],'total'=>$voucher['total'],'currency'=>'VND'];if($public)unset($out['preview'],$out['hotelStatus'],$out['hotelVersion']);$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
