<?php
declare(strict_types=1);
/** Small SMTP transport: certificate verification is always enabled; never falls back to plaintext. */
final class SmtpTransport
{
    public function __construct(private array $tlsOptions=[]){}
    private static function reply($socket,array $allowed):void
    {
        $bytes=0;$code=null;do{$line=@fgets($socket,2048);if($line===false||!preg_match('/^([0-9]{3})([ -])/', $line,$m)||($bytes+=strlen($line))>65536)throw new RuntimeException('SMTP protocol failure.');if($code!==null&&$code!==$m[1])throw new RuntimeException('SMTP protocol failure.');$code=$m[1];}while($m[2]==='-');if(!in_array((int)$code,$allowed,true))throw new RuntimeException('SMTP command rejected.');
    }
    private static function write($socket,string $value):void{for($i=0,$n=strlen($value);$i<$n;){$sent=@fwrite($socket,substr($value,$i));if($sent===false||$sent===0)throw new RuntimeException('SMTP write failure.');$i+=$sent;}}
    private static function command($socket,string $line,array $codes):void{self::write($socket,$line."\r\n");self::reply($socket,$codes);}
    private static function address(string $s):string{if(!filter_var($s,FILTER_VALIDATE_EMAIL)||!preg_match('/^[\x21-\x7e]+$/D',$s)||preg_match('/[<>\r\n]/',$s))throw new RuntimeException('Invalid mail address.');return $s;}
    public function send(array $c,string $to,string $subject,string $text,string $id):void
    {
        $to=self::address($to);$from=self::address($c['fromEmail']);if(!in_array($c['security'],['TLS','STARTTLS'],true)||preg_match('/[\r\n\x00]/',$subject.$c['fromName'])||strlen($text)>200000)throw new RuntimeException('Invalid mail content.');$ssl=array_replace($this->tlsOptions,['crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,'verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$c['host']]);$context=stream_context_create(['ssl'=>$ssl]);$host=str_contains($c['host'],':')?'['.$c['host'].']':$c['host'];$socket=@stream_socket_client(($c['security']==='TLS'?'tls':'tcp').'://'.$host.':'.$c['port'],$errno,$error,10,STREAM_CLIENT_CONNECT,$context);if(!$socket)throw new RuntimeException('SMTP connection failure.');
        try{stream_set_timeout($socket,15);self::reply($socket,[220]);self::command($socket,'EHLO localhost',[250]);if($c['security']==='STARTTLS'){self::command($socket,'STARTTLS',[220]);if(!@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT))throw new RuntimeException('SMTP TLS failure.');self::command($socket,'EHLO localhost',[250]);}
            self::command($socket,'AUTH LOGIN',[334]);self::command($socket,base64_encode($c['user']),[334]);self::command($socket,base64_encode($c['password']),[235]);self::command($socket,'MAIL FROM:<'.$from.'>',[250]);self::command($socket,'RCPT TO:<'.$to.'>',[250,251]);self::command($socket,'DATA',[354]);$encoded=static function(string $v):string{$words=[];do{$part=mb_strcut($v,0,42,'UTF-8');$words[]='=?UTF-8?B?'.base64_encode($part).'?=';$v=substr($v,strlen($part));}while($v!=='');return implode("\r\n ",$words);};
            $domain=substr(strrchr($from,'@'),1);$headers=['Date: '.gmdate('D, d M Y H:i:s').' +0000','Message-ID: <'.hash('sha256',$id).'@'.$domain.'>','From: '.$encoded($c['fromName']).' <'.$from.'>','To: <'.$to.'>','Subject: '.$encoded($subject),'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];$message=implode("\r\n",$headers)."\r\n\r\n".rtrim(chunk_split(base64_encode($text),76,"\r\n"))."\r\n";self::write($socket,$message.".\r\n");self::reply($socket,[250]);@fwrite($socket,"QUIT\r\n");
        }finally{fclose($socket);}
    }
}
