<?php
declare(strict_types=1);

final class Schedule
{
    public static function period(array $variant,string $date):?array
    {
        $weekday=(int)(new DateTimeImmutable($date.'T00:00:00Z'))->format('N');$matches=[];
        foreach($variant['schedulePeriods'] ?? [] as $index=>$period)if($period['startDate']<=$date&&$date<=$period['endDate']&&(!isset($period['weekdays'])||in_array($weekday,$period['weekdays'],true))){$period['_index']=$index;$period['_span']=(new DateTimeImmutable($period['endDate'].'T00:00:00Z'))->getTimestamp()-(new DateTimeImmutable($period['startDate'].'T00:00:00Z'))->getTimestamp();$matches[]=$period;}
        usort($matches,fn($a,$b)=>($b['priority'] ?? 10)<=>($a['priority'] ?? 10) ?: $a['_span']<=>$b['_span'] ?: $b['_index']<=>$a['_index']);$result=$matches[0] ?? null;if($result)unset($result['_index'],$result['_span']);return $result;
    }
    public static function slots(array $variant,string $date):array{return self::period($variant,$date)['timeSlots'] ?? $variant['timeSlots'] ?? [];}
    public static function allSlots(array $variant):array{$all=[];foreach(array_merge($variant['timeSlots'] ?? [],...array_map(fn($p)=>$p['timeSlots'],$variant['schedulePeriods'] ?? [])) as $slot)$all[$slot['id']]=$slot;return array_values($all);}
    public static function closureApplies(array $variant,string $date,array $closure):bool
    {
        $weekday=(int)(new DateTimeImmutable($date.'T00:00:00Z'))->format('N');return $closure['startDate']<=$date&&$date<=$closure['endDate']&&(!isset($closure['weekdays'])||in_array($weekday,$closure['weekdays'],true))&&!($closure['startDate']!==$closure['endDate']&&!empty($closure['weekdays'])&&(self::period($variant,$date)['reopenWeeklyClosures'] ?? false));
    }
}
