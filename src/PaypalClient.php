<?php
declare(strict_types=1);
require_once __DIR__.'/PaypalProtocol.php';

final class PaypalClientError extends RuntimeException
{
    public function __construct(public readonly int $status,string $message){parent::__construct($message);}
}

/** Server-only API transport. The optional transport is injected by offline tests, never by config. */
final class PaypalClient
{
    public function __construct(private ?Closure $transport=null){}

    private function request(string $mode,string $path,string $method,array $headers,?string $body):array
    {
        $url=PaypalProtocol::host($mode).$path;
        try{
            if($this->transport!==null){$response=($this->transport)(['url'=>$url,'method'=>$method,'headers'=>$headers,'body'=>$body]);}
            else{
                $curl=curl_init($url);$raw='';$list=[];foreach($headers as $key=>$value)$list[]=$key.': '.$value;
                curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$list,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>1000000)return 0;$raw.=$chunk;return strlen($chunk);}]);
                if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
                try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);if($ok===false)throw new RuntimeException('Transport failed');$response=['status'=>$status,'body'=>$raw];}finally{curl_close($curl);}
            }
            if(!is_array($response)||!is_int($response['status']??null)||!is_string($response['body']??null)||strlen($response['body'])>1000000)throw new RuntimeException('Invalid response');
            $object=json_decode($response['body'],false,64,JSON_THROW_ON_ERROR);$data=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
            if(!$object instanceof stdClass)throw new RuntimeException('Invalid response');
            if($response['status']<200||$response['status']>=300)throw new PaypalClientError(502,'PayPal chưa xử lý được yêu cầu. Kiểm tra cấu hình hoặc thử lại; không thanh toán thêm nếu đã trả tiền.');
            return $data;
        }catch(PaypalClientError $e){throw $e;}catch(Throwable){throw new PaypalClientError(502,'Chưa kết nối được PayPal. Vui lòng kiểm tra lại trạng thái trước khi thanh toán lần nữa.');}
    }

    private function token(string $mode,string $clientId,string $secret):string
    {
        if($clientId===''||$secret===''||strlen($clientId)>200||strlen($secret)>1024||preg_match('/[\x00-\x1f\x7f:]/',$clientId))throw new PaypalClientError(502,'Cấu hình PayPal không hợp lệ.');
        $data=$this->request($mode,'/v1/oauth2/token','POST',['Authorization'=>'Basic '.base64_encode($clientId.':'.$secret),'Content-Type'=>'application/x-www-form-urlencoded'],'grant_type=client_credentials');
        $token=$data['access_token']??null;
        if(!is_string($token)||$token===''||strlen($token)>10000||preg_match('/[\x00-\x20\x7f]/',$token))throw new PaypalClientError(502,'PayPal không trả về access token hợp lệ.');
        return $token;
    }

    private function api(string $mode,string $token,string $path,?array $body=null,?string $requestId=null):array
    {
        $headers=['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Prefer'=>'return=representation'];
        if($requestId!==null)$headers['PayPal-Request-Id']=$requestId;
        return $this->request($mode,$path,$body===null?'GET':'POST',$headers,$body===null?null:json_encode($body===[]?(object)[]:$body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public function create(string $mode,string $clientId,string $secret,string $attempt,string $code,int $cents,string $origin):array
    {
        $body=PaypalProtocol::createBody($attempt,$code,$cents,$origin);
        if($mode==='LIVE'&&!str_starts_with($origin,'https://'))throw new InvalidArgumentException('LIVE cần HTTPS.');
        $token=$this->token($mode,$clientId,$secret);$result=$this->api($mode,$token,'/v2/checkout/orders',$body,$attempt.'-create');
        if(!PaypalProtocol::remoteId($result['id']??null))throw new PaypalClientError(502,'Phản hồi PayPal không hợp lệ.');
        $approval=null;foreach(is_array($result['links']??null)?$result['links']:[] as $link)if(is_array($link)&&in_array($link['rel']??null,['payer-action','approve'],true)){$approval=$link['href']??null;break;}
        try{$url=PaypalProtocol::approval($approval,$mode);}catch(InvalidArgumentException){throw new PaypalClientError(502,'PayPal thiếu đường dẫn xác nhận hợp lệ.');}
        return ['id'=>$result['id'],'approval_url'=>$url];
    }

    /** The guard must recheck current configuration, expiry and payable targets immediately before capture. */
    public function complete(string $mode,string $clientId,string $secret,string $orderId,string $attempt,int $cents,bool $allowCapture,Closure $guard,?string $expectedCapture=null):?array
    {
        if(!PaypalProtocol::remoteId($orderId)||!preg_match('/^JMP[A-F0-9]{24}$/D',$attempt)||$expectedCapture!==null&&!PaypalProtocol::remoteId($expectedCapture))throw new InvalidArgumentException('Mã thanh toán PayPal không hợp lệ.');
        PaypalProtocol::usd($cents);$token=$this->token($mode,$clientId,$secret);$path='/v2/checkout/orders/'.$orderId;$order=$this->api($mode,$token,$path);
        if(!PaypalProtocol::matches($order,$orderId,$attempt,$cents)&&($order['status']??null)!=='COMPLETED')throw new PaypalClientError(409,'Đơn PayPal không khớp số tiền hoặc mã JinMemo.');
        if($allowCapture&&($order['status']??null)==='APPROVED'){
            $guard();
            try{$this->api($mode,$token,$path.'/capture',[],$attempt.'-capture');}
            catch(PaypalClientError $e){$order=$this->api($mode,$token,$path);if(($order['status']??null)!=='COMPLETED')throw $e;}
            $order=$this->api($mode,$token,$path);
            if(!PaypalProtocol::matches($order,$orderId,$attempt,$cents)&&($order['status']??null)!=='COMPLETED')throw new PaypalClientError(409,'Đơn PayPal sau thu tiền không khớp.');
        }
        try{return PaypalProtocol::settlement($order,$orderId,$attempt,$cents,$expectedCapture);}catch(UnexpectedValueException){throw new PaypalClientError(409,'Không tìm thấy giao dịch thu PayPal.');}
    }

    /** Verify remotely. Never fetch the certificate URL supplied by an incoming webhook. */
    public function webhook(string $mode,string $clientId,string $secret,string $webhookId,mixed $body,array $headers):?array
    {
        if(!is_array($body)||array_is_list($body))throw new PaypalClientError(400,'Webhook PayPal không hợp lệ.');
        if($webhookId==='')throw new PaypalClientError(401,'PayPal chưa cấu hình webhook.');
        $names=['paypal-auth-algo','paypal-cert-url','paypal-transmission-id','paypal-transmission-sig','paypal-transmission-time'];
        foreach($names as $name)if(!is_string($headers[$name]??null)||$headers[$name]===''||strlen($headers[$name])>4000)throw new PaypalClientError(401,'Thiếu chữ ký webhook PayPal.');
        $token=$this->token($mode,$clientId,$secret);$result=$this->api($mode,$token,'/v1/notifications/verify-webhook-signature',['auth_algo'=>$headers[$names[0]],'cert_url'=>$headers[$names[1]],'transmission_id'=>$headers[$names[2]],'transmission_sig'=>$headers[$names[3]],'transmission_time'=>$headers[$names[4]],'webhook_id'=>$webhookId,'webhook_event'=>$body]);
        if(($result['verification_status']??null)!=='SUCCESS')throw new PaypalClientError(401,'Chữ ký webhook PayPal không hợp lệ.');
        $type=$body['event_type']??null;if(!in_array($type,['PAYMENT.CAPTURE.COMPLETED','CHECKOUT.ORDER.APPROVED'],true))return null;
        $order=$type==='CHECKOUT.ORDER.APPROVED'?($body['resource']['id']??null):($body['resource']['supplementary_data']['related_ids']['order_id']??null);
        $capture=$type==='PAYMENT.CAPTURE.COMPLETED'?($body['resource']['id']??null):null;
        if(!PaypalProtocol::remoteId($order)||$capture!==null&&!PaypalProtocol::remoteId($capture)||$type==='PAYMENT.CAPTURE.COMPLETED'&&$capture===null)throw new PaypalClientError(400,'Webhook thiếu mã đơn/giao dịch PayPal.');
        return ['orderId'=>$order,'allowCapture'=>$type==='CHECKOUT.ORDER.APPROVED','expectedCapture'=>$capture];
    }
}
