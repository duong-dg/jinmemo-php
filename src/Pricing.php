<?php
declare(strict_types=1);
require_once __DIR__.'/Schedule.php';

final class Pricing
{
    public const MAX_SAFE=9007199254740991;
    public const GROUPS=['ADULT','CHILD','SENIOR','LOCAL_ADULT','LOCAL_CHILD','LOCAL_SENIOR'];
    private static function fail(string $message): never { throw new HttpError(400,$message); }
    public static function validateDate(string $date): void
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))self::fail('Ngày không hợp lệ.');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        if(!$parsed || $parsed->format('Y-m-d')!==$date)self::fail('Ngày không hợp lệ.');
    }
    private static function safe(mixed $value): bool { return (is_int($value) || (is_float($value) && is_finite($value) && floor($value)===$value)) && $value>=0 && $value<=self::MAX_SAFE; }
    public static function schedule(array $variant,string $date,?string $slotId,?int $now=null): ?array
    {
        $slots=Schedule::slots($variant,$date);
        if(!$slots){if($slotId!==null && $slotId!=='')self::fail('Gói này không sử dụng khung giờ.');return null;}
        $slot=null;foreach($slots as $candidate)if($candidate['id']===$slotId){$slot=$candidate;break;}
        if(!$slot)self::fail('Chọn khung giờ sử dụng.');
        $start=new DateTimeImmutable($date.'T'.$slot['start'].':00+07:00');
        if(($now ?? (int)floor(microtime(true)*1000))>=$start->getTimestamp()*1000)self::fail('Đã quá giờ bắt đầu. Vui lòng chọn khung giờ hoặc ngày khác.');
        return array_merge($slot,['requiresSelection'=>count($slots)>1]);
    }
    public static function calculate(array $product,string $variantId,string $date,array $quantities,array $rules=[],array $closures=[],?string $slotId=null,?int $now=null): array
    {
        self::validateDate($date);$variant=null;foreach($product['variants'] as $item)if($item['id']===$variantId){$variant=$item;break;}
        if(!$variant)self::fail('Gói vé không tồn tại.');if(isset($variant['saleWindow']['startDate'])&&$date<$variant['saleWindow']['startDate']||isset($variant['saleWindow']['endDate'])&&$date>$variant['saleWindow']['endDate'])self::fail('Gói vé chưa mở bán cho ngày sử dụng này.');$slot=self::schedule($variant,$date,$slotId,$now);
        $weekday=(int)(new DateTimeImmutable($date.'T00:00:00Z'))->format('N');
        foreach($closures as $closure)if($closure['productId']===$product['id'] && (!isset($closure['variantId']) || $closure['variantId']==='' || $closure['variantId']===$variantId) && Schedule::closureApplies($variant,$date,$closure))self::fail('Ngày này đã khóa bán.');
        if(!$quantities || count(array_filter($quantities,fn($q)=>$q!==0 && $q!==0.0))===0)self::fail('Chọn ít nhất một khách.');
        $lines=[];$guests=0;$total=0;
        foreach($quantities as $type=>$quantity){
            if(!in_array($type,self::GROUPS,true))self::fail('Nhóm khách không hợp lệ.');
            if(!self::safe($quantity) || $quantity>50)self::fail('Số khách phải từ 0 đến 50.');$quantity=(int)$quantity;if($quantity===0)continue;
            if(!array_key_exists($type,$variant['prices']))self::fail('Gói không áp dụng cho nhóm khách này.');
            $candidates=array_values(array_filter($rules,fn($r)=>$r['productId']===$product['id'] && $r['variantId']===$variantId && $r['customerType']===$type && $r['startDate']<=$date && $date<=$r['endDate'] && (!isset($r['weekdays']) || in_array($weekday,$r['weekdays'],true))));
            usort($candidates,fn($a,$b)=>$b['priority']<=>$a['priority']);
            if(count($candidates)>1 && $candidates[0]['priority']==$candidates[1]['priority'])self::fail('Lịch giá bị xung đột.');
            $price=$candidates[0]['amount'] ?? $variant['prices'][$type];if(!self::safe($price))self::fail('Giá không hợp lệ.');$price=(int)$price;
            $subtotal=$quantity*$price;$total+=$subtotal;$guests+=$quantity;
            $lines[]=['customerType'=>$type,'quantity'=>$quantity,'unitPrice'=>$price,'subtotal'=>$subtotal,'ruleId'=>$candidates[0]['id'] ?? 'base'];
        }
        if($guests>150)self::fail('Mỗi đơn tối đa 150 khách.');if(!self::safe($total))self::fail('Tổng tiền vượt giới hạn.');
        $quote=['productId'=>$product['id'],'productName'=>$product['name'],'variantId'=>$variantId,'variantName'=>$variant['name'],'date'=>$date];if($slot)$quote['timeSlot']=$slot;
        return array_merge($quote,['currency'=>'VND','lines'=>$lines,'total'=>$total,'demo'=>$product['demo'] ?? true]);
    }
    public static function unitPrice(int $price,?array $discount=null): int
    {
        if(!$discount || $discount['type']==='NONE')return $price;
        // Split quotient/remainder to avoid floating point multiplication of currency.
        $amount=$discount['type']==='PERCENT'?intdiv($price,100)*$discount['value']+intdiv(($price%100)*$discount['value'],100):$discount['value'];
        return max(0,$price-$amount);
    }
    public static function online(array $product,string $variantId,string $date,array $quantities,array $rules=[],array $closures=[],?string $slotId=null,?int $now=null): array
    {
        $quote=self::calculate($product,$variantId,$date,$quantities,$rules,$closures,$slotId,$now);$variant=null;foreach($product['variants'] as $v)if($v['id']===$variantId)$variant=$v;
        $discount=$variant['onlineDiscount'] ?? $product['onlineDiscount'] ?? null;$original=$quote['total'];$total=0;
        foreach($quote['lines'] as &$line){$price=self::unitPrice($line['unitPrice'],$discount);if($price<$line['unitPrice']){$line['originalUnitPrice']=$line['unitPrice'];$line['unitDiscount']=$line['unitPrice']-$price;}$line['unitPrice']=$price;$line['subtotal']=$price*$line['quantity'];$total+=$line['subtotal'];}unset($line);
        $quote['total']=$total;if($original>$total && $discount)$quote['onlineDiscount']=array_merge($discount,['amount'=>$original-$total,'subtotal'=>$original]);return $quote;
    }
    public static function checkVoucher(array $quote,?string $voucher): void
    {
        if($voucher!==null && $voucher!=='' && !empty($quote['onlineDiscount']['amount']))self::fail('Ưu đãi online không cộng dồn mã giảm giá. Bỏ mã để dùng giá online.');
    }
    public static function publicVariants(array $product): array
    {
        $variants=$product['variants'];foreach($variants as &$v){$v['originalPrices']=$v['prices'];foreach($v['prices'] as &$price)$price=self::unitPrice($price,$v['onlineDiscount'] ?? $product['onlineDiscount'] ?? null);unset($price);}unset($v);usort($variants,fn($a,$b)=>($a['displayOrder'] ?? 10001)<=>($b['displayOrder'] ?? 10001));return $variants;
    }
}
