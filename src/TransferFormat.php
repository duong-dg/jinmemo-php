<?php
declare(strict_types=1);
require_once __DIR__.'/TransferAudit.php';
require_once __DIR__.'/TransferV2Audit.php';

final class TransferFormat
{
    public static function detect(string $path):string
    {
        if(is_link($path)||!is_file($path)||!is_readable($path))throw new InvalidArgumentException('Provide a readable regular SQL file.');
        $f=fopen($path,'rb');if(!$f)throw new InvalidArgumentException('Cannot read SQL file.');try{$line=fgets($f,256);}finally{fclose($f);}
        return is_string($line)&&str_starts_with($line,'-- JinMemo MySQL full transfer v2;')?'full-v2':'v1';
    }
    public static function audit(string $path):TransferAudit|TransferV2Audit{return self::detect($path)==='full-v2'?new TransferV2Audit():new TransferAudit();}
    public static function profile(string $format):string{return dirname(__DIR__).'/docs/'.($format==='full-v2'?'TRANSFER-V2-SCHEMA.json':'TRANSFER-EXPORT-SCHEMA.json');}
    public static function relations(string $format):string{return dirname(__DIR__).'/docs/'.($format==='full-v2'?'TRANSFER-V2-RELATIONS.json':'TRANSFER-RELATIONS.json');}
}
