<?php
declare(strict_types=1);
require_once __DIR__.'/StaySchema.php';
final class StayPricing
{
    public static function selection(array $in):array
    {
        if(array_diff(array_keys($in),['hotelId','roomId','checkIn','checkOut','rooms','adults','children','voucherCode','locale']))throw new HttpError(400,'Lựa chọn phòng không hợp lệ.');$out=[];foreach(['hotelId','roomId'] as $key){if(!is_string($in[$key] ?? null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$in[$key]))throw new HttpError(400,'Mã cơ sở hoặc phòng không hợp lệ.');$out[$key]=strtolower($in[$key]);}foreach(['checkIn','checkOut'] as $key)$out[$key]=StaySchema::date($in[$key] ?? null);foreach(['rooms'=>[1,20],'adults'=>[1,100],'children'=>[0,100]] as $key=>[$min,$max]){if(!is_int($in[$key] ?? null)||$in[$key]<$min||$in[$key]>$max)throw new HttpError(400,'Kiểm tra số phòng, người lớn và trẻ em.');$out[$key]=$in[$key];}if(array_key_exists('voucherCode',$in)&&!is_string($in['voucherCode']))throw new HttpError(400,'Voucher không hợp lệ.');$voucher=trim($in['voucherCode'] ?? '');if(mb_strlen($voucher,'UTF-8')>40)throw new HttpError(400,'Voucher không hợp lệ.');$out['voucherCode']=mb_strtoupper($voucher,'UTF-8');$out['locale']=$in['locale'] ?? 'vi';if(array_key_exists('locale',$in)&&!is_string($in['locale'])||!in_array($out['locale'],['vi','en'],true))throw new HttpError(400,'Ngôn ngữ không hợp lệ.');return $out;
    }
    public static function nights(string $in,string $out):array
    {
        StaySchema::date($in);StaySchema::date($out);$start=new DateTimeImmutable($in,new DateTimeZone('UTC'));$end=new DateTimeImmutable($out,new DateTimeZone('UTC'));$n=(int)$start->diff($end)->format('%r%a');if($n<1||$n>30)throw new HttpError(400,'Mỗi lần đặt từ 1 đến 30 đêm. Ngày trả phòng phải sau ngày nhận.');$dates=[];for($i=0;$i<$n;$i++)$dates[]=$start->modify('+'.$i.' days')->format('Y-m-d');return $dates;
    }
    public static function calculate(array $property,array $input,?string $today=null):array
    {
        $room=null;foreach($property['rooms'] as $r)if($r['id']===$input['roomId']&&$r['active'])$room=$r;if(!$room)throw new HttpError(400,'Loại phòng chưa mở bán.');$today??=(new DateTimeImmutable('now',new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');if($input['checkIn']<$today)throw new HttpError(400,'Ngày nhận phòng đã qua.');$dates=self::nights($input['checkIn'],$input['checkOut']);if(count($dates)<$property['minNights']||count($dates)>$property['maxNights'])throw new HttpError(400,'Cơ sở nhận đặt từ '.$property['minNights'].' đến '.$property['maxNights'].' đêm.');$qty=$input['rooms'];if($input['adults']+$input['children']>$room['maxGuests']*$qty||$input['adults']>$room['maxAdults']*$qty||$input['children']>$room['maxChildren']*$qty)throw new HttpError(400,'Số khách vượt sức chứa của loại phòng. Hãy tăng số phòng hoặc đổi loại.');$lines=[];$subtotal=0;$original=0;foreach($dates as $date){$night=StaySchema::night($room,$date);if($night['closed']||$night['stock']<$qty)throw new HttpError(400,'Đêm '.$date.' đã khóa bán hoặc không đủ phòng.');$line=$night+['quantity'=>$qty,'total'=>$night['unitPrice']*$qty];$subtotal+=$line['total'];$original+=$night['price']*$qty;$lines[]=$line;}if($subtotal<0||$subtotal>15000000000)throw new HttpError(400,'Tổng tiền lưu trú không hợp lệ.');return ['room'=>$room,'nights'=>$lines,'subtotal'=>$subtotal,'originalTotal'=>$original,'total'=>$subtotal];
    }
}
