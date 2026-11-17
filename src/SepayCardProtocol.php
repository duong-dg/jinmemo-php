<?php
declare(strict_types=1);

/** Wire format shared with the original SePay card integration. No card data is retained. */
final class SepayCardProtocol
{
    public const SIGNED_FIELDS = ['order_amount','merchant','currency','operation','order_description','order_invoice_number','customer_id','payment_method','success_url','error_url','cancel_url'];

    public static function canonical(array $fields):string
    {
        $parts=[];
        foreach(self::SIGNED_FIELDS as $key)if(array_key_exists($key,$fields)){
            if(!is_string($fields[$key]))throw new InvalidArgumentException('Invalid checkout field.');
            $parts[]=$key.'='.$fields[$key];
        }
        return implode(',',$parts);
    }

    public static function sign(array $fields,string $secret):string
    {
        return base64_encode(hash_hmac('sha256',self::canonical($fields),$secret,true));
    }

    public static function amount(string $value):int
    {
        if(!preg_match('/^[0-9]{1,11}(?:\.0{1,2})?$/D',$value))throw new InvalidArgumentException('Invalid VND amount.');
        $amount=(int)$value;
        if($amount<1||$amount>15000000000)throw new InvalidArgumentException('Invalid VND amount.');
        return $amount;
    }

    public static function form(string $mode,string $merchant,string $secret,string $invoice,string $code,int $amount,string $base):array
    {
        if(!in_array($mode,['TEST','LIVE'],true)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$merchant)||!preg_match('/^JMP[A-F0-9]{24}$/D',$invoice)||!preg_match('/^J[GMCBH][A-F0-9]{10}$/D',$code))throw new InvalidArgumentException('Invalid checkout context.');
        self::amount((string)$amount);
        $url=parse_url($base);
        if(!$url||!isset($url['scheme'],$url['host'])||!in_array($url['scheme'],['http','https'],true)||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||isset($url['path'])&&$url['path']!==''&&$url['path']!=='/'||$mode==='LIVE'&&$url['scheme']!=='https')throw new InvalidArgumentException('Invalid checkout origin.');
        $returned=rtrim($base,'/').'/api/v1/payments/sepay/card-return';
        $fields=['order_amount'=>(string)$amount,'merchant'=>$merchant,'currency'=>'VND','operation'=>'PURCHASE','order_description'=>'Thanh toan '.$code,'order_invoice_number'=>$invoice,'payment_method'=>'CARD','success_url'=>$returned,'error_url'=>$returned,'cancel_url'=>$returned];
        return ['action'=>$mode==='TEST'?'https://pay-sandbox.sepay.vn/v1/checkout/init':'https://pay.sepay.vn/v1/checkout/init','fields'=>$fields+['signature'=>self::sign($fields,$secret)]];
    }

    /** Authenticate before calling this parser. Returns only settlement identifiers and amounts. */
    public static function notification(mixed $body):array
    {
        if(is_array($body)&&is_float($body['timestamp']??null)&&is_finite($body['timestamp'])&&floor($body['timestamp'])===$body['timestamp']&&$body['timestamp']>0&&$body['timestamp']<=9007199254740991)$body['timestamp']=(int)$body['timestamp'];
        if(!is_array($body)||!is_int($body['timestamp']??null)||$body['timestamp']<1||$body['timestamp']>9007199254740991||!in_array($body['notification_type']??null,['ORDER_PAID','TRANSACTION_VOID'],true)||!is_array($body['order']??null)||!is_array($body['transaction']??null))throw new InvalidArgumentException('Invalid card IPN payload.');
        $order=$body['order'];$transaction=$body['transaction'];
        $identifier=static fn($v)=>is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$v);
        foreach([$order['id']??null,$transaction['id']??null] as $id)if(!$identifier($id))throw new InvalidArgumentException('Invalid card IPN payload.');
        foreach([[$order,'order_status',40],[$order,'order_currency',10],[$transaction,'payment_method',40],[$transaction,'transaction_type',40],[$transaction,'transaction_status',40],[$transaction,'transaction_currency',10]] as [$row,$key,$max])if(!is_string($row[$key]??null)||!mb_check_encoding($row[$key],'UTF-8')||strlen(mb_convert_encoding($row[$key],'UTF-16LE','UTF-8'))/2>$max)throw new InvalidArgumentException('Invalid card IPN payload.');
        if(!is_string($order['order_invoice_number']??null)||!preg_match('/^JMP[A-F0-9]{24}$/D',$order['order_invoice_number']))throw new InvalidArgumentException('Invalid card IPN payload.');
        $money=static function(mixed $value):int{
            if(is_float($value)&&is_finite($value)&&floor($value)===$value&&$value>0&&$value<=9007199254740991)$value=(int)$value;
            if(is_int($value)){if($value<1||$value>9007199254740991)throw new InvalidArgumentException('Invalid VND amount.');$value=(string)$value;}
            if(!is_string($value)||strlen($value)>40)throw new InvalidArgumentException('Invalid VND amount.');
            return self::amount($value);
        };
        $amount=$money($transaction['transaction_amount']??null);$orderAmount=$money($order['order_amount']??null);
        $reason='';
        if($body['notification_type']!=='ORDER_PAID'||$order['order_status']!=='CAPTURED'||$transaction['transaction_type']!=='PAYMENT'||$transaction['transaction_status']!=='APPROVED')$reason='Trạng thái thẻ chưa xác nhận thu tiền hoặc giao dịch bị hủy; cần đối soát';
        if($order['order_currency']!=='VND'||$transaction['transaction_currency']!=='VND'||$transaction['payment_method']!=='CARD'||$orderAmount!==$amount)$reason='Tiền tệ, phương thức hoặc số tiền thẻ không khớp';
        $normalized=['invoice'=>$order['order_invoice_number'],'order'=>$order['id'],'transaction'=>$transaction['id'],'amount'=>$amount,'orderAmount'=>$orderAmount,'currency'=>$order['order_currency'],'transactionCurrency'=>$transaction['transaction_currency'],'method'=>$transaction['payment_method'],'status'=>$order['order_status'],'transactionStatus'=>$transaction['transaction_status'],'type'=>$transaction['transaction_type'],'notification'=>$body['notification_type']];
        return ['invoice'=>$normalized['invoice'],'external'=>$transaction['id'].':'.$body['notification_type'],'amount'=>$amount,'fingerprint'=>hash('sha256',json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'reason'=>$reason];
    }
}
