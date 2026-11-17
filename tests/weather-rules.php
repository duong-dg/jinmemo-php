<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/WeatherRules.php';
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/weather-rules-node.json'),true,512,JSON_THROW_ON_ERROR);
foreach($fixture['cases'] as $i=>$case)foreach(['vi','en'] as $lang){$expected=array_map(fn($tip)=>['id'=>$tip['id'],'text'=>$tip[$lang]],$case['expected']);if(WeatherRules::advice($case['day'],$lang)!==$expected)throw new RuntimeException('Source advice mismatch '.$i.' '.$lang);}
if(WeatherRules::today((int)(strtotime('2026-11-17T18:00:00Z')*1000))!=='2026-11-18')throw new RuntimeException('Vietnam date boundary');
echo "PASS: source Node weather advice parity for 80 cases in VI/EN and Vietnam day boundary.\n";
