<?php
declare(strict_types=1);
final class SepaySettings
{
    public static function credential(array $c):?string{return match($c['sepayAuth']){'API_KEY'=>$c['sepayEncrypted']??null,'HMAC_SHA256'=>$c['sepayHmacEncrypted']??null,default=>null};}
    public static function ready(array $c):bool{return $c['sepayEnabled']&&!empty(self::credential($c))&&$c['bankCode']!==''&&$c['accountNumber']!==''&&$c['accountName']!=='';}
    public static function validate(array $in):array
    {
        if(array_key_exists('sepayEnabled',$in)&&!is_bool($in['sepayEnabled'])||array_key_exists('sepayAuth',$in)&&!in_array($in['sepayAuth'],['API_KEY','HMAC_SHA256'],true))throw new HttpError(400,'SePay hiện hỗ trợ API Key hoặc HMAC.');
        foreach(['bankCode'=>50,'accountNumber'=>40,'accountName'=>150,'sepayKey'=>256,'sepayHmacSecret'=>256] as $key=>$max)if(array_key_exists($key,$in)){
            if(!is_string($in[$key])||preg_match('/[\x00-\x1f\x7f]/',$in[$key]))throw new HttpError(400,'Cấu hình SePay không hợp lệ.');$v=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$in[$key]);if($v===null||strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2>$max)throw new HttpError(400,'Cấu hình SePay quá dài.');$in[$key]=$v;
        }
        foreach(['bankCode'=>'/^[A-Za-z0-9]*$/D','accountNumber'=>'/^[0-9]*$/D'] as $key=>$pattern)if(isset($in[$key])&&!preg_match($pattern,$in[$key]))throw new HttpError(400,'Kiểm tra ngân hàng và số tài khoản.');
        foreach(['sepayKey'=>24,'sepayHmacSecret'=>32] as $key=>$min)if(($in[$key]??'')!==''&&strlen(mb_convert_encoding($in[$key],'UTF-16LE','UTF-8'))/2<$min)throw new HttpError(400,'Khóa SePay cần ít nhất '.$min.' ký tự.');return $in;
    }
    public static function apply(array $data,array $old,array $in,bool $changing,SecretCipher $cipher):array
    {
        $data=array_replace($data,array_intersect_key($in,array_flip(['sepayEnabled','sepayAuth','bankCode','accountNumber','accountName'])));$field=$data['sepayAuth']==='HMAC_SHA256'?'sepayHmacSecret':'sepayKey';
        if($changing&&$data['sepayEnabled']&&($in[$field]??'')===''||$old['sepayAuth']!==$data['sepayAuth']&&($in[$field]??'')==='')throw new HttpError(400,'Đổi môi trường hoặc xác thực SePay cần nhập khóa mới.');
        foreach(['sepayKey'=>'sepayEncrypted','sepayHmacSecret'=>'sepayHmacEncrypted'] as $key=>$encrypted)if(($in[$key]??'')!==''){if($changing&&!empty($old[$encrypted])&&hash_equals($cipher->decrypt($old[$encrypted]),$in[$key]))throw new HttpError(400,'TEST và LIVE phải dùng khóa SePay khác nhau.');$data[$encrypted]=$cipher->encrypt($in[$key]);}
        if($data['sepayEnabled']&&!self::ready($data))throw new HttpError(400,'SePay cần ngân hàng, số tài khoản, chủ tài khoản và khóa webhook tương ứng.');return $data;
    }
}
