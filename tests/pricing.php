<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/src/AdminAuth.php';require dirname(__DIR__).'/src/Pricing.php';
function canonical(mixed $value): mixed {if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);foreach($value as &$item)$item=canonical($item);return $value;}
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/pricing-node.json'),true,512,JSON_THROW_ON_ERROR);
foreach($cases as $case){
    try{
        $method=$case['mode']==='online'?'online':'calculate';$actual=Pricing::$method($case['product'],$case['variantId'],$case['date'],$case['quantities'],$case['rules'],$case['closures'],$case['slotId'] ?? null,$case['now']);
        if(isset($case['error']))throw new RuntimeException($case['name'].': expected error '.$case['error']);
        if(canonical($actual)!==canonical($case['expected']))throw new RuntimeException($case['name'].': quote differs from Node fixture: '.json_encode($actual,JSON_UNESCAPED_UNICODE));
    }catch(HttpError $e){if(($case['error'] ?? null)!==$e->getMessage())throw new RuntimeException($case['name'].': unexpected error '.$e->getMessage());}
}
$p=$cases[0]['product'];$p['onlineDiscount']=['type'=>'PERCENT','value'=>15];$quote=Pricing::online($p,'eco','2099-06-01',['ADULT'=>1]);
try{Pricing::checkVoucher($quote,'SALE');throw new RuntimeException('Voucher stacked with online discount.');}catch(HttpError $e){if(!str_contains($e->getMessage(),'không cộng dồn'))throw $e;}
Pricing::checkVoucher($quote,'');$variants=Pricing::publicVariants($p);
if($variants[0]['originalPrices']['ADULT']!==600000 || $variants[0]['prices']['ADULT']!==510000)throw new RuntimeException('Public online prices');
// The result and cutoff must not depend on the execution host timezone.
foreach(['UTC','America/New_York','Asia/Ho_Chi_Minh'] as $zone){date_default_timezone_set($zone);$slot=Pricing::schedule(['timeSlots'=>[['id'=>'am','start'=>'09:00']]],'2099-06-01','am',4083962399999);if($slot['id']!=='am')throw new RuntimeException('Timezone-dependent slot');}
echo 'PASS: '.count($cases)." Node parity fixtures, online voucher guard, public prices, timezone independence.\n";
