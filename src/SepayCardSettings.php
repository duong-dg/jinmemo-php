<?php
declare(strict_types=1);
final class SepayCardSettings
{
    public static function ready(array $c):bool{return $c['sepayCardEnabled']&&$c['sepayMerchantId']!==''&&!empty($c['sepayCardEncrypted'])&&!empty($c['sepayCardIpnEncrypted']);}
    public static function validate(array $in):array
    {
        if(array_key_exists('sepayCardEnabled',$in)&&!is_bool($in['sepayCardEnabled']))throw new HttpError(400,'Kiểm tra cấu hình thẻ SePay.');
        foreach(['sepayMerchantId'=>100,'sepayCardSecret'=>256,'sepayCardIpnKey'=>256] as $key=>$max)if(array_key_exists($key,$in)){
            if(!is_string($in[$key])||preg_match('/[\x00-\x1f\x7f]/',$in[$key]))throw new HttpError(400,'Kiểm tra cấu hình thẻ SePay.');
            $value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$in[$key]);
            if($value===null||strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max)throw new HttpError(400,'Cấu hình thẻ SePay quá dài.');$in[$key]=$value;
        }
        if(isset($in['sepayMerchantId'])&&!preg_match('/^[A-Za-z0-9_-]*$/D',$in['sepayMerchantId']))throw new HttpError(400,'Merchant ID thẻ SePay không hợp lệ.');
        foreach(['sepayCardSecret'=>16,'sepayCardIpnKey'=>24] as $key=>$min)if(($in[$key]??'')!==''&&strlen(mb_convert_encoding($in[$key],'UTF-16LE','UTF-8'))/2<$min)throw new HttpError(400,'Khóa thẻ SePay cần ít nhất '.$min.' ký tự.');
        return $in;
    }
    public static function apply(array $data,array $old,array $in,bool $changing,SecretCipher $cipher):array
    {
        $data=array_replace($data,array_intersect_key($in,array_flip(['sepayCardEnabled','sepayMerchantId'])));
        foreach(['sepayCardSecret'=>'sepayCardEncrypted','sepayCardIpnKey'=>'sepayCardIpnEncrypted'] as $key=>$encrypted){$value=$in[$key]??'';if($changing&&$data['sepayCardEnabled']&&$value==='')throw new HttpError(400,'Đổi môi trường cần nhập lại cả hai khóa thẻ SePay.');if($value!==''){if($changing&&!empty($old[$encrypted])&&hash_equals($cipher->decrypt($old[$encrypted]),$value))throw new HttpError(400,'TEST và LIVE phải dùng khóa thẻ SePay khác nhau.');$data[$encrypted]=$cipher->encrypt($value);}}
        if($data['sepayCardEnabled']&&!self::ready($data))throw new HttpError(400,'Thẻ SePay cần Merchant ID, Secret Key và khóa IPN (X-Secret-Key).');
        return $data;
    }
}
