<?php
declare(strict_types=1);
final class PaypalSettings
{
    public static function ready(array $c):bool{return $c['paypalEnabled']&&$c['paypalClientId']!==''&&$c['paypalWebhookId']!==''&&!empty($c['paypalEncrypted']);}
    public static function validate(array $in):array
    {
        if(array_key_exists('paypalEnabled',$in)&&!is_bool($in['paypalEnabled'])||array_key_exists('paypalRate',$in)&&(!is_int($in['paypalRate'])||$in['paypalRate']<100||$in['paypalRate']>1000000))throw new HttpError(400,'Kiểm tra cấu hình/tỷ giá PayPal.');
        foreach(['paypalClientId'=>200,'paypalWebhookId'=>100,'paypalSecret'=>256] as $key=>$max)if(array_key_exists($key,$in)){
            if(!is_string($in[$key])||preg_match('/[\x00-\x1f\x7f]/',$in[$key]))throw new HttpError(400,'Cấu hình PayPal không hợp lệ.');
            $value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$in[$key]);if($value===null||strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max)throw new HttpError(400,'Cấu hình PayPal quá dài.');$in[$key]=$value;
        }
        foreach(['paypalClientId'=>'/^[A-Za-z0-9_-]*$/D','paypalWebhookId'=>'/^[A-Za-z0-9-]*$/D'] as $key=>$pattern)if(isset($in[$key])&&!preg_match($pattern,$in[$key]))throw new HttpError(400,'Mã tài khoản/webhook PayPal không hợp lệ.');
        if(($in['paypalSecret']??'')!==''&&strlen(mb_convert_encoding($in['paypalSecret'],'UTF-16LE','UTF-8'))/2<16)throw new HttpError(400,'Client Secret PayPal tối thiểu 16 ký tự.');return $in;
    }
    public static function apply(array $data,array $old,array $in,bool $changing,SecretCipher $cipher):array
    {
        $data=array_replace($data,array_intersect_key($in,array_flip(['paypalEnabled','paypalClientId','paypalWebhookId','paypalRate'])));$secret=$in['paypalSecret']??'';
        if($changing&&$data['paypalEnabled']&&$secret===''||$data['paypalClientId']!==$old['paypalClientId']&&$data['paypalClientId']!==''&&$secret==='')throw new HttpError(400,'Đổi môi trường hoặc Client ID PayPal cần nhập Client Secret mới.');
        if($secret!==''){if($changing&&!empty($old['paypalEncrypted'])&&hash_equals($cipher->decrypt($old['paypalEncrypted']),$secret))throw new HttpError(400,'TEST và LIVE phải dùng khóa PayPal khác nhau.');$data['paypalEncrypted']=$cipher->encrypt($secret);}
        if($data['paypalEnabled']&&!self::ready($data))throw new HttpError(400,'PayPal cần Client ID, Client Secret và Webhook ID.');return $data;
    }
}
