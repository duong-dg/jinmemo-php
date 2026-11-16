<?php
declare(strict_types=1);
require_once __DIR__.'/PaymentSettings.php';
final class SepayOAuthError extends RuntimeException
{
    public function __construct(public readonly string $error,public readonly int $status=400){parent::__construct($error);}
}
final class SepayOAuth
{
    public function __construct(private PDO $db){}
    private static function b64(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    public static function issueToken(string $secret,string $clientId,string $mode,?int $now=null):string{$now??=time();$p=self::b64(json_encode(['aud'=>'sepay-webhook','sub'=>$clientId,'mode'=>$mode,'iat'=>$now,'exp'=>$now+3600],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));return $p.'.'.self::b64(hash_hmac('sha256','jinmemo-sepay-oauth:'.$p,$secret,true));}
    public static function verifyToken(string $token,string $secret,string $clientId,string $mode,?int $now=null):bool
    {
        if(strlen($token)>2048||!preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/D',$token,$parts))return false;$signature=self::b64(hash_hmac('sha256','jinmemo-sepay-oauth:'.$parts[1],$secret,true));if(!hash_equals($signature,$parts[2]))return false;$decoded=base64_decode(strtr($parts[1],'-_','+/'),true);if($decoded===false||self::b64($decoded)!==$parts[1])return false;try{$p=json_decode($decoded,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){return false;}if(!is_array($p))return false;$iat=$p['iat']??null;$exp=$p['exp']??null;$int=fn($v)=>is_int($v)||is_float($v)&&is_finite($v)&&floor($v)===$v;$now??=time();return ($p['aud']??null)==='sepay-webhook'&&($p['sub']??null)===$clientId&&($p['mode']??null)===$mode&&$int($iat)&&$int($exp)&&$iat<=$now&&$exp>$now&&(float)($exp-$iat)===3600.0;
    }
    public static function parse(string $raw,string $contentType):array
    {
        if(strlen($raw)>8192)throw new SepayOAuthError('invalid_request',413);$type=strtolower(trim(explode(';',$contentType)[0]));if($type==='application/json'){try{$object=json_decode($raw,false,16,JSON_THROW_ON_ERROR);$body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new SepayOAuthError('invalid_request');}if(!$object instanceof stdClass)throw new SepayOAuthError('invalid_request');return $body;}
        if($type!=='application/x-www-form-urlencoded')throw new SepayOAuthError('invalid_request',415);$body=[];foreach(explode('&',$raw) as $part){if($part==='')continue;if(preg_match('/%(?![a-f0-9]{2})/i',$part))throw new SepayOAuthError('invalid_request');$pair=explode('=',$part,2);$key=urldecode($pair[0]);$value=urldecode($pair[1]??'');if(!preg_match('/^[A-Za-z_]+$/D',$key)||array_key_exists($key,$body)||!mb_check_encoding($value,'UTF-8'))throw new SepayOAuthError('invalid_request');$body[$key]=$value;}return $body;
    }
    public function token(array $body,string $authorization):array
    {
        $c=(new PaymentSettings($this->db))->config();if(!$c['sepayEnabled']||$c['sepayAuth']!=='OAUTH2'||empty($c['sepayOauthEncrypted'])||$c['sepayOauthClientId']==='')throw new SepayOAuthError('invalid_client',401);$custom=array_key_exists('clientId',$body)||array_key_exists('clientSecret',$body);$allowed=$custom?['clientId','clientSecret']:['grant_type','client_id','client_secret'];if(array_diff(array_keys($body),$allowed))throw new SepayOAuthError('invalid_request');
        foreach($body as $key=>$value)if(!is_string($value)||strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>(in_array($key,['client_secret','clientSecret'],true)?256:100))throw new SepayOAuthError('invalid_request');if($custom&&(($body['clientId']??'')===''||($body['clientSecret']??'')==='')||!$custom&&!array_key_exists('grant_type',$body))throw new SepayOAuthError('invalid_request');if(!$custom&&$body['grant_type']!=='client_credentials')throw new SepayOAuthError('unsupported_grant_type');$id=$body[$custom?'clientId':'client_id']??'';$secret=$body[$custom?'clientSecret':'client_secret']??'';
        if($authorization!==''){if($id!==''||$secret!==''||strlen($authorization)>2048||!preg_match('/^Basic ([A-Za-z0-9+\/]+=*)$/D',$authorization,$m))throw new SepayOAuthError('invalid_client',401);$pair=base64_decode($m[1],true);if($pair===false||!str_contains($pair,':')||!mb_check_encoding($pair,'UTF-8')||preg_match('/%(?![a-f0-9]{2})/i',$pair))throw new SepayOAuthError('invalid_client',401);[$id,$secret]=explode(':',$pair,2);$id=urldecode($id);$secret=urldecode($secret);if(!mb_check_encoding($id.$secret,'UTF-8'))throw new SepayOAuthError('invalid_client',401);}
        $expected=(new SecretCipher(OrderMail::directory()))->decrypt($c['sepayOauthEncrypted']);$idMatch=hash_equals($c['sepayOauthClientId'],$id);$secretMatch=hash_equals($expected,$secret);if(!$idMatch||!$secretMatch)throw new SepayOAuthError('invalid_client',401);$token=self::issueToken($expected,$c['sepayOauthClientId'],$c['mode']);return $custom?['data'=>['accessToken'=>$token,'expiredIn'=>3600]]:['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>3600];
    }
}
