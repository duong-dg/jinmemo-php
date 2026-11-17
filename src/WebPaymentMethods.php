<?php
declare(strict_types=1);
require_once __DIR__.'/PaymentSettings.php';
require_once __DIR__.'/ManualMethods.php';
final class WebPaymentMethods
{
    public const ONLINE=['VNPAY','VNPAY_QR','VNPAY_CARD','SEPAY','SEPAY_CARD','PAYPAL'];
    public static function providers(array $c):array
    {
        if($c['mode']!=='LIVE')return [];
        return array_merge($c['vnpayEnabled']?array_merge(['VNPAY','VNPAY_QR'],$c['vnpayCardEnabled']?['VNPAY_CARD']:[]):[],SepaySettings::ready($c)?['SEPAY']:[],SepayCardSettings::ready($c)?['SEPAY_CARD']:[],PaypalSettings::ready($c)?['PAYPAL']:[]);
    }
    public static function select(PDO $db,string $method):?array
    {
        if(!in_array($method,self::ONLINE,true))return (new ManualMethods($db))->select($method);
        if(!in_array($method,self::providers((new PaymentSettings($db))->config()),true))throw new HttpError(409,'Cổng thanh toán đã tắt hoặc chưa sẵn sàng. Vui lòng chọn lại.');
        return null;
    }
}
