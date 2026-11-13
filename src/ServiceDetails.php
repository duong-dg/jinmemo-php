<?php
declare(strict_types=1);

final class ServiceDetails
{
    public static function lines(?array $details,bool $en=false):array
    {
        $out=[];foreach(['included','excluded'] as $key){$text=$en?($details[$key.'En'] ?? ''):($details[$key] ?? '');if($en&&$text==='')$text=$details[$key] ?? '';$lines=[];foreach(preg_split('/\r?\n/',$text) as $line){$line=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$line);if($line!=='')$lines[]=$line;}$out[$key]=$lines;}return $out;
    }
}
