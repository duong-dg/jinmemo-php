<?php
declare(strict_types=1);
require_once __DIR__.'/PaymentSettings.php';
require_once __DIR__.'/PaypalClient.php';
require_once __DIR__.'/PaypalSettings.php';
require_once __DIR__.'/PaymentTargets.php';
require_once __DIR__.'/CheckoutBundles.php';
require_once __DIR__.'/HotelPayments.php';
final class PaypalPayments
{
    private PaymentSettings $settings;
    public function __construct(private PDO $db,private array $env=[],private ?PaypalClient $client=null){$this->settings=new PaymentSettings($db,$env);$this->client??=new PaypalClient();}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function tx(callable $fn):array{$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');$r=$fn();$this->db->commit();return $r;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}
    public function checkout(array $in,bool $test=false,?array $context=null):array
    {
        if(array_diff(array_keys($in),['code','accessKey','email','provider'])||!is_string($in['code']??null)||!preg_match('/^J[GMCBH][A-F0-9]{10}$/D',$in['code'])||!in_array($in['provider']??null,['PAYPAL'],true)||array_key_exists('accessKey',$in)&&(!is_string($in['accessKey'])||!preg_match('/^[a-f0-9]{64}$/D',$in['accessKey']))||array_key_exists('email',$in)&&(!is_string($in['email'])||strlen($in['email'])>200||!filter_var($in['email'],FILTER_VALIDATE_EMAIL)))throw new HttpError(400,'Kiểm tra mã đơn, khóa/email và cổng thanh toán.');if($context!==null&&($test||!str_starts_with($in['code'],'JC')))throw new HttpError(400,'Chỉ thanh toán giỏ CTV LIVE.');if(!$test&&$context===null&&!isset($in['accessKey'])&&!isset($in['email']))throw new HttpError(400,'Nhập khóa hoặc email tra cứu.');if($test&&(str_starts_with($in['code'],'JB')||str_starts_with($in['code'],'JH')))throw new HttpError(400,'TEST dùng vé đơn hoặc giỏ vé web.');
        $a=$this->tx(function()use($in,$test,$context){$c=$this->settings->config();if($c['mode']!==($test?'TEST':'LIVE')||!PaypalSettings::ready($c))throw new HttpError(400,'Cổng chưa bật hoặc đang ở môi trường khác.');$base=$this->settings->base();if(!$test&&!str_starts_with($base,'https://'))throw new HttpError(503,'Thanh toán LIVE cần HTTPS.');if($context!==null)(new PaymentTargets($this->db))->authorizeContext($in,$context);elseif(!$test)(new PaymentTargets($this->db))->authorizeCode($in);$s=(new PaymentTargets($this->db))->state($in['code'],$context);$config=['paypalClientId'=>$c['paypalClientId'],'paypalEncrypted'=>$c['paypalEncrypted'],'paypalWebhookId'=>$c['paypalWebhookId'],'paypalRate'=>$c['paypalRate'],'usdCents'=>$this->cents($s['amount'],$c['paypalRate'])];if(isset($s['hotel']))$config['hotelId']=$s['hotel']['row']['id'];elseif($s['bundle'])$config['bundleId']=$s['bundle']['id'];elseif($s['cart'])$config+=['cartId'=>$s['cart']['id'],'cartOrders'=>array_map(fn($o)=>['id'=>$o['id'],'amount'=>json_decode($o['snapshot'],true,512,JSON_THROW_ON_ERROR)['total']],$s['orders']),'channel'=>$s['cart']['channel'],'partnerId'=>$s['cart']['partner_id'],'counterId'=>$s['cart']['counter_id']];$orderId=$s['orders'][0]['id']??null;$q=$this->query("SELECT * FROM jingo_gateway_attempts WHERE provider='PAYPAL' AND mode=? AND config_version=? AND amount=? AND status='PENDING' AND expires_at>UTC_TIMESTAMP(6) ORDER BY created_at DESC,id DESC",[$c['mode'],$c['version'],$s['amount']]);$attempt=null;while($r=$q->fetch(PDO::FETCH_ASSOC))if($r['order_id']===$orderId&&json_decode($r['config'],true,512,JSON_THROW_ON_ERROR)===$config){$attempt=$r;break;}if(!$attempt){$id='JMP'.strtoupper(bin2hex(random_bytes(12)));$this->query("INSERT INTO jingo_gateway_attempts(id,order_id,provider,mode,amount,config_version,config,expires_at) VALUES (?,?,'PAYPAL',?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE))",[$id,$orderId,$c['mode'],$s['amount'],$c['version'],json_encode($config,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$attempt=$this->query('SELECT * FROM jingo_gateway_attempts WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);}return $attempt;});
        return $this->create($a,$in['code']);
    }
    private function cents(int $amount,int $rate):int{try{return PaypalProtocol::cents($amount,$rate);}catch(InvalidArgumentException $e){throw new HttpError(400,$e->getMessage());}}
    private function create(array $a,string $code):array
    {
        $config=json_decode($a['config'],true,512,JSON_THROW_ON_ERROR);$mapping=$this->query('SELECT * FROM jingo_paypal_orders WHERE attempt_id=?',[$a['id']])->fetch(PDO::FETCH_ASSOC);
        if(!$mapping){
            $remote=$this->client->create($a['mode'],$config['paypalClientId'],(new SecretCipher(OrderMail::directory()))->decrypt($config['paypalEncrypted']),$a['id'],$code,$config['usdCents'],$this->settings->base());
            $mapping=$this->tx(function()use($a,$remote){
                $existing=$this->query('SELECT * FROM jingo_paypal_orders WHERE id=? OR attempt_id=?',[$remote['id'],$a['id']])->fetchAll(PDO::FETCH_ASSOC);
                foreach($existing as $row)if($row['attempt_id']!==$a['id']||$row['id']!==$remote['id'])throw new HttpError(409,'Mã PayPal đã gắn với yêu cầu khác.');
                if($existing)return $existing[0];
                $this->query('INSERT INTO jingo_paypal_orders(id,attempt_id,approval_url) VALUES (?,?,?)',[$remote['id'],$a['id'],$remote['approval_url']]);return $remote;
            });
        }
        return ['id'=>$a['id'],'provider'=>'PAYPAL','mode'=>$a['mode'],'amount'=>(int)$a['amount'],'currency'=>'USD','usdAmount'=>PaypalProtocol::usd($config['usdCents']),'exchangeRate'=>$config['paypalRate'],'expiresAt'=>$a['expires_at'],'url'=>PaypalProtocol::approval($mapping['approval_url'],$a['mode'])];
    }

    private function attempt(string $orderId):array
    {
        if(!PaypalProtocol::remoteId($orderId))throw new HttpError(400,'Mã PayPal không hợp lệ.');
        $a=$this->query("SELECT a.* FROM jingo_paypal_orders p JOIN jingo_gateway_attempts a ON a.id=p.attempt_id WHERE p.id=? AND a.provider='PAYPAL'",[$orderId])->fetch(PDO::FETCH_ASSOC);
        if(!$a)throw new HttpError(404,'Không tìm thấy thanh toán PayPal.');return $a;
    }

    private function guard(array $original):void
    {
        $this->tx(function()use($original){
            $a=$this->query("SELECT * FROM jingo_gateway_attempts WHERE id=? AND provider='PAYPAL' FOR UPDATE",[$original['id']])->fetch(PDO::FETCH_ASSOC);if(!$a)throw new HttpError(409,'Không tìm thấy yêu cầu PayPal.');
            $config=json_decode($a['config'],true,512,JSON_THROW_ON_ERROR);$current=$this->settings->config();
            if(!PaypalSettings::ready($current)||$current['mode']!==$a['mode']||$current['paypalClientId']!==$config['paypalClientId']||$current['paypalEncrypted']!==$config['paypalEncrypted']||$current['paypalWebhookId']!==$config['paypalWebhookId']||strtotime($a['expires_at'].' UTC')<=time()||$a['status']!=='PENDING')throw new HttpError(409,'Yêu cầu PayPal đã hết hạn hoặc cấu hình thay đổi. Chưa thu thêm tiền.');
            if(isset($config['hotelId']))$code=$this->query('SELECT code FROM jingo_stay_bookings WHERE id=?',[$config['hotelId']])->fetchColumn();
            elseif(isset($config['bundleId']))$code=$this->query('SELECT code FROM jingo_checkout_bundles WHERE id=?',[$config['bundleId']])->fetchColumn();
            elseif(isset($config['cartId']))$code=$this->query('SELECT code FROM jingo_carts WHERE id=?',[$config['cartId']])->fetchColumn();
            else $code=$this->query('SELECT code FROM jingo_orders WHERE id=?',[$a['order_id']])->fetchColumn();
            if(!$code)throw new HttpError(409,'Đơn không còn hợp lệ để thu PayPal.');$state=(new PaymentTargets($this->db))->state($code,PaymentTargets::context($config));
            if($state['amount']!==(int)$a['amount'])throw new HttpError(409,'Giá đơn đã thay đổi. Chưa thu thêm tiền.');
            if(isset($config['cartOrders'])&&array_map(fn($o)=>['id'=>$o['id'],'amount'=>json_decode($o['snapshot'],true,512,JSON_THROW_ON_ERROR)['total']],$state['orders'])!==$config['cartOrders'])throw new HttpError(409,'Thành phần giỏ đã thay đổi. Chưa thu thêm tiền.');
            return [];
        });
    }

    private function complete(string $orderId,bool $allowCapture,?string $expectedCapture=null):array
    {
        $a=$this->attempt($orderId);$config=json_decode($a['config'],true,512,JSON_THROW_ON_ERROR);
        $proof=$this->client->complete($a['mode'],$config['paypalClientId'],(new SecretCipher(OrderMail::directory()))->decrypt($config['paypalEncrypted']),$orderId,$a['id'],$config['usdCents'],$allowCapture,fn()=>$this->guard($a),$expectedCapture);
        if($proof===null)return ['status'=>'PENDING'];
        $fingerprint=hash('sha256',json_encode(['attempt'=>$a['id'],'orderId'=>$proof['orderId'],'captureId'=>$proof['captureId'],'usd'=>$proof['usd'],'currency'=>$proof['currency']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
        return $this->settle($a,$proof['external'],(int)$a['amount'],$fingerprint,$proof['reason']);
    }

    public function capture(array $body):array
    {
        if(array_keys($body)!==['orderId']||!PaypalProtocol::remoteId($body['orderId']))throw new HttpError(400,'Mã PayPal không hợp lệ.');return $this->complete($body['orderId'],true);
    }

    public function webhook(mixed $body,array $headers):array
    {
        $current=$this->settings->config();if(empty($current['paypalEncrypted'])||$current['paypalWebhookId']===''||$current['paypalClientId']==='')throw new HttpError(401,'PayPal chưa cấu hình webhook.');
        $event=$this->client->webhook($current['mode'],$current['paypalClientId'],(new SecretCipher(OrderMail::directory()))->decrypt($current['paypalEncrypted']),$current['paypalWebhookId'],$body,$headers);
        if($event===null||!$this->query('SELECT attempt_id FROM jingo_paypal_orders WHERE id=?',[$event['orderId']])->fetchColumn())return ['success'=>true];
        $a=$this->attempt($event['orderId']);$config=json_decode($a['config'],true,512,JSON_THROW_ON_ERROR);if($a['mode']!==$current['mode']||$config['paypalClientId']!==$current['paypalClientId'])throw new HttpError(409,'Môi trường hoặc tài khoản PayPal không khớp.');
        $this->complete($event['orderId'],$event['allowCapture'],$event['expectedCapture']);return ['success'=>true];
    }

    private function settle(array $original,string $external,int $amount,string $fingerprint,string $reason=''):array
    {
        return $this->tx(function()use($original,$external,$amount,$fingerprint,$reason){$id='PAYPAL:'.$original['mode'].':'.$external;$prior=$this->query('SELECT status,fingerprint FROM jingo_gateway_events WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if($prior){if(!hash_equals($prior['fingerprint'],$fingerprint))throw new HttpError(409,'Giao dịch đã nhận với dữ liệu khác.');return ['duplicate'=>true,'status'=>$prior['status']];}$a=$this->query('SELECT * FROM jingo_gateway_attempts WHERE id=? FOR UPDATE',[$original['id']])->fetch(PDO::FETCH_ASSOC);$config=json_decode($a['config'],true,512,JSON_THROW_ON_ERROR);$this->query("INSERT INTO jingo_gateway_events(id,attempt_id,order_id,provider,mode,amount,status,reason,fingerprint) VALUES (?,?,?,'PAYPAL',?,?,'REVIEW','Đang xử lý',?)",[$id,$a['id'],$a['order_id'],$a['mode'],$amount,$fingerprint]);$s=null;try{if(isset($config['hotelId'])){$code=$this->query('SELECT code FROM jingo_stay_bookings WHERE id=?',[$config['hotelId']])->fetchColumn();if(!$code)throw new HttpError(409,'Không tìm thấy đơn phòng.');$s=(new PaymentTargets($this->db))->state($code,PaymentTargets::context($config));}elseif(isset($config['bundleId'])){$b=$this->query('SELECT code FROM jingo_checkout_bundles WHERE id=?',[$config['bundleId']])->fetchColumn();if(!$b)throw new HttpError(409,'Mã tổng không còn hợp lệ.');$s=(new PaymentTargets($this->db))->state($b,PaymentTargets::context($config));}elseif(isset($config['cartId'])){$code=$this->query('SELECT code FROM jingo_carts WHERE id=?',[$config['cartId']])->fetchColumn();if(!$code)throw new HttpError(409,'Giỏ không còn hợp lệ.');$s=(new PaymentTargets($this->db))->state($code,PaymentTargets::context($config));$currentTargets=array_map(fn($o)=>['id'=>$o['id'],'amount'=>json_decode($o['snapshot'],true,512,JSON_THROW_ON_ERROR)['total']],$s['orders']);if($currentTargets!==$config['cartOrders'])$reason='Thành phần giỏ đã thay đổi.';}else{$code=$this->query('SELECT code FROM jingo_orders WHERE id=?',[$a['order_id']])->fetchColumn();if(!$code)throw new HttpError(409,'Đơn không còn hợp lệ.');$s=(new PaymentTargets($this->db))->state($code,PaymentTargets::context($config));}}catch(HttpError $e){$reason=$reason?:$e->getMessage();}if(!$s||$s['amount']!==$amount||(int)$a['amount']!==$amount)$reason=$reason?:'Số tiền không khớp.';if(strtotime($a['expires_at'].' UTC')<=time())$reason='Yêu cầu đã hết hạn; cần đối soát thủ công.';$current=$this->settings->config();if($current['mode']!==$a['mode']||!$current['paypalEnabled'])$reason='Cổng đã tắt hoặc đổi môi trường.';elseif(($current['paypalEncrypted']??null)!==$config['paypalEncrypted']||$current['paypalClientId']!==$config['paypalClientId']||$current['paypalWebhookId']!==$config['paypalWebhookId'])$reason='Tài khoản hoặc khóa PayPal thay đổi; cần đối soát thủ công.';if($this->query('SELECT reference_hash FROM jingo_payment_references WHERE reference_hash=?',[hash('sha256',$id)])->fetchColumn())$reason='Giao dịch đã có trong sổ thu.';$status=$reason!==''?'REVIEW':($a['mode']==='TEST'?'TEST':'PAID');if($status==='PAID')(new PaymentTargets($this->db))->credit($s,$id,'PAYPAL');if(isset($config['hotelId'])&&$this->query('SELECT id FROM jingo_stay_bookings WHERE id=?',[$config['hotelId']])->fetchColumn())$this->query('INSERT INTO jingo_stay_payment_events(event_id,booking_id) VALUES (?,?)',[$id,$config['hotelId']]);$this->query('UPDATE jingo_gateway_events SET status=?,reason=? WHERE id=?',[$status,$reason,$id]);$this->query("UPDATE jingo_gateway_attempts SET status=? WHERE id=? AND status<>'PAID'",[$status,$a['id']]);return ['duplicate'=>false,'status'=>$status];});
    }
}
