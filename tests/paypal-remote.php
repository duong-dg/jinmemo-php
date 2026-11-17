<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/PaypalClient.php';
$remote=[];$remoteKeys=[];$remoteRequests=[];$captureCount=0;$serial=1;$verify='SUCCESS';$lostCreate=false;$lostCapture=false;$forcedId=null;
$client=new PaypalClient(function(array $request)use(&$remote,&$remoteKeys,&$remoteRequests,&$captureCount,&$serial,&$verify,&$lostCreate,&$lostCapture,&$forcedId){
    $remoteRequests[]=$request;$url=parse_url($request['url']);if(!in_array($url['host'],['api-m.paypal.com','api-m.sandbox.paypal.com'],true))throw new RuntimeException('Unexpected remote host');
    $response=fn(array $body,int $status=200)=>['status'=>$status,'body'=>json_encode($body,JSON_THROW_ON_ERROR)];
    if($url['path']==='/v1/oauth2/token')return $response(['access_token'=>'synthetic-server-token']);
    if($url['path']==='/v1/notifications/verify-webhook-signature')return $response(['verification_status'=>$verify]);
    if($url['path']==='/v2/checkout/orders'){
        $key=$request['headers']['PayPal-Request-Id'];if(!isset($remoteKeys[$key])){$id=$forcedId??('ORDER'.str_pad((string)$serial++,12,'0',STR_PAD_LEFT));$remoteKeys[$key]=$id;$data=json_decode($request['body'],true,512,JSON_THROW_ON_ERROR);$remote[$id]=['id'=>$id,'intent'=>'CAPTURE','status'=>'CREATED','purchase_units'=>$data['purchase_units'],'payer'=>['name'=>'PRIVATE-PAYER']];}
        $id=$remoteKeys[$key];if($lostCreate){$lostCreate=false;throw new RuntimeException('Lost create response');}
        return $response(['id'=>$id,'links'=>[['rel'=>'payer-action','href'=>'https://www.'.(str_contains($url['host'],'sandbox')?'sandbox.':'').'paypal.com/checkoutnow?token='.$id]]]);
    }
    if(preg_match('~^/v2/checkout/orders/([A-Z0-9]+)(/capture)?$~D',$url['path'],$m)){
        if(!isset($remote[$m[1]]))return $response([],404);
        if(isset($m[2])){if($remote[$m[1]]['status']!=='APPROVED')return $response([],422);$captureCount++;$remote[$m[1]]['status']='COMPLETED';$remote[$m[1]]['purchase_units'][0]['payments']=['captures'=>[['id'=>'CAPTURE'.str_pad((string)$serial++,12,'0',STR_PAD_LEFT),'status'=>'COMPLETED','final_capture'=>true,'amount'=>$remote[$m[1]]['purchase_units'][0]['amount'],'payer'=>'PRIVATE-CAPTURE']]];if($lostCapture){$lostCapture=false;throw new RuntimeException('Lost capture response');}}
        return $response($remote[$m[1]]);
    }
    throw new RuntimeException('Unexpected remote path');
});
