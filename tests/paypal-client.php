<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/PaypalClient.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function bad(callable $fn,string $why,?int $status=null):void{try{$fn();}catch(InvalidArgumentException $e){if($status===null)return;throw $e;}catch(PaypalClientError $e){check($e->status===$status,$why.' status '.$e->status);return;}throw new RuntimeException($why);}
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/paypal-money-node.json'),true,512,JSON_THROW_ON_ERROR);
foreach($fixtures['cases'] as $case){if($case['cents']===null)bad(fn()=>PaypalProtocol::cents($case['vnd'],$case['rate']),'Reject source money');else{$c=PaypalProtocol::cents($case['vnd'],$case['rate']);check($c===$case['cents']&&PaypalProtocol::usd($c)===$case['usd'],'Source integer rounding');}}
foreach([[true,25000],['600000',25000],[null,25000],[INF,25000],[600000,NAN]] as [$v,$r])bad(fn()=>PaypalProtocol::cents($v,$r),'Strict money types');
foreach([0,-1,100000000000,1.5,null] as $value)bad(fn()=>PaypalProtocol::usd($value),'USD bounds');
foreach(['http://www.paypal.com/x','https://www.paypal.com.evil.test','https://evil.test/?x=www.paypal.com','https://www.paypal.com@evil.test/x','https://user@www.paypal.com/x','https://www.paypal.com:444/x','https://www.sandbox.paypal.com/x',"https://www.paypal.com/\nX",'https://www.paypal.com\@evil.test/x'] as $url)bad(fn()=>PaypalProtocol::approval($url,'LIVE'),'Approval allowlist');
check(PaypalProtocol::approval('https://www.paypal.com/checkoutnow?token=ORDER1234567890','LIVE')!==''&&PaypalProtocol::approval('https://www.sandbox.paypal.com/checkoutnow','TEST')!=='','Mode-specific approval hosts');
$attempt='JMP'.str_repeat('A',24);$orderId='ORDER1234567890';$captureId='CAPTURE1234567890';$origin='https://shop.example.test';$code='JC1234567890';$cents=2308;
$template=PaypalProtocol::createBody($attempt,$code,$cents,$origin);$unit=$template['purchase_units'][0];
check($unit===['reference_id'=>$attempt,'custom_id'=>$attempt,'invoice_id'=>$attempt,'description'=>'JinMemo '.$code,'amount'=>['currency_code'=>'USD','value'=>'23.08']],'Source create contract');
$order=['id'=>$orderId,'intent'=>'CAPTURE','status'=>'APPROVED','purchase_units'=>[$unit],'payer'=>['email_address'=>'PRIVATE-CUSTOMER']];
$requests=[];$remote=$order;$captureCount=0;$createCount=0;$lost=false;$verify='SUCCESS';$approvalOverride=null;$forcedResponse=null;$throwTransport=false;$captureRejected=false;
$transport=function(array $request)use(&$requests,&$remote,&$captureCount,&$createCount,&$lost,&$verify,&$approvalOverride,&$forcedResponse,&$throwTransport,&$captureRejected,$orderId,$captureId,$attempt){
    $requests[]=$request;
    if($throwTransport)throw new RuntimeException('PRIVATE-TRANSPORT-ERROR');
    if($forcedResponse!==null)return $forcedResponse;
    $url=parse_url($request['url']);check(in_array($url['host'],['api-m.paypal.com','api-m.sandbox.paypal.com'],true)&&$url['scheme']==='https','Fixed HTTPS API hosts');
    $response=fn(array $value)=>['status'=>200,'body'=>json_encode($value,JSON_THROW_ON_ERROR)];
    if($url['path']==='/v1/oauth2/token'){check($request['method']==='POST'&&$request['headers']['Authorization']==='Basic '.base64_encode('CLIENT_1:synthetic-server-secret')&&$request['body']==='grant_type=client_credentials','OAuth credentials server only');return $response(['access_token'=>'fixture-token']);}
    check($request['headers']['Authorization']==='Bearer fixture-token','Server bearer token');
    if($url['path']==='/v1/notifications/verify-webhook-signature')return $response(['verification_status'=>$verify]);
    if($url['path']==='/v2/checkout/orders'){$createCount++;check($request['headers']['PayPal-Request-Id']===$attempt.'-create','Stable creation key');return $response(['id'=>$orderId,'links'=>[['rel'=>'payer-action','href'=>$approvalOverride??('https://www.'.(str_contains($url['host'],'sandbox')?'sandbox.':'').'paypal.com/checkoutnow?token='.$orderId)]]]);}
    if($url['path']==='/v2/checkout/orders/'.$orderId.'/capture'){
        if($captureRejected)return ['status'=>422,'body'=>'{}'];
        check($request['method']==='POST'&&$request['body']==='{}'&&$request['headers']['PayPal-Request-Id']===$attempt.'-capture','Stable capture key and object body');$captureCount++;$remote['status']='COMPLETED';$remote['purchase_units'][0]['payments']=['captures'=>[['id'=>$captureId,'status'=>'COMPLETED','final_capture'=>true,'amount'=>['currency_code'=>'USD','value'=>'23.08'],'payer'=>'PRIVATE-CAPTURE']]];
        if($lost){$lost=false;throw new RuntimeException('Lost capture response');}return $response($remote);
    }
    check($url['path']==='/v2/checkout/orders/'.$orderId&&$request['method']==='GET'&&$request['body']===null,'Read existing order');return $response($remote);
};
$client=new PaypalClient($transport);$mapping=$client->create('LIVE','CLIENT_1','synthetic-server-secret',$attempt,$code,$cents,$origin);check($mapping===['id'=>$orderId,'approval_url'=>'https://www.paypal.com/checkoutnow?token='.$orderId]&&!str_contains(json_encode($mapping),'secret'),'Safe approval response');
$client->create('TEST','CLIENT_1','synthetic-server-secret',$attempt,$code,$cents,$origin);check(str_starts_with($requests[count($requests)-1]['url'],'https://api-m.sandbox.paypal.com/'),'Sandbox host');
$approvalOverride='https://evil.test';bad(fn()=>$client->create('LIVE','CLIENT_1','synthetic-server-secret',$attempt,$code,$cents,$origin),'Bad remote approval',502);$approvalOverride=null;
$complete=fn(bool $allow,Closure $guard,?string $expected=null)=>$client->complete('LIVE','CLIENT_1','synthetic-server-secret',$orderId,$attempt,$cents,$allow,$guard,$expected);
$guardCalls=0;$guard=function()use(&$guardCalls){$guardCalls++;};
check($complete(false,$guard)===null&&$captureCount===0&&$guardCalls===0,'Read-only completion never starts capture');
bad(fn()=>$complete(true,fn()=>throw new PaypalClientError(409,'Expired or disabled')),'Guard prevents remote charge',409);check($captureCount===0,'No charge after guard rejection');
$remote['purchase_units'][0]['amount']['value']='24.00';bad(fn()=>$complete(true,$guard),'Reject wrong amount before capture',409);check($captureCount===0&&$guardCalls===0,'Mismatch never reaches guard/capture');$remote=$order;
$captureRejected=true;bad(fn()=>$complete(true,$guard),'Uncertain incomplete order still returns failure',502);check($captureCount===0,'No synthetic charge on rejected capture');$captureRejected=false;$guardCalls=0;
$lost=true;$proof=$complete(true,$guard);check($captureCount===1&&$guardCalls===1&&$proof['reason']===''&&$proof['captureId']===$captureId&&$proof['usd']==='23.08','Uncertain capture resolved by fresh GET');check(!str_contains(json_encode($proof),'PRIVATE')&&!str_contains(json_encode($proof),'fixture-token'),'Compact proof omits payer, tokens, remote payload');
check($complete(true,$guard)===$proof&&$captureCount===1&&$guardCalls===1,'Retry completed order never captures again');
$remote['purchase_units'][0]['payments']['captures'][0]['final_capture']=false;check($complete(false,$guard)['reason']!=='','Partial capture REVIEW');$remote['purchase_units'][0]['payments']['captures'][0]['final_capture']=true;
$remote['purchase_units'][0]['payments']['captures'][0]['amount']['value']='23.09';check($complete(false,$guard)['reason']!=='','Wrong capture amount REVIEW');$remote['purchase_units'][0]['payments']['captures'][0]['amount']['value']='23.08';
$remote['purchase_units'][0]['reference_id']='JMP'.str_repeat('B',24);check($complete(false,$guard)['reason']!=='','Completed mismatched reference retained for REVIEW');$remote['purchase_units'][0]['reference_id']=$attempt;
bad(fn()=>$complete(false,$guard,'CAPTURE9999999999'),'Missing expected capture',409);
$extra=$remote['purchase_units'][0]['payments']['captures'][0];$extra['id']='CAPTURE9999999999';$remote['purchase_units'][0]['payments']['captures'][]=$extra;check($complete(false,$guard,$captureId)['reason']!=='','Multiple captures REVIEW even when expected capture exists');array_pop($remote['purchase_units'][0]['payments']['captures']);
$remote['purchase_units'][0]['payments']['captures'][0]['status']='PENDING';check($complete(false,$guard)===null,'Pending capture not settled');$remote['purchase_units'][0]['payments']['captures'][0]['status']='COMPLETED';
$headers=['paypal-auth-algo'=>'SHA256withRSA','paypal-cert-url'=>'http://127.0.0.1/private-certificate','paypal-transmission-id'=>'TX1','paypal-transmission-sig'=>'signature','paypal-transmission-time'=>'2026-11-17T00:00:00Z'];
$event=['event_type'=>'PAYMENT.CAPTURE.COMPLETED','resource'=>['id'=>$captureId,'supplementary_data'=>['related_ids'=>['order_id'=>$orderId]],'payer'=>'PRIVATE']];
$webhook=fn($body,$h)=>$client->webhook('LIVE','CLIENT_1','synthetic-server-secret','WH-FIXTURE',$body,$h);
$before=count($requests);bad(fn()=>$webhook($event,[]),'Missing signature',401);check(count($requests)===$before,'No remote request for absent signature');
$verify='FAILURE';bad(fn()=>$webhook($event,$headers),'Failed remote signature',401);$verify='SUCCESS';
check($webhook($event,$headers)===['orderId'=>$orderId,'allowCapture'=>false,'expectedCapture'=>$captureId],'Verified capture notification only reads');$last=$requests[count($requests)-1];$verifiedBody=json_decode($last['body'],true);check($verifiedBody['cert_url']==='http://127.0.0.1/private-certificate'&&$verifiedBody['webhook_id']==='WH-FIXTURE','Certificate URL passed to PayPal, never fetched');
check($webhook(['event_type'=>'CHECKOUT.ORDER.APPROVED','resource'=>['id'=>$orderId]],$headers)===['orderId'=>$orderId,'allowCapture'=>true,'expectedCapture'=>null],'Approved event requests guarded capture');
check($webhook(['event_type'=>'PAYMENT.CAPTURE.REFUNDED'],$headers)===null,'Unrelated event does not refund or capture');
bad(fn()=>$webhook(['event_type'=>'PAYMENT.CAPTURE.COMPLETED','resource'=>['id'=>'bad']],$headers),'Malformed capture event',400);
foreach([['status'=>302,'body'=>'{}'],['status'=>401,'body'=>'{"secret":"PRIVATE-REMOTE-ERROR"}'],['status'=>200,'body'=>'not JSON'],['status'=>200,'body'=>'[]'],['status'=>200,'body'=>str_repeat('x',1000001)],['status'=>200,'body'=>'{"access_token":"bad\\r\\nInjected"}']] as $response){$forcedResponse=$response;try{$client->create('LIVE','CLIENT_1','synthetic-server-secret',$attempt,$code,$cents,$origin);throw new RuntimeException('Expected safe remote error');}catch(PaypalClientError $e){check($e->status===502&&!str_contains($e->getMessage(),'PRIVATE')&&!str_contains($e->getMessage(),'synthetic-server-secret'),'Remote failures sanitized');}}
$forcedResponse=null;$throwTransport=true;bad(fn()=>$client->create('LIVE','CLIENT_1','synthetic-server-secret',$attempt,$code,$cents,$origin),'Timeout/network error',502);
echo "PASS: source integer USD rounding, mode/approval allowlists, fixed server API, stable create/capture keys, guarded capture, uncertain-response recovery, remote webhook verification and private compact proof.\n";
