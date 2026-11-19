<?php
declare(strict_types=1);
final class ContactChannels
{
    public static function validate(array $data):array
    {
        $out=[];foreach(['facebookUrl','whatsapp','zaloUrl','messengerUrl'] as $key){$v=$data[$key]??'';if(!is_string($v))throw new HttpError(400,'Kiểm tra kênh liên hệ.');$v=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$v);if($v===null||str_contains($v,"\0")||strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2>($key==='whatsapp'?40:500))throw new HttpError(400,'Kiểm tra kênh liên hệ.');if($v!==''){
                if($key==='whatsapp'){if(!preg_match('/^\+?[1-9][0-9]{7,14}$/D',preg_replace('/[ ()-]/','',$v)))throw new HttpError(400,'Nhập số WhatsApp có mã quốc gia.');}
                else{$p=parse_url($v);$host=strtolower($p['host']??'');$hosts=match($key){'facebookUrl'=>['facebook.com','www.facebook.com','m.facebook.com'],'zaloUrl'=>['zalo.me','www.zalo.me'],default=>['m.me','messenger.com','www.messenger.com','facebook.com','www.facebook.com']};if(!$p||strtolower($p['scheme']??'')!=='https'||!in_array($host,$hosts,true)||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&$p['port']!==443||strlen($p['path']??'')<2||$key==='messengerUrl'&&in_array($host,['facebook.com','www.facebook.com'],true)&&!str_starts_with($p['path'],'/messages/t/'))throw new HttpError(400,'Nhập liên kết HTTPS đúng của kênh liên hệ.');}
            }$out[$key]=$v;
        }return $out;
    }
    public static function links(array $data):array
    {
        $out=[];foreach(['facebookUrl'=>'Facebook','whatsapp'=>'WhatsApp','zaloUrl'=>'Zalo','messengerUrl'=>'Messenger'] as $key=>$label){try{$v=self::validate([$key=>$data[$key]??''])[$key];if($v!=='')$out[]=['label'=>$label,'href'=>$key==='whatsapp'?'https://wa.me/'.preg_replace('/[^0-9]/','',$v):$v];}catch(HttpError){}}return $out;
    }
}
