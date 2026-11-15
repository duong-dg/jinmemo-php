<?php
declare(strict_types=1);
final class SecretCipher
{
    public function __construct(private string $directory){}
    private function key(bool $create):string
    {
        if(!is_dir($this->directory)&&(!$create||!@mkdir($this->directory,0700,true)))throw new HttpError(503,'Không mở được nơi lưu khóa mã hóa.');$path=rtrim($this->directory,'/\\').'/.smtp-key';$lock=@fopen($path.'.lock','c+b');if(!$lock)throw new HttpError(503,'Không mở được khóa mã hóa.');@chmod($path.'.lock',0600);try{if(!flock($lock,LOCK_EX))throw new HttpError(503,'Không khóa được nơi lưu khóa mã hóa.');if(!is_file($path)){if(!$create)throw new HttpError(503,'Thiếu khóa mã hóa. Khôi phục bản sao lưu.');$f=@fopen($path,'xb');if(!$f)throw new HttpError(503,'Không tạo được khóa mã hóa.');@chmod($path,0600);try{if(fwrite($f,random_bytes(32))!==32||!fflush($f))throw new HttpError(503,'Không ghi được khóa mã hóa.');}finally{fclose($f);}}$key=@file_get_contents($path);if($key===false||strlen($key)!==32)throw new HttpError(503,'Khóa mã hóa không hợp lệ. Khôi phục bản sao lưu.');return $key;}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function encrypt(string $text):string{$key=$this->key(true);$iv=random_bytes(12);$cipher=openssl_encrypt($text,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'',16);if($cipher===false)throw new HttpError(503,'Không mã hóa được thông tin đơn.');return base64_encode($iv.$cipher.$tag);}
    public function decrypt(string $text):string{$raw=base64_decode($text,true);if($raw===false||strlen($raw)<28)throw new HttpError(503,'Dữ liệu mã hóa không hợp lệ.');$plain=openssl_decrypt(substr($raw,12,-16),'aes-256-gcm',$this->key(false),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,-16),'');if($plain===false)throw new HttpError(503,'Không giải mã được thông tin đơn. Kiểm tra khóa sao lưu.');return $plain;}
}
