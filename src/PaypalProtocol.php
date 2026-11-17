<?php
declare(strict_types=1);

final class PaypalProtocol
{
    public static function cents(mixed $vnd,mixed $rate):int
    {
        if(!is_int($vnd)||$vnd<1||$vnd>9007199254740991||!is_int($rate)||$rate<100||$rate>1000000)throw new InvalidArgumentException('Số tiền hoặc tỷ giá PayPal không hợp lệ.');
        // All operands fit signed 64-bit integers, including the source JS safe-integer ceiling.
        $cents=intdiv($vnd*200+$rate,2*$rate);
        if($cents<1||$cents>99999999999)throw new InvalidArgumentException('Số tiền USD nằm ngoài giới hạn thanh toán.');
        return $cents;
    }

    public static function usd(mixed $cents):string
    {
        if(!is_int($cents)||$cents<1||$cents>99999999999)throw new InvalidArgumentException('Số tiền USD không hợp lệ.');
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    public static function remoteId(mixed $id):bool{return is_string($id)&&preg_match('/^[A-Z0-9]{10,40}$/D',$id)===1;}

    public static function host(string $mode):string
    {
        return match($mode){'LIVE'=>'https://api-m.paypal.com','TEST'=>'https://api-m.sandbox.paypal.com',default=>throw new InvalidArgumentException('Môi trường PayPal không hợp lệ.')};
    }

    public static function approval(mixed $url,string $mode):string
    {
        self::host($mode);
        if(!is_string($url)||strlen($url)>10000||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new InvalidArgumentException('Đường dẫn PayPal không hợp lệ.');
        $parts=parse_url($url);
        if(!$parts||($parts['scheme']??'')!=='https'||strtolower($parts['host']??'')!==($mode==='LIVE'?'www.paypal.com':'www.sandbox.paypal.com')||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])&&$parts['port']!==443)throw new InvalidArgumentException('Đường dẫn PayPal không hợp lệ.');
        return $url;
    }

    public static function createBody(string $attempt,string $code,int $cents,string $origin):array
    {
        if(!preg_match('/^JMP[A-F0-9]{24}$/D',$attempt)||!preg_match('/^J[GMCBH][A-F0-9]{10}$/D',$code))throw new InvalidArgumentException('Mã thanh toán PayPal không hợp lệ.');
        $parts=parse_url($origin);
        if(!$parts||!isset($parts['scheme'],$parts['host'])||!in_array($parts['scheme'],['http','https'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||isset($parts['path'])&&!in_array($parts['path'],['','/'],true))throw new InvalidArgumentException('PUBLIC_BASE_URL không hợp lệ.');
        $return=rtrim($origin,'/').'/thanh-toan/paypal';
        return ['intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>$attempt,'custom_id'=>$attempt,'invoice_id'=>$attempt,'description'=>'JinMemo '.$code,'amount'=>['currency_code'=>'USD','value'=>self::usd($cents)]]],'payment_source'=>['paypal'=>['experience_context'=>['brand_name'=>'JinMemo','shipping_preference'=>'NO_SHIPPING','user_action'=>'PAY_NOW','return_url'=>$return,'cancel_url'=>$return.'?cancelled=1']]]];
    }

    public static function matches(array $order,string $orderId,string $attempt,int $cents):bool
    {
        $units=$order['purchase_units']??null;$unit=is_array($units)?($units[0]??null):null;
        return ($order['id']??null)===$orderId&&($order['intent']??null)==='CAPTURE'&&is_array($units)&&array_is_list($units)&&count($units)===1&&is_array($unit)&&($unit['reference_id']??null)===$attempt&&($unit['custom_id']??null)===$attempt&&($unit['invoice_id']??null)===$attempt&&($unit['amount']['currency_code']??null)==='USD'&&($unit['amount']['value']??null)===self::usd($cents);
    }

    /** Read verified API responses only, never caller-supplied browser order details. */
    public static function settlement(array $order,string $orderId,string $attempt,int $cents,?string $expectedCapture=null):?array
    {
        if(($order['status']??null)!=='COMPLETED')return null;
        $captures=$order['purchase_units'][0]['payments']['captures']??[];
        if(!is_array($captures)||!array_is_list($captures))throw new UnexpectedValueException('Không tìm thấy giao dịch thu PayPal.');
        $capture=$expectedCapture===null?($captures[0]??null):null;
        if($expectedCapture!==null)foreach($captures as $row)if(is_array($row)&&($row['id']??null)===$expectedCapture){$capture=$row;break;}
        if(!is_array($capture)||!self::remoteId($capture['id']??null))throw new UnexpectedValueException('Không tìm thấy giao dịch thu PayPal.');
        if(($capture['status']??null)!=='COMPLETED')return null;
        $reason=self::matches($order,$orderId,$attempt,$cents)?'':'Đơn PayPal không khớp số tiền hoặc mã JinMemo; cần kiểm tra trên PayPal';
        if(count($captures)!==1||($capture['amount']['currency_code']??null)!=='USD'||($capture['amount']['value']??null)!==self::usd($cents)||($capture['final_capture']??null)!==true)$reason='Số tiền USD, tiền tệ hoặc lần thu PayPal không khớp; cần kiểm tra trên PayPal';
        return ['external'=>$capture['id'],'reason'=>$reason,'usd'=>is_string($capture['amount']['value']??null)?$capture['amount']['value']:'','currency'=>is_string($capture['amount']['currency_code']??null)?$capture['amount']['currency_code']:'','captureId'=>$capture['id'],'orderId'=>$orderId];
    }
}
