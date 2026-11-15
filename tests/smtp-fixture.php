<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$context=stream_context_create(['ssl'=>['local_cert'=>$argv[1],'local_pk'=>$argv[2],'verify_peer'=>false]]);
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
if(!$server)exit(2);echo stream_socket_get_name($server,false)."\n";flush();
$client=stream_socket_accept($server,15);if(!$client)exit(3);stream_set_timeout($client,10);$mode=$argv[4]??'TLS';
function line($s):string{$l=fgets($s,4096);if($l===false)throw new RuntimeException('EOF');return rtrim($l,"\r\n");}
function out($s,string $speak):void{fwrite($s,$speak."\r\n");}
try{
 if($mode==='TLS'&&!stream_socket_enable_crypto($client,true,STREAM_CRYPTO_METHOD_TLS_SERVER))throw new RuntimeException('TLS');out($client,'220 localhost fixture');$commands=[];$auth=[];$data='';
 while(true){$l=line($client);$commands[]=$l;if(str_starts_with($l,'EHLO '))out($client,"250-localhost\r\n250 STARTTLS");
 elseif($l==='STARTTLS'){out($client,'220 Ready');if(!stream_socket_enable_crypto($client,true,STREAM_CRYPTO_METHOD_TLS_SERVER))throw new RuntimeException('STARTTLS');}
 elseif($l==='AUTH LOGIN'){out($client,'334 VXNlcm5hbWU6');$auth[]=base64_decode(line($client));out($client,'334 UGFzc3dvcmQ6');$auth[]=base64_decode(line($client));out($client,'235 OK');}
 elseif(str_starts_with($l,'MAIL FROM:'))out($client,'250 OK');
 elseif(str_starts_with($l,'RCPT TO:'))out($client,'250 OK');
 elseif($l==='DATA'){out($client,'354 End with dot');while(($l=line($client))!=='.')$data.=$l."\r\n";out($client,'250 Accepted');break;}
 else throw new RuntimeException('Unexpected command');
 }
 file_put_contents($argv[3],json_encode(['commands'=>$commands,'auth'=>$auth,'message'=>$data],JSON_THROW_ON_ERROR));
}catch(Throwable){fclose($client);fclose($server);exit(4);}
fclose($client);fclose($server);
