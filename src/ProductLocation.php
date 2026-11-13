<?php
declare(strict_types=1);

final class ProductLocation
{
    public static function validMapUrl(string $url):bool
    {
        if($url==='')return true;if(!preg_match('#^https://#i',$url)||preg_match('/[\x00-\x1f\x7f\\\\]/u',$url))return false;$parts=parse_url($url);if(!$parts||strtolower($parts['scheme'] ?? '')!=='https'||isset($parts['port'])&&$parts['port']!==443)return false;foreach(['user','pass'] as $key)if(isset($parts[$key])&&$parts[$key]!=='')return false;$host=strtolower(rawurldecode($parts['host'] ?? ''));
        // Match browser dot-segment normalization before checking /maps paths.
        $segments=[];foreach(explode('/',$parts['path'] ?? '/') as $index=>$part){if($index===0&&$part==='')continue;$dot=preg_replace('/%2e/i','.',$part);if($dot==='..'){array_pop($segments);continue;}if($dot==='.')continue;$segments[]=$part;}$path='/'.implode('/',$segments);
        if(in_array($host,['maps.app.goo.gl','maps.google.com','maps.google.com.vn'],true))return true;if($host==='goo.gl')return str_starts_with($path,'/maps');return in_array($host,['google.com','www.google.com','google.com.vn','www.google.com.vn'],true)&&(bool)preg_match('#^/maps(?:/|$)#D',$path);
    }
}
