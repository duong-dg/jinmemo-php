<?php
declare(strict_types=1);

/** Web order creation. Prices and allocations are committed together. */
final class Orders
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function fail(string $message='Dữ liệu đơn hàng không hợp lệ.'):never{throw new HttpError(400,$message);}
    private static function uuid(mixed $v):bool{return is_string($v)&&(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$v);}
    private static function text(array $input,string $key,int $min,int $max):string
    {
        $v=$input[$key] ?? null;if(!is_string($v))self::fail();$v=trim($v);$n=mb_strlen($v,'UTF-8');if($n<$min||$n>$max)self::fail();return $v;
    }
    private static function canonical(mixed $v):mixed
    {
        if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);foreach($v as &$item)$item=self::canonical($item);unset($item);return $v;
    }
    public static function validate(array $input):array
    {
        if(array_diff(array_keys($input),['requestId','accessKey','productId','variantId','date','voucherCode','slotId','quantities','expectedTotal','contact','locale','accepted']))self::fail();
        if(!self::uuid($input['requestId'] ?? null)||!is_string($input['accessKey'] ?? null)||!preg_match('/^[a-f0-9]{64}$/iD',$input['accessKey'])||($input['accepted'] ?? null)!==true)self::fail();
        foreach(['productId','variantId'] as $key)if(!is_string($input[$key] ?? null)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$input[$key]))self::fail();
        if(!is_string($input['date'] ?? null))self::fail();Pricing::validateDate($input['date']);
        if(!is_array($input['quantities'] ?? null)||array_diff(array_keys($input['quantities']),Pricing::GROUPS))self::fail();foreach($input['quantities'] as $q)if(!is_int($q)||$q<0||$q>50)self::fail();
        if(!is_int($input['expectedTotal'] ?? null)||$input['expectedTotal']<0||$input['expectedTotal']>15000000000)self::fail();
        if(isset($input['slotId'])&&(!is_string($input['slotId'])||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$input['slotId'])))self::fail();
        if(array_key_exists('slotId',$input)&&$input['slotId']===null)self::fail();
        if(array_key_exists('voucherCode',$input)){if(!is_string($input['voucherCode']))self::fail();$input['voucherCode']=strtoupper(trim($input['voucherCode']));if(!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/D',$input['voucherCode']))self::fail();}
        $input['locale']=$input['locale'] ?? 'vi';if(!in_array($input['locale'],['vi','en'],true))self::fail();
        $c=$input['contact'] ?? null;if(!is_array($c)||array_diff(array_keys($c),['name','email','phone','note','invoice']))self::fail();
        $c['name']=self::text($c,'name',2,100);$c['email']=self::text($c,'email',1,200);$c['phone']=self::text($c,'phone',8,25);$c['note']=self::text($c+['note'=>''],'note',0,1000);
        $digits=preg_replace('/\D/','',$c['phone']);if(!filter_var($c['email'],FILTER_VALIDATE_EMAIL)||!preg_match('/^\+?[0-9 ()-]{8,25}$/D',$c['phone'])||strlen($digits)<8||strlen($digits)>15)self::fail('Kiểm tra email và điện thoại.');
        if(array_key_exists('invoice',$c)){$i=$c['invoice'];if(!is_array($i)||array_diff(array_keys($i),['name','taxCode','address','email']))self::fail();$i=['name'=>self::text($i,'name',2,200),'taxCode'=>self::text($i+['taxCode'=>''],'taxCode',0,30),'address'=>self::text($i,'address',3,300),'email'=>self::text($i,'email',1,200)];if(!filter_var($i['email'],FILTER_VALIDATE_EMAIL)||!preg_match('/^[A-Za-z0-9-]*$/D',$i['taxCode']))self::fail();$c['invoice']=$i;}
        $input['contact']=$c;return $input;
    }
    private function prior(array $input,string $hash):?array
    {
        $r=$this->query('SELECT code,request_hash FROM jingo_orders WHERE request_id=?',[$input['requestId']])->fetch(PDO::FETCH_ASSOC);if(!$r)return null;if(!hash_equals($r['request_hash'],$hash))throw new HttpError(409,'Mã yêu cầu đã dùng cho đơn khác.');return ['code'=>$r['code'],'accessKey'=>$input['accessKey']];
    }
    public function create(array $input):array
    {
        $input=self::validate($input);$hash=hash('sha256',json_encode(self::canonical($input),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        // READ COMMITTED lets a second worker observe the first worker's replay after the product lock.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            if($prior=$this->prior($input,$hash)){$this->db->commit();return $prior;}
            if(!$this->query('SELECT id FROM jingo_products WHERE id=? FOR UPDATE',[$input['productId']])->fetchColumn())throw new HttpError(404,'Không tìm thấy sản phẩm.');
            if($prior=$this->prior($input,$hash)){$this->db->commit();return $prior;}
            $p=(new Products($this->db))->find($input['productId'],false,false,$input['locale']);if($p['demo'] ?? false)self::fail('Sản phẩm thử nghiệm không nhận đơn.');
            if($input['locale']==='en'){$p['name']=$p['english']['name'];foreach($p['variants'] as &$v)foreach($p['english']['variants'] as $t)if($v['id']===$t['id']){$v['name']=$t['name'];break;}unset($v);}
            $quote=Pricing::online($p,$input['variantId'],$input['date'],$input['quantities'],$p['rules'],$p['closures'],$input['slotId'] ?? null);
            $voucher=$input['voucherCode'] ?? '';Pricing::checkVoucher($quote,$voucher);$discount=null;
            if($voucher!==''){$discount=(new Vouchers($this->db))->discount($voucher,$quote['total'],['productId'=>$p['id'],'variantId'=>$input['variantId'],'date'=>$input['date']],true);$quote['discount']=['code'=>$discount['code'],'amount'=>$discount['amount'],'subtotal'=>$discount['subtotal']];$quote['total']-=$discount['amount'];}
            if($quote['total']!==$input['expectedTotal'])throw new HttpError(409,'Giá đã thay đổi. Vui lòng xem lại báo giá.');
            $quantity=array_sum(array_column($quote['lines'],'quantity'));if($quantity<1||$quantity>150)self::fail('Chọn từ 1 đến 150 khách.');
            $hex=bin2hex(random_bytes(16));$id=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);$code='JG'.strtoupper(bin2hex(random_bytes(5)));
            $snapshot=$quote+['productId'=>$p['id'],'productName'=>$p['name'],'variantId'=>$input['variantId'],'date'=>$input['date'],'locale'=>$input['locale'],'demo'=>false];foreach($p['variants'] as $v)if($v['id']===$input['variantId']){$snapshot['variantName']=$v['name'];$snapshot['conditions']=$v['conditions'] ?? '';}
            $this->query('INSERT INTO jingo_orders(id,code,request_id,request_hash,access_hash,snapshot,contact,instructions) VALUES (?,?,?,?,?,?,?,?)',[$id,$code,$input['requestId'],$hash,hash('sha256',$input['accessKey']),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($input['contact'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'']);
            (new Inventory($this->db))->allocate($id,$p['id'],$input['variantId'],$input['date'],$quote['timeSlot']['id'] ?? '',$quantity);if($discount)(new Vouchers($this->db))->allocate($id,$discount['id']);$this->db->commit();return ['code'=>$code,'accessKey'=>$input['accessKey']];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062){if($prior=$this->prior($input,$hash))return $prior;throw new HttpError(409,'Trùng mã đơn. Vui lòng thử lại.');}throw $e;}
    }
    private function detail(array $row,bool $admin):array
    {
        $data=['id'=>$row['id'],'code'=>$row['code'],'snapshot'=>json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR),'bookingStatus'=>$row['booking_status'],'paymentStatus'=>$row['payment_status'],'instructions'=>$row['instructions'],'version'=>(int)$row['version'],'createdAt'=>str_replace(' ','T',$row['created_at']).'Z','fulfillmentStatus'=>'PENDING','tickets'=>[]];if($admin){$data['contact']=json_decode($row['contact'],true,512,JSON_THROW_ON_ERROR);$data['channel']='WEB';}
        foreach($this->query('SELECT id,code,kind,covers,note,created_at FROM jingo_order_tickets WHERE order_id=? ORDER BY created_at,id',[$row['id']])->fetchAll(PDO::FETCH_ASSOC) as $ticket)$data['tickets'][]=['id'=>$ticket['id'],'code'=>$ticket['code'],'kind'=>$ticket['kind'],'covers'=>(int)$ticket['covers'],'note'=>$ticket['note'],'createdAt'=>str_replace(' ','T',$ticket['created_at']).'Z'];$data['deliveredGuests']=array_sum(array_column($data['tickets'],'covers'));$data['totalGuests']=array_sum(array_column($data['snapshot']['lines'],'quantity'));$data['fulfillmentStatus']=$data['deliveredGuests']>=$data['totalGuests']?'DELIVERED':($data['deliveredGuests']>0?'PARTIAL':'PENDING');return $data;
    }
    public function lookup(array $input):array
    {
        if(array_diff(array_keys($input),['code','accessKey','email'])||!is_string($input['code'] ?? null)||!preg_match('/^J[GM][A-F0-9]{10}$/D',$input['code']))self::fail();
        if(array_key_exists('accessKey',$input)&&(!is_string($input['accessKey'])||!preg_match('/^[a-f0-9]{64}$/iD',$input['accessKey'])))self::fail();
        if(array_key_exists('email',$input)&&(!is_string($input['email'])||strlen($input['email'])>200||!filter_var($input['email'],FILTER_VALIDATE_EMAIL)))self::fail();if(!isset($input['accessKey'])&&!isset($input['email']))self::fail();
        $row=$this->query('SELECT * FROM jingo_orders WHERE code=?',[$input['code']])->fetch(PDO::FETCH_ASSOC);$ok=false;if($row){$contact=json_decode($row['contact'],true,512,JSON_THROW_ON_ERROR);$ok=isset($input['accessKey'])?hash_equals($row['access_hash'],hash('sha256',$input['accessKey'])):strtolower(trim($contact['email']))===strtolower(trim($input['email']));}if(!$ok)throw new HttpError(404,'Không tìm thấy đơn.');return $this->detail($row,false);
    }
    public function get(string $id):array
    {
        $row=$this->query('SELECT * FROM jingo_orders WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new HttpError(404,'Không tìm thấy đơn.');$result=$this->detail($row,true);$result['history']=[];
        foreach($this->query('SELECT id,admin_id,action,data,created_at FROM jingo_order_actions WHERE order_id=? ORDER BY order_version',[$id])->fetchAll(PDO::FETCH_ASSOC) as $a)$result['history'][]=['id'=>$a['id'],'adminId'=>$a['admin_id'],'action'=>$a['action'],'data'=>json_decode($a['data'],true,512,JSON_THROW_ON_ERROR),'createdAt'=>str_replace(' ','T',$a['created_at']).'Z'];
        $result['payments']=[];foreach($this->query('SELECT p.id,p.amount,p.method,p.admin_id,p.created_at,r.reference FROM jingo_order_payments p JOIN jingo_payment_references r ON r.reference_hash=p.reference_hash WHERE p.order_id=? ORDER BY p.created_at,p.id',[$id])->fetchAll(PDO::FETCH_ASSOC) as $p)$result['payments'][]=['id'=>$p['id'],'amount'=>(int)$p['amount'],'method'=>$p['method'],'reference'=>$p['reference'],'adminId'=>$p['admin_id'],'createdAt'=>str_replace(' ','T',$p['created_at']).'Z'];return $result;
    }
    public function list(array $input):array
    {
        if(array_diff(array_keys($input),['q','booking','payment','page','pageSize']))self::fail('Bộ lọc đơn không hợp lệ.');
        $q=self::text($input+['q'=>''],'q',0,120);$booking=$input['booking'] ?? 'ALL';$payment=$input['payment'] ?? 'ALL';
        if(!in_array($booking,['ALL','REQUESTED','CONFIRMED','CANCELLED'],true)||!in_array($payment,['ALL','UNPAID','PAID','CREDIT'],true))self::fail();
        $numbers=[];foreach(['page'=>1000000,'pageSize'=>100] as $key=>$max){$v=$input[$key] ?? ($key==='page'?1:25);if((!is_int($v)&&(!is_string($v)||!preg_match('/^[1-9][0-9]*$/D',$v)))||(int)$v<1||(int)$v>$max)self::fail();$numbers[$key]=(int)$v;}
        $where=['1=1'];$args=[];if($booking!=='ALL'){$where[]='booking_status=?';$args[]=$booking;}if($payment!=='ALL'){$where[]='payment_status=?';$args[]=$payment;}
        if($q!==''){$where[]="LOCATE(LOWER(?),LOWER(CONCAT_WS(' ',code,JSON_UNQUOTE(JSON_EXTRACT(contact,'$.name')),JSON_UNQUOTE(JSON_EXTRACT(contact,'$.email')),JSON_UNQUOTE(JSON_EXTRACT(contact,'$.phone')))))>0";$args[]=$q;}$where=implode(' AND ',$where);
        $this->db->beginTransaction();try{
            $s=$this->query("SELECT COUNT(*) AS total,COALESCE(SUM(booking_status='CANCELLED'),0) AS cancelled,COALESCE(SUM(payment_status='UNPAID' AND booking_status<>'CANCELLED'),0) AS unpaid,COALESCE(SUM(IF(payment_status='PAID',CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.total')) AS DECIMAL(20,0)),0)),0) AS paid_value FROM jingo_orders WHERE $where",$args)->fetch(PDO::FETCH_ASSOC);
            $total=(int)$s['total'];$page=min($numbers['page'],max(1,(int)ceil($total/$numbers['pageSize'])));$offset=($page-1)*$numbers['pageSize'];$limit=$numbers['pageSize'];
            $rows=$this->query("SELECT * FROM jingo_orders WHERE $where ORDER BY created_at DESC,id DESC LIMIT $limit OFFSET $offset",$args)->fetchAll(PDO::FETCH_ASSOC);$data=array_map(fn($row)=>$this->detail($row,true),$rows);$this->db->commit();return ['data'=>$data,'page'=>$page,'pageSize'=>$limit,'summary'=>['total'=>$total,'cancelled'=>(int)$s['cancelled'],'unpaid'=>(int)$s['unpaid'],'paidValue'=>(int)$s['paid_value']]];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function act(string $id,array $input,string $actor):array
    {
        $action=$input['action'] ?? null;$keys=['requestId','version','action','note'];if($action==='INSTRUCTIONS')$keys[]='instructions';if($action==='PAY')$keys=array_merge($keys,['method','amount','reference']);
        if(array_diff(array_keys($input),$keys)||!in_array($action,['INSTRUCTIONS','CANCEL','PAY'],true)||!self::uuid($input['requestId'] ?? null)||!is_int($input['version'] ?? null)||$input['version']<1||$input['version']>=2147483647)self::fail();
        $input['note']=self::text($input,'note',1,1000);if($action==='INSTRUCTIONS')$input['instructions']=self::text($input,'instructions',1,4000);
        if($action==='PAY'){
            if(array_key_exists('method',$input)&&$input['method']===null)self::fail();$input['method']=$input['method'] ?? 'BANK';if(!in_array($input['method'],['BANK','CASH'],true)||!is_int($input['amount'] ?? null)||$input['amount']<0||$input['amount']>15000000000)self::fail();
            $reference=mb_strtoupper(preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',self::text($input,'reference',1,200)) ?? '','UTF-8');if($reference===''||mb_strlen($reference,'UTF-8')>200||preg_match('/[\x00-\x1f\x7f]/u',$reference))self::fail('Mã giao dịch không hợp lệ.');
            if($input['method']==='BANK'&&str_starts_with($reference,'CASH:'))self::fail('Tiền tố CASH: dành cho phiếu thu tiền mặt.');
            $input['reference']=$input['method']==='CASH'?'CASH:'.$input['requestId']:$reference;
        }
        $hash=hash('sha256',json_encode(self::canonical(['id'=>$id,'action'=>$input]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            $row=$this->query('SELECT * FROM jingo_orders WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new HttpError(404,'Không tìm thấy đơn.');
            $prior=$this->query('SELECT request_hash FROM jingo_order_actions WHERE id=?',[$input['requestId']])->fetchColumn();if($prior!==false){if(!hash_equals($prior,$hash))throw new HttpError(409,'Mã thao tác đã dùng.');$result=$this->get($id);$this->db->commit();return $result;}
            if((int)$row['version']!==$input['version'])throw new HttpError(409,'Đơn đã thay đổi. Tải lại trước khi xử lý.');if($row['booking_status']==='CANCELLED')throw new HttpError(409,'Đơn đã hủy.');
            if($action==='CANCEL'){
                if($row['payment_status']!=='UNPAID')throw new HttpError(409,'Đơn đã thu tiền hoặc ghi công nợ cần quy trình hoàn riêng; không thể hủy trực tiếp.');
                (new Inventory($this->db))->release($id);(new Vouchers($this->db))->release($id);$this->query("UPDATE jingo_orders SET booking_status='CANCELLED',version=version+1 WHERE id=?",[$id]);
            }elseif($action==='PAY'){
                if($row['booking_status']!=='CONFIRMED'||$row['payment_status']!=='UNPAID')throw new HttpError(409,'Chỉ ghi nhận thu tiền cho đơn đã đặt và chưa thanh toán.');
                $snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);if($input['amount']!==$snapshot['total'])self::fail('Số tiền phải đúng tổng đơn. Thanh toán thiếu/thừa cần xử lý riêng.');
                $referenceHash=hash('sha256',$input['reference']);$this->query("INSERT INTO jingo_payment_references(reference_hash,reference,kind,entity_id,amount) VALUES (?,?,'ORDER_PAYMENT',?,?)",[$referenceHash,$input['reference'],$id,$input['amount']]);
                $hex=bin2hex(random_bytes(16));$paymentId=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
                $this->query('INSERT INTO jingo_order_payments(id,order_id,reference_hash,amount,method,admin_id) VALUES (?,?,?,?,?,?)',[$paymentId,$id,$referenceHash,$input['amount'],$input['method'],$actor]);
                $this->query("UPDATE jingo_orders SET payment_status='PAID',version=version+1 WHERE id=?",[$id]);
            }else{
                if($row['booking_status']!=='CONFIRMED')throw new HttpError(409,'Chỉ cập nhật hướng dẫn cho đơn đã đặt.');$this->query('UPDATE jingo_orders SET instructions=?,version=version+1 WHERE id=?',[$input['instructions'],$id]);
            }
            $this->query('INSERT INTO jingo_order_actions(id,order_id,request_hash,admin_id,action,order_version,data) VALUES (?,?,?,?,?,?,?)',[$input['requestId'],$id,$hash,$actor,$action,$input['version']+1,json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $result=$this->get($id);$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,$action==='PAY'?'Mã giao dịch hoặc thao tác đã được ghi nhận. Kiểm tra lại trước khi thu.':'Mã thao tác đã dùng.');throw $e;}
    }
}
