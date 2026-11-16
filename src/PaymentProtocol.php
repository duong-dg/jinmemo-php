<?php
declare(strict_types=1);
final class PaymentProtocol
{
    private static function encode(string $s):string{return str_replace(['%21','%27','%28','%29','%2A'],['!',"'",'(',')','*'],rawurlencode($s));}
    public static function canonical(array $params):string{unset($params['vnp_SecureHash'],$params['vnp_SecureHashType']);ksort($params,SORT_STRING);$out=[];foreach($params as $k=>$v)if($v!=='')$out[]=self::encode((string)$k).'='.str_replace('%20','+',self::encode($v));return implode('&',$out);}
    public static function sign(array $params,string $secret):string{return hash_hmac('sha512',self::canonical($params),$secret);}
    public static function verify(array $params,string $secret):bool{$hash=$params['vnp_SecureHash']??'';return is_string($hash)&&preg_match('/^[a-f0-9]{128}$/iD',$hash)===1&&hash_equals(self::sign($params,$secret),strtolower($hash));}
    public static function vnTime(string $utc):string{$d=new DateTimeImmutable($utc,new DateTimeZone('UTC'));return $d->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('YmdHis');}
    public static function query(string $raw):?array
    {
        if(strlen($raw)>80000)return null;$out=[];foreach(explode('&',$raw) as $part){if($part==='')continue;$pair=explode('=',$part,2);if(preg_match('/%(?![0-9a-f]{2})/i',$part))return null;$key=urldecode($pair[0]);$value=urldecode($pair[1]??'');if(!preg_match('/^vnp_[a-zA-Z0-9_]{1,70}$/D',$key)||array_key_exists($key,$out)||strlen($value)>2000||!preg_match('//u',$value))return null;$out[$key]=$value;}return count($out)<=40?$out:null;
    }
}
