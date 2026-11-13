<?php
declare(strict_types=1);

final class TourInfo
{
    public static function effective(array $product,array $variant):array
    {
        $base=$product['tourInfo'] ?? [];$custom=$variant['tourInfo'] ?? [];$out=[];foreach(['duration','durationEn'] as $key){$value=($custom[$key] ?? '')!==''?$custom[$key]:($base[$key] ?? null);if($value!==null)$out[$key]=$value;}foreach(['minGuests','maxGuests'] as $key){$value=$custom[$key] ?? $base[$key] ?? null;if($value!==null)$out[$key]=$value;}return $out;
    }
    public static function check(array $info,int $count):void
    {
        if(isset($info['minGuests'])&&$count<$info['minGuests'])throw new HttpError(400,'Gói tour cần ít nhất '.$info['minGuests'].' khách. Vui lòng thêm '.($info['minGuests']-$count).' khách.');
        if(isset($info['maxGuests'])&&$count>$info['maxGuests'])throw new HttpError(400,'Gói tour nhận tối đa '.$info['maxGuests'].' khách mỗi đơn. Đoàn lớn vui lòng liên hệ.');
    }
}
