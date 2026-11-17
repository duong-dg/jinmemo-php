<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^jingo_vnpay_test_[a-f0-9]{12}$/D',$argv[1]??''))exit(1);
foreach(['AdminAuth','Pricing','Products','Inventory','Vouchers','Quotes','Orders','PaypalPayments'] as $class)require_once dirname(__DIR__).'/src/'.$class.'.php';
$db=new PDO(getenv('JINGO_TEST_DSN').';dbname='.$argv[1],getenv('JINGO_TEST_USER')?:'root',getenv('JINGO_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");
$task=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
$client=new PaypalClient(static function(array $request)use($task){$path=parse_url($request['url'],PHP_URL_PATH);if($path==='/v1/oauth2/token')return ['status'=>200,'body'=>'{"access_token":"synthetic-worker-token"}'];if($request['method']!=='GET'||$path!=='/v2/checkout/orders/'.$task['orderId'])throw new RuntimeException('Unexpected capture');return ['status'=>200,'body'=>json_encode($task['remote'],JSON_THROW_ON_ERROR)];});
echo (new PaypalPayments($db,['PUBLIC_BASE_URL'=>'https://payments.example.test'],$client))->capture(['orderId'=>$task['orderId']])['status'];
