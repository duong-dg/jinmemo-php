<?php
declare(strict_types=1);
require_once __DIR__.'/ProductLocation.php';

final class ProductSchema
{
    private static function fail(string $message): never {throw new HttpError(400,$message);}
    private static function keys(array $data,array $keys): void {if(array_diff(array_keys($data),$keys))self::fail('Có trường sản phẩm không được hỗ trợ.');}
    private static function text(array $data,string $key,int $max,bool $required=false,string $default=''): string
    {
        $value=$data[$key] ?? ($required?null:$default);if(!is_string($value))self::fail('Trường '.$key.' không hợp lệ.');$value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);
        if($value===null || ($required && $value==='') || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max)self::fail('Trường '.$key.' không hợp lệ.');return $value;
    }
    private static function integer(mixed $value,int $max): int {if((!is_int($value)&&!(is_float($value)&&is_finite($value)&&floor($value)===$value)) || $value<0 || $value>$max)self::fail('Số nguyên ngoài phạm vi cho phép.');return (int)$value;}
    private static function id(mixed $id,bool $uuid=false): string {if(!is_string($id) || !preg_match($uuid?'/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD':'/^[a-zA-Z0-9_-]{1,80}$/D',$id))self::fail('Mã tham chiếu không hợp lệ.');return $id;}
    private static function list(mixed $value,int $max,int $min=0): array {if(!is_array($value) || !array_is_list($value) || count($value)<$min || count($value)>$max)self::fail('Danh sách ngoài phạm vi cho phép.');return $value;}
    private static function object(mixed $value): array {if(!is_array($value))self::fail('Dữ liệu đối tượng không hợp lệ.');return $value;}
    private static function html(array $data,string $key): string {$html=$data[$key] ?? '';if(!is_string($html) || strlen(mb_convert_encoding($html,'UTF-16LE','UTF-8'))/2>200000)self::fail('HTML quá dài hoặc không hợp lệ.');return ContentHtml::clean($html);}
    private static function image(mixed $image): ?array {if($image===null)return null;$image=self::object($image);self::keys($image,['id','alt']);if(!array_key_exists('alt',$image))self::fail('Thiếu mô tả ảnh.');return ['id'=>self::id($image['id'] ?? null,true),'alt'=>self::text($image,'alt',300)];}
    private static function conditions(mixed $value): array {$value=self::object($value);self::keys($value,Pricing::GROUPS);$result=[];foreach($value as $key=>$text)$result[$key]=self::text($value,$key,300);return $result;}
    public static function discount(mixed $value): array
    {
        $value=self::object($value);self::keys($value,['type','value']);$type=$value['type'] ?? '';if(!in_array($type,['NONE','PERCENT','AMOUNT'],true))self::fail('Loại ưu đãi không hợp lệ.');
        $amount=self::integer($value['value'] ?? ($type==='NONE'?0:null),$type==='PERCENT'?100:100000000);if(($type==='NONE' && $amount!==0)||($type!=='NONE' && $amount===0))self::fail('Giá trị ưu đãi không hợp lệ.');return ['type'=>$type,'value'=>$amount];
    }
    private static function weekdays(mixed $days,bool $unique=false): array {$days=self::list($days,7,1);foreach($days as $day)if(!is_int($day)||$day<1||$day>7)self::fail('Thứ trong tuần không hợp lệ.');if($unique&&count(array_unique($days))!==count($days))self::fail('Các thứ khóa bán không được trùng.');return $days;}
    private static function location(mixed $value):array
    {
        $value=self::object($value);self::keys($value,['address','addressEn','mapUrl']);$out=[];foreach(['address'=>500,'addressEn'=>500,'mapUrl'=>2000] as $key=>$limit){if(array_key_exists($key,$value)&&!is_string($value[$key]))self::fail('Địa chỉ hoặc liên kết bản đồ không hợp lệ.');$out[$key]=self::text($value,$key,$limit);}if(!ProductLocation::validMapUrl($out['mapUrl']))self::fail('Nhập link Google Maps HTTPS hợp lệ.');return $out;
    }
    private static function serviceDetails(mixed $value):array
    {
        $value=self::object($value);self::keys($value,['custom','included','excluded','includedEn','excludedEn']);$out=[];if(array_key_exists('custom',$value)){if(!is_bool($value['custom']))self::fail('Cấu hình dịch vụ riêng không hợp lệ.');$out['custom']=$value['custom'];}foreach(['included','excluded','includedEn','excludedEn'] as $key){if(array_key_exists($key,$value)&&!is_string($value[$key]))self::fail('Nội dung dịch vụ không hợp lệ.');$out[$key]=self::text($value,$key,6000);}return $out;
    }
    private static function tourInfo(mixed $value):array
    {
        $value=self::object($value);self::keys($value,['duration','durationEn','minGuests','maxGuests']);$out=[];foreach(['duration','durationEn'] as $key)if(array_key_exists($key,$value)){if(!is_string($value[$key]))self::fail('Thời lượng tour không hợp lệ.');$out[$key]=self::text($value,$key,150);}foreach(['minGuests','maxGuests'] as $key)if(array_key_exists($key,$value)){$out[$key]=self::integer($value[$key],150);if($out[$key]<1)self::fail('Số khách tour phải từ 1 đến 150.');}return $out;
    }
    private static function slots(mixed $value,int $min=0):array
    {
        $out=[];$ids=[];$starts=[];foreach(self::list($value,30,$min) as $slot){$slot=self::object($slot);self::keys($slot,['id','start','end']);$id=self::id($slot['id'] ?? null);$start=$slot['start'] ?? null;$end=$slot['end'] ?? null;if(!is_string($start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$start)||(array_key_exists('end',$slot)&&(!is_string($end)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$end)||$end<=$start)))self::fail('Khung giờ không hợp lệ.');if(isset($ids[$id])||isset($starts[$start]))self::fail('Khung giờ bị trùng mã hoặc giờ bắt đầu.');$ids[$id]=true;$starts[$start]=true;$s=['id'=>$id,'start'=>$start];if(isset($slot['end']))$s['end']=$end;$out[]=$s;}return $out;
    }
    public static function validate(array $input): array
    {
        self::keys($input,['id','supplierId','slug','name','destination','destinationId','type','description','cover','gallery','detailHtml','status','demo','version','variants','rules','closures','english','seoTitle','seoDescription','onlineDiscount','displayOrder','tourInfo','serviceDetails','location']);
        if(!array_key_exists('description',$input))self::fail('Thiếu mô tả sản phẩm.');foreach(['onlineDiscount','demo','gallery','detailHtml','rules','closures','english','seoTitle','seoDescription'] as $key)if(array_key_exists($key,$input)&&$input[$key]===null)self::fail('Trường '.$key.' không được null.');
        $p=['id'=>self::id($input['id'] ?? null),'name'=>self::text($input,'name',200,true),'destination'=>self::text($input,'destination',150,true),'type'=>self::id($input['type'] ?? null),'description'=>self::text($input,'description',10000),'slug'=>$input['slug'] ?? null,'status'=>$input['status'] ?? null,'version'=>self::integer($input['version'] ?? null,2147483646),'demo'=>$input['demo'] ?? false,'cover'=>self::image($input['cover'] ?? null),'gallery'=>[],'detailHtml'=>self::html($input,'detailHtml'),'seoTitle'=>self::text($input,'seoTitle',200),'seoDescription'=>self::text($input,'seoDescription',500)];
        if(!is_string($p['slug'])||strlen($p['slug'])>160||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$p['slug'])||!in_array($p['status'],['DRAFT','PUBLISHED','ARCHIVED'],true)||!is_bool($p['demo']))self::fail('Đường dẫn hoặc trạng thái sản phẩm không hợp lệ.');
        foreach(['supplierId','destinationId'] as $key)if(array_key_exists($key,$input))$p[$key]=self::id($input[$key],true);
        if(isset($input['onlineDiscount']))$p['onlineDiscount']=self::discount($input['onlineDiscount']);
        if(array_key_exists('displayOrder',$input))$p['displayOrder']=self::integer($input['displayOrder'],10000);
        if(array_key_exists('location',$input))$p['location']=self::location($input['location']);if(array_key_exists('tourInfo',$input))$p['tourInfo']=self::tourInfo($input['tourInfo']);if(array_key_exists('serviceDetails',$input))$p['serviceDetails']=self::serviceDetails($input['serviceDetails']);
        foreach(self::list($input['gallery'] ?? [],20) as $image){$ref=self::image($image);if(!$ref)self::fail('Ảnh bộ sưu tập không hợp lệ.');$p['gallery'][]=$ref;}
        $variants=[];$p['variants']=[];
        foreach(self::list($input['variants'] ?? null,30,1) as $v){
            $v=self::object($v);self::keys($v,['id','name','prices','timeSlots','schedulePeriods','conditions','onlineDiscount','saleWindow','displayOrder','tourInfo','serviceDetails']);$id=self::id($v['id'] ?? null);if(isset($variants[$id]))self::fail('Mã gói vé bị trùng.');
            foreach(['timeSlots','schedulePeriods','conditions','onlineDiscount'] as $key)if(array_key_exists($key,$v)&&$v[$key]===null)self::fail('Trường gói không được null.');
            $prices=self::object($v['prices'] ?? null);self::keys($prices,Pricing::GROUPS);if(!$prices)self::fail('Gói cần ít nhất một giá.');foreach($prices as &$price)$price=self::integer($price,100000000);unset($price);
            $variant=['id'=>$id,'name'=>self::text($v,'name',100,true),'prices'=>$prices,'timeSlots'=>self::slots($v['timeSlots'] ?? [])];
            if(array_key_exists('displayOrder',$v))$variant['displayOrder']=self::integer($v['displayOrder'],10000);
            if(array_key_exists('serviceDetails',$v))$variant['serviceDetails']=self::serviceDetails($v['serviceDetails']);if(array_key_exists('tourInfo',$v))$variant['tourInfo']=self::tourInfo($v['tourInfo']);$info=TourInfo::effective($p,$variant);if(isset($info['minGuests'],$info['maxGuests'])&&$info['minGuests']>$info['maxGuests'])self::fail('Số khách tối thiểu không được lớn hơn tối đa: '.$variant['name']);
            if(array_key_exists('saleWindow',$v)){$window=self::object($v['saleWindow']);self::keys($window,['startDate','endDate']);$variant['saleWindow']=[];foreach(['startDate','endDate'] as $key)if(array_key_exists($key,$window)){if(!is_string($window[$key]))self::fail('Ngày mở bán không hợp lệ.');Pricing::validateDate($window[$key]);$variant['saleWindow'][$key]=$window[$key];}if(isset($window['startDate'],$window['endDate'])&&$window['startDate']>$window['endDate'])self::fail('Ngày kết thúc mở bán phải sau ngày bắt đầu.');}
            // Omitted periods stay omitted for compatibility with existing PHP exports.
            if(array_key_exists('schedulePeriods',$v)){$variant['schedulePeriods']=[];$periodIds=[];foreach(self::list($v['schedulePeriods'],100) as $period){$period=self::object($period);self::keys($period,['id','startDate','endDate','weekdays','priority','reopenWeeklyClosures','timeSlots']);$pid=self::id($period['id'] ?? null);if(isset($periodIds[$pid]))self::fail('Mã giai đoạn bị trùng.');$periodIds[$pid]=true;foreach(['startDate','endDate'] as $key){if(!is_string($period[$key] ?? null))self::fail('Ngày giai đoạn không hợp lệ.');Pricing::validateDate($period[$key]);}if($period['startDate']>$period['endDate'])self::fail('Ngày kết thúc phải sau ngày bắt đầu.');$reopen=$period['reopenWeeklyClosures'] ?? false;if(!is_bool($reopen)||array_key_exists('reopenWeeklyClosures',$period)&&$period['reopenWeeklyClosures']===null)self::fail('Cấu hình mở lại ngày nghỉ không hợp lệ.');$item=['id'=>$pid,'startDate'=>$period['startDate'],'endDate'=>$period['endDate'],'priority'=>self::integer(array_key_exists('priority',$period)?$period['priority']:10,10000),'reopenWeeklyClosures'=>$reopen,'timeSlots'=>self::slots($period['timeSlots'] ?? null,1)];if(array_key_exists('weekdays',$period))$item['weekdays']=self::weekdays($period['weekdays'],true);$variant['schedulePeriods'][]=$item;}}
            if(isset($v['conditions']))$variant['conditions']=self::conditions($v['conditions']);if(isset($v['onlineDiscount']))$variant['onlineDiscount']=self::discount($v['onlineDiscount']);$variants[$id]=$variant;$p['variants'][]=$variant;
        }
        $p['rules']=[];$p['closures']=[];$ruleIds=[];
        foreach(['rules'=>1000,'closures'=>500] as $key=>$max)foreach(self::list($input[$key] ?? [],$max) as $r){
            $r=self::object($r);self::keys($r,$key==='rules'?['id','productId','variantId','customerType','startDate','endDate','weekdays','priority','amount']:['productId','variantId','startDate','endDate','weekdays','reason']);
            $item=['productId'=>self::id($r['productId'] ?? null)];if($item['productId']!==$p['id'])self::fail('Lịch phải thuộc sản phẩm đang sửa.');
            foreach(['startDate','endDate'] as $date){if(!is_string($r[$date] ?? null))self::fail('Ngày không hợp lệ.');Pricing::validateDate($r[$date]);$item[$date]=$r[$date];}if($item['startDate']>$item['endDate'])self::fail('Ngày kết thúc phải sau ngày bắt đầu.');
            if(array_key_exists('variantId',$r)){$item['variantId']=self::id($r['variantId']);if(!isset($variants[$item['variantId']]))self::fail('Lịch tham chiếu gói không tồn tại.');}
            if(isset($r['weekdays']))$item['weekdays']=self::weekdays($r['weekdays'],$key==='closures');
            if($key==='rules'){
                $item['id']=self::id($r['id'] ?? null);if(isset($ruleIds[$item['id']]))self::fail('Mã lịch giá bị trùng.');$ruleIds[$item['id']]=true;
                $type=$r['customerType'] ?? '';if(!in_array($type,Pricing::GROUPS,true)||!isset($item['variantId'])||!array_key_exists($type,$variants[$item['variantId']]['prices']))self::fail('Lịch giá tham chiếu nhóm khách chưa bật.');
                $item['customerType']=$type;$item['priority']=self::integer($r['priority'] ?? null,10000);$item['amount']=self::integer($r['amount'] ?? null,100000000);
                foreach($p['rules'] as $other)if($other['variantId']===$item['variantId']&&$other['customerType']===$type&&$other['priority']===$item['priority']&&$other['startDate']<=$item['endDate']&&$item['startDate']<=$other['endDate']&&array_intersect($other['weekdays'] ?? [1,2,3,4,5,6,7],$item['weekdays'] ?? [1,2,3,4,5,6,7]))self::fail('Có lịch giá trùng phạm vi và độ ưu tiên.');
            }elseif(isset($r['reason']))$item['reason']=self::text($r,'reason',200);$p[$key][]=$item;
        }
        if(array_key_exists('english',$input)){
            $en=self::object($input['english']);self::keys($en,['name','description','seoTitle','seoDescription','destination','detailHtml','variants','status','sourceHash']);$p['english']=[];
            foreach(['name'=>200,'description'=>10000,'seoTitle'=>200,'seoDescription'=>500,'destination'=>150] as $key=>$max){if($key!=='destination'&&!array_key_exists($key,$en))self::fail('Bản dịch thiếu thông tin.');$p['english'][$key]=self::text($en,$key,$max);}
            $p['english']['detailHtml']=self::html($en,'detailHtml');$p['english']['variants']=[];
            foreach(self::list($en['variants'] ?? [],30) as $v){$v=self::object($v);self::keys($v,['id','name','conditions']);$item=['id'=>self::id($v['id'] ?? null),'name'=>self::text($v,'name',100)];if(isset($v['conditions']))$item['conditions']=self::conditions($v['conditions']);$p['english']['variants'][]=$item;}
            $p['english']['status']=$en['status'] ?? 'DRAFT';if(!in_array($p['english']['status'],['DRAFT','PUBLISHED','NEEDS_REVIEW'],true))self::fail('Trạng thái bản dịch không hợp lệ.');
            if(array_key_exists('sourceHash',$en)){if(!is_string($en['sourceHash'])||!preg_match('/^[a-f0-9]{64}$/D',$en['sourceHash']))self::fail('Hash bản dịch không hợp lệ.');$p['english']['sourceHash']=$en['sourceHash'];}
        }
        return $p;
    }
}
