<?php
declare(strict_types=1);
final class LoginGeo
{
    public function __construct(private PDO $db,private ?Closure $fetch=null){}
    public static function publicIp(string $ip):bool
    {
        $ip=preg_replace('/^::ffff:/i','',$ip);if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){[$a,$b,$c]=array_map('intval',explode('.',$ip));return !($a===0||$a===10||$a===127||$a>=224||$a===169&&$b===254||$a===172&&$b>=16&&$b<=31||$a===192&&($b===168||$b===0)||$a===100&&$b>=64&&$b<=127||$a===198&&($b===18||$b===19||$b===51&&$c===100)||$a===203&&$b===0&&$c===113);}return (bool)(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)&&preg_match('/^[23]/i',$ip)&&!preg_match('/^2001:db8/i',$ip));
    }
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function lookup(string $ip,bool $enabled,bool $fresh=false):?array
    {
        if(!$enabled||!self::publicIp($ip))return null;$ip=preg_replace('/^::ffff:/i','',$ip);$id=hash('sha256',$ip);$cached=$this->query('SELECT data FROM jingo_admin_geo_cache WHERE id=? AND expires_at>UTC_TIMESTAMP()',[$id])->fetchColumn();if($cached!==false)return json_decode($cached,true,512,JSON_THROW_ON_ERROR);if(!$fresh)return null;
        try{$day=gmdate('Y-m-d');$this->query('INSERT IGNORE INTO jingo_admin_geo_usage(id) VALUES (?)',[$day]);if(!$this->query('UPDATE jingo_admin_geo_usage SET calls=calls+1 WHERE id=? AND calls<100',[$day])->rowCount())return null;if($this->fetch)$raw=($this->fetch)($ip);else{if(!function_exists('curl_init'))return null;$c=curl_init('https://api.db-ip.com/v2/free/'.rawurlencode($ip));$body='';curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_TIMEOUT_MS=>2000,CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>16000)return 0;$body.=$chunk;return strlen($chunk);}]);try{if(curl_exec($c)===false||curl_getinfo($c,CURLINFO_RESPONSE_CODE)!==200)return null;$raw=json_decode($body,true,32,JSON_THROW_ON_ERROR);}finally{curl_close($c);}}if(!is_array($raw)||($raw['ipAddress']??null)!==$ip)return null;foreach(['countryCode'=>3,'countryName'=>100,'stateProv'=>150,'city'=>150] as $field=>$max)if(array_key_exists($field,$raw)&&(!is_string($raw[$field])||!mb_check_encoding($raw[$field],'UTF-8')||strlen(mb_convert_encoding($raw[$field],'UTF-16LE','UTF-8'))/2>$max))return null;$value=['country'=>($raw['countryName']??'')?:($raw['countryCode']??''),'region'=>$raw['stateProv']??'','city'=>$raw['city']??'','source'=>'DB-IP'];$this->query('INSERT INTO jingo_admin_geo_cache(id,data,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY)) ON DUPLICATE KEY UPDATE data=VALUES(data),expires_at=VALUES(expires_at)',[$id,json_encode($value,JSON_THROW_ON_ERROR)]);return $value;}catch(Throwable){return null;}
    }
}
