<?php
declare(strict_types=1);

final class WeatherRules
{
    private static function number(mixed $v,float $min,float $max,bool $coerce=false):int|float
    {
        if($coerce&&is_string($v)&&is_numeric(trim($v)))$v=0+$v;
        if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<$min||$v>$max)throw new RuntimeException('Invalid forecast');return $v;
    }
    private static function condition(mixed $v):int
    {
        if(!is_array($v)||!is_int($v['code']??null)||!is_string($v['text']??null)||!mb_check_encoding($v['text'],'UTF-8')||strlen(mb_convert_encoding($v['text'],'UTF-16LE','UTF-8'))/2>200)throw new RuntimeException('Invalid condition');return $v['code'];
    }
    public static function normalize(array $raw,int $now):array
    {
        $current=$raw['current']??[];$temperature=self::number($current['temp_c']??null,-90,65);$code=self::condition($current['condition']??null);$epoch=$current['last_updated_epoch']??null;
        if(!is_int($epoch)||$epoch<=0||$now-$epoch*1000>14400000||$epoch*1000>$now+3600000||array_key_exists('is_day',$current)&&(!is_int($current['is_day'])||!in_array($current['is_day'],[0,1],true)))throw new RuntimeException('Outdated forecast');
        $forecasts=$raw['forecast']['forecastday']??null;if(!is_array($forecasts)||!array_is_list($forecasts)||count($forecasts)<1||count($forecasts)>14)throw new RuntimeException('Invalid days');$today=self::today($now);$days=[];
        foreach($forecasts as $f){if(!is_array($f)||!is_string($f['date']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$f['date']))throw new RuntimeException('Invalid date');$d=$f['day']??[];$day=['date'=>$f['date'],'min'=>self::number($d['mintemp_c']??null,-90,65),'max'=>self::number($d['maxtemp_c']??null,-90,65),'rain'=>self::number($d['daily_chance_of_rain']??null,0,100,true),'precip'=>self::number($d['totalprecip_mm']??null,0,2000),'wind'=>self::number($d['maxwind_kph']??null,0,500),'humidity'=>self::number($d['avghumidity']??null,0,100),'visibility'=>self::number($d['avgvis_km']??null,0,1000),'uv'=>self::number($d['uv']??null,0,30),'code'=>self::condition($d['condition']??null)];$hours=array_key_exists('hour',$f)?$f['hour']:[];if(!is_array($hours)||!array_is_list($hours)||count($hours)>24)throw new RuntimeException('Invalid hours');$evening=[];
            foreach($hours as $h){if(!is_array($h)||!is_string($h['time']??null))throw new RuntimeException('Invalid hour');$temp=self::number($h['temp_c']??null,-90,65);$rain=self::number($h['chance_of_rain']??null,0,100,true);$hour=substr($h['time'],11,2);if(is_numeric($hour)&&(int)$hour>=18&&(int)$hour<=22)$evening[]=['temp'=>$temp,'rain'=>$rain];}
            if($evening){$day['eveningMin']=min(array_column($evening,'temp'));$day['eveningRain']=max(array_column($evening,'rain'));}if($f['date']>=$today&&count($days)<3)$days[]=$day;
        }
        if(!$days)throw new RuntimeException('Outdated days');return ['updatedAt'=>gmdate('Y-m-d\TH:i:s',$epoch).'.000Z','current'=>['temperature'=>$temperature,'isDay'=>($current['is_day']??1)!==0,'code'=>$code],'days'=>$days];
    }
    public static function today(int $now):string{return (new DateTimeImmutable('@'.intdiv($now,1000)))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');}
    public static function advice(array $d,string $lang):array
    {
        $ids=[];$thunder=in_array($d['code'],[1087,1273,1276,1279,1282],true);if($thunder)$ids[]='thunder';if($d['wind']>=40)$ids[]='wind-strong';elseif($d['wind']>=25)$ids[]='wind';if($d['precip']>=30)$ids[]='heavy-rain';elseif($d['rain']>=60)$ids[]='rain';elseif($d['rain']>=30)$ids[]='showers';if($d['max']>=35)$ids[]='hot';elseif($d['max']>=30&&$d['humidity']>=80)$ids[]='muggy';elseif($d['max']>=30)$ids[]='warm';if($d['min']<=15)$ids[]='cold';elseif($d['min']<=20)$ids[]='cool';if($d['uv']>=8)$ids[]='uv-very-high';elseif($d['uv']>=6)$ids[]='uv-high';if($d['visibility']<2)$ids[]='visibility';if(($d['eveningRain']??0)>=60&&$d['rain']<60)$ids[]='evening-rain';if(isset($d['eveningMin'])&&$d['max']-$d['eveningMin']>=10&&$d['min']>20)$ids[]='evening-cool';if($d['humidity']>=90&&$d['rain']>=30)$ids[]='damp';if($d['rain']<30&&$d['precip']<5&&$d['min']>=20&&$d['max']<30&&$d['wind']<25&&$d['uv']<6&&($d['eveningRain']??0)<30&&!$thunder&&$d['visibility']>=2)$ids[]='pleasant';if(!$ids)$ids[]='prepare';$text=json_decode(file_get_contents(__DIR__.'/weather-advice.json'),true,512,JSON_THROW_ON_ERROR);return array_map(fn($id)=>['id'=>$id,'text'=>$text[$id][$lang==='en'?'en':'vi']],array_slice($ids,0,3));
    }
}
