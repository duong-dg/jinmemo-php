<?php
declare(strict_types=1);
require_once __DIR__.'/TransportPlan.php';
final class RideLinks
{
    public function __construct(private PDO $db){}
    private static function plain(string $s):string
    {
        if(class_exists('Normalizer'))$s=Normalizer::normalize($s,Normalizer::FORM_D);else{static $map=null;$map??=json_decode(file_get_contents(__DIR__.'/ride-name-decomposition.json'),true,512,JSON_THROW_ON_ERROR);$s=strtr($s,$map);$s=preg_replace_callback('/[\x{AC00}-\x{D7A3}]/u',static function($m){$n=mb_ord($m[0],'UTF-8')-0xAC00;$tail=$n%28;return mb_chr(0x1100+intdiv($n,588),'UTF-8').mb_chr(0x1161+intdiv($n%588,28),'UTF-8').($tail?mb_chr(0x11A7+$tail,'UTF-8'):'');},$s);}return strtr(preg_replace('/[\x{0300}-\x{036f}]/u','',$s),['đ'=>'d','Đ'=>'D']);
    }
    public static function build(array $plan,bool $en=false,bool $admin=false):array
    {
        if(!$admin&&!$plan['enabled'])return [];$vehicles=array_column($plan['vehicles'],null,'id');$areas=array_column($plan['areas'],null,'id');$name=function($id)use($areas,$en){if(!$en)return $areas[$id]['name'];$english=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$areas[$id]['englishName']??'');return $english!==''?$english:self::plain($areas[$id]['name']);};$out=[];foreach($plan['fares'] as $f){$v=$vehicles[$f['vehicleId']]??null;if(!$v||$f['privatePrice']<=0)continue;$quote=null;foreach(TransportPlan::quotes($plan,$f['from'],$f['to'],1) as $q)if($q['vehicleId']===$v['id']&&!isset($q['error'])){$quote=$q;break;}if(!$quote||!$quote['total']||!$quote['base'])continue;$base=['from'=>$f['from'],'to'=>$f['to'],'vehicleId'=>$v['id'],'fromName'=>$name($f['from']),'toName'=>$name($f['to']),'vehicleName'=>$en?(($v['englishName']??'')!==''?$v['englishName']:self::plain($v['name'])):$v['name'],'seats'=>$v['seats'],'image'=>$v['image']??null,'durationMinutes'=>$quote['durationMinutes']??null,'roundTrip'=>$f['roundTripOnly']??false,'twoWay'=>$f['twoWay']??false];$out[]=$base+['id'=>$f['id'].'/PRIVATE','mode'=>'PRIVATE','shareSize'=>null,'price'=>$quote['total']];if(empty($f['roundTripOnly'])&&!empty($f['sharedEnabled']))foreach($v['sharedSizes'] as $size)$out[]=$base+['id'=>$f['id'].'/SHARED/'.$size,'mode'=>'SHARED','shareSize'=>$size,'price'=>intdiv($quote['total'],$size)];}return $out;
    }
    public function list(string $lang='vi',bool $admin=false):array{if(!in_array($lang,['vi','en'],true))throw new HttpError(400,'Ngôn ngữ không hợp lệ.');return ['data'=>self::build((new TransportPlan($this->db))->read()['plan'],$lang==='en',$admin)];}
    public static function href(array $link,string $lang='vi'):string{return ($lang==='en'?'/en/rides':'/dat-xe').'?'.http_build_query(['from'=>$link['from'],'to'=>$link['to'],'mode'=>$link['mode'],'vehicle'=>$link['vehicleId'],...($link['shareSize']?['share'=>$link['shareSize']]:[])],'','&',PHP_QUERY_RFC3986);}
}
