<?php
declare(strict_types=1);
require_once __DIR__.'/SupplyLots.php';
require_once __DIR__.'/SupplyAllocation.php';
require_once __DIR__.'/PosAccess.php';

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
    public function createPos(array $input,string $actor,?string $staffId=null):array
    {
        $counter=$input['counterId'] ?? null;unset($input['counterId']);
        if(!is_string($counter)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$counter)||array_key_exists('voucherCode',$input))self::fail('Chọn quầy hợp lệ; voucher chỉ dùng cho đơn Website.');
        $created=$this->create($input,['counterId'=>$counter,'adminId'=>$actor]+($staffId!==null?['staffId'=>$staffId]:[]));
        return $this->get((string)$this->query('SELECT id FROM jingo_orders WHERE code=?',[$created['code']])->fetchColumn());
    }
    public function create(array $input,?array $pos=null):array
    {
        $input=self::validate($input);if($pos&&isset($input['voucherCode']))self::fail('Voucher chỉ dùng cho đơn Website.');$hash=hash('sha256',json_encode(self::canonical($pos?['pos'=>$pos,'input'=>$input]:$input),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        // READ COMMITTED lets a second worker observe the first worker's replay after the product lock.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            if(isset($pos['staffId'])){$this->query('SELECT id FROM jingo_counters WHERE id=? FOR UPDATE',[$pos['counterId']]);PosAccess::live($this->db,$pos['staffId'],$pos['counterId'],'SELL');}
            if($prior=$this->prior($input,$hash)){$this->db->commit();return $prior;}
            if($pos){$counter=$this->query('SELECT data FROM jingo_counters WHERE id=? FOR UPDATE',[$pos['counterId']])->fetchColumn();if($counter===false||json_decode($counter,true,512,JSON_THROW_ON_ERROR)['status']!=='ACTIVE')self::fail('Chọn quầy đang hoạt động.');}
            if(!$this->query('SELECT id FROM jingo_products WHERE id=? FOR UPDATE',[$input['productId']])->fetchColumn())throw new HttpError(404,'Không tìm thấy sản phẩm.');
            if($prior=$this->prior($input,$hash)){$this->db->commit();return $prior;}
            $p=(new Products($this->db))->find($input['productId'],false,false,$input['locale']);if($p['demo'] ?? false)self::fail('Sản phẩm thử nghiệm không nhận đơn.');
            if($input['locale']==='en'){$p['name']=$p['english']['name'];foreach($p['variants'] as &$v)foreach($p['english']['variants'] as $t)if($v['id']===$t['id']){$v['name']=$t['name'];break;}unset($v);}
            if($input['date']<(new DateTimeImmutable('now',new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d'))self::fail('Ngày sử dụng đã qua.');
            $quote=$pos?Pricing::calculate($p,$input['variantId'],$input['date'],$input['quantities'],$p['rules'],$p['closures'],$input['slotId'] ?? null):Pricing::online($p,$input['variantId'],$input['date'],$input['quantities'],$p['rules'],$p['closures'],$input['slotId'] ?? null);
            $voucher=$input['voucherCode'] ?? '';Pricing::checkVoucher($quote,$voucher);$discount=null;
            if($voucher!==''){$discount=(new Vouchers($this->db))->discount($voucher,$quote['total'],['productId'=>$p['id'],'variantId'=>$input['variantId'],'date'=>$input['date']],true);$quote['discount']=['code'=>$discount['code'],'amount'=>$discount['amount'],'subtotal'=>$discount['subtotal']];$quote['total']-=$discount['amount'];}
            if($quote['total']!==$input['expectedTotal'])throw new HttpError(409,'Giá đã thay đổi. Vui lòng xem lại báo giá.');
            $quantity=array_sum(array_column($quote['lines'],'quantity'));if($quantity<1||$quantity>150)self::fail('Chọn từ 1 đến 150 khách.');
            $hex=bin2hex(random_bytes(16));$id=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);$code='JG'.strtoupper(bin2hex(random_bytes(5)));
            $snapshot=$quote+['productId'=>$p['id'],'productName'=>$p['name'],'variantId'=>$input['variantId'],'date'=>$input['date'],'locale'=>$input['locale'],'demo'=>false];foreach($p['variants'] as $v)if($v['id']===$input['variantId']){$snapshot['variantName']=$v['name'];$snapshot['conditions']=$v['conditions'] ?? '';}
            $this->query('INSERT INTO jingo_orders(id,code,request_id,request_hash,access_hash,snapshot,contact,instructions) VALUES (?,?,?,?,?,?,?,?)',[$id,$code,$input['requestId'],$hash,hash('sha256',$pos?bin2hex(random_bytes(32)):$input['accessKey']),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($input['contact'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'']);
            (new Inventory($this->db))->allocate($id,$p['id'],$input['variantId'],$input['date'],$quote['timeSlot']['id'] ?? '',$quantity);if($discount)(new Vouchers($this->db))->allocate($id,$discount['id']);
            if($pos){$this->query('INSERT INTO jingo_pos_orders(order_id,counter_id,admin_id) VALUES (?,?,?)',[$id,$pos['counterId'],$pos['adminId']]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$pos['adminId'],'pos.order-create',$id]);}
            $this->db->commit();return ['code'=>$code,'accessKey'=>$input['accessKey']];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062){if($prior=$this->prior($input,$hash))return $prior;throw new HttpError(409,'Trùng mã đơn. Vui lòng thử lại.');}throw $e;}
    }
    private function detail(array $row,bool $admin):array
    {
        $pos=$admin?$this->query('SELECT p.counter_id,c.data FROM jingo_pos_orders p JOIN jingo_counters c ON c.id=p.counter_id WHERE p.order_id=?',[$row['id']])->fetch(PDO::FETCH_ASSOC):false;
        $data=['id'=>$row['id'],'code'=>$row['code'],'snapshot'=>json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR),'bookingStatus'=>$row['booking_status'],'paymentStatus'=>$row['payment_status'],'instructions'=>$row['instructions'],'version'=>(int)$row['version'],'createdAt'=>str_replace(' ','T',$row['created_at']).'Z','fulfillmentStatus'=>'PENDING','tickets'=>[]];if($admin){$data['contact']=json_decode($row['contact'],true,512,JSON_THROW_ON_ERROR);$data['channel']='WEB';}
        if($admin){$data['channel']=$pos?'POS':'WEB';$data['counter']=$pos?['id'=>$pos['counter_id'],'name'=>json_decode($pos['data'],true,512,JSON_THROW_ON_ERROR)['name']]:null;}
        foreach($this->query('SELECT id,code,kind,covers,note,created_at FROM jingo_order_tickets WHERE order_id=? ORDER BY created_at,id',[$row['id']])->fetchAll(PDO::FETCH_ASSOC) as $ticket)$data['tickets'][]=['id'=>$ticket['id'],'code'=>$ticket['code'],'kind'=>$ticket['kind'],'covers'=>(int)$ticket['covers'],'note'=>$ticket['note'],'createdAt'=>str_replace(' ','T',$ticket['created_at']).'Z'];$data['deliveredGuests']=array_sum(array_column($data['tickets'],'covers'));$data['totalGuests']=array_sum(array_column($data['snapshot']['lines'],'quantity'));$data['fulfillmentStatus']=$data['deliveredGuests']>=$data['totalGuests']?'DELIVERED':($data['deliveredGuests']>0?'PARTIAL':'PENDING');return $data;
    }
    public function lookup(array $input):array
    {
        if(array_diff(array_keys($input),['code','accessKey','email'])||!is_string($input['code'] ?? null)||!preg_match('/^J[GM][A-F0-9]{10}$/D',$input['code']))self::fail();
        if(array_key_exists('accessKey',$input)&&(!is_string($input['accessKey'])||!preg_match('/^[a-f0-9]{64}$/iD',$input['accessKey'])))self::fail();
        if(array_key_exists('email',$input)&&(!is_string($input['email'])||strlen($input['email'])>200||!filter_var($input['email'],FILTER_VALIDATE_EMAIL)))self::fail();if(!isset($input['accessKey'])&&!isset($input['email']))self::fail();
        $row=$this->query('SELECT * FROM jingo_orders WHERE code=?',[$input['code']])->fetch(PDO::FETCH_ASSOC);$ok=false;if($row){$contact=json_decode($row['contact'],true,512,JSON_THROW_ON_ERROR);$ok=isset($input['accessKey'])?hash_equals($row['access_hash'],hash('sha256',$input['accessKey'])):strtolower(trim($contact['email']))===strtolower(trim($input['email']));}if(!$ok)throw new HttpError(404,'Không tìm thấy đơn.');$data=$this->detail($row,false);if(!isset($input['accessKey'])&&$this->query('SELECT order_id FROM jingo_pos_orders WHERE order_id=?',[$row['id']])->fetchColumn()){$data['tickets']=[];$data['ticketAccess']='EMAIL_LINK';}return $data;
    }
    public function get(string $id):array
    {
        $row=$this->query('SELECT * FROM jingo_orders WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new HttpError(404,'Không tìm thấy đơn.');$result=$this->detail($row,true);$archive=$this->query('SELECT reason,created_at FROM jingo_order_archives WHERE order_id=?',[$id])->fetch(PDO::FETCH_ASSOC);$result['archived']=$archive!==false;$result['archiveReason']=$archive['reason'] ?? '';$result['history']=[];
        foreach($this->query('SELECT id,admin_id,action,data,created_at FROM jingo_order_actions WHERE order_id=? ORDER BY order_version',[$id])->fetchAll(PDO::FETCH_ASSOC) as $a)$result['history'][]=['id'=>$a['id'],'adminId'=>$a['admin_id'],'action'=>$a['action'],'data'=>json_decode($a['data'],true,512,JSON_THROW_ON_ERROR),'createdAt'=>str_replace(' ','T',$a['created_at']).'Z'];
        if($result['channel']==='POS')$result['openShiftId']=$this->query('SELECT shift_id FROM jingo_pos_open_shifts WHERE counter_id=?',[$result['counter']['id']])->fetchColumn()?:null;
        $result['payments']=[];foreach($this->query('SELECT p.id,p.amount,p.method,p.admin_id,p.created_at,r.reference,s.shift_id FROM jingo_order_payments p JOIN jingo_payment_references r ON r.reference_hash=p.reference_hash LEFT JOIN jingo_pos_shift_receipts s ON s.payment_id=p.id WHERE p.order_id=? ORDER BY p.created_at,p.id',[$id])->fetchAll(PDO::FETCH_ASSOC) as $p)$result['payments'][]=['id'=>$p['id'],'amount'=>(int)$p['amount'],'method'=>$p['method'],'reference'=>$p['reference'],'adminId'=>$p['admin_id'],'shiftId'=>$p['shift_id'],'createdAt'=>str_replace(' ','T',$p['created_at']).'Z'];return $result;
    }
    private static function filters(array $input):array
    {
        if(array_diff(array_keys($input),['q','booking','payment','page','pageSize','archived','dateKind','from','to','channel','counterId']))self::fail('Bộ lọc đơn không hợp lệ.');
        $q=self::text($input+['q'=>''],'q',0,120);$booking=$input['booking'] ?? 'ALL';$payment=$input['payment'] ?? 'ALL';
        if(!in_array($booking,['ALL','REQUESTED','CONFIRMED','CANCELLED'],true)||!in_array($payment,['ALL','UNPAID','PAID','CREDIT'],true))self::fail();
        $numbers=[];foreach(['page'=>1000000,'pageSize'=>100] as $key=>$max){$v=$input[$key] ?? ($key==='page'?1:25);if((!is_int($v)&&(!is_string($v)||!preg_match('/^[1-9][0-9]*$/D',$v)))||(int)$v<1||(int)$v>$max)self::fail();$numbers[$key]=(int)$v;}
        $archived=$input['archived'] ?? '0';if(!in_array($archived,['0','1','true','false'],true))self::fail('Bộ lọc lưu trữ không hợp lệ.');$where=[(in_array($archived,['1','true'],true)?'EXISTS':'NOT EXISTS').'(SELECT 1 FROM jingo_order_archives a WHERE a.order_id=jingo_orders.id)'];$args=[];if($booking!=='ALL'){$where[]='booking_status=?';$args[]=$booking;}if($payment!=='ALL'){$where[]='payment_status=?';$args[]=$payment;}
        $channel=$input['channel'] ?? 'ALL';$counter=$input['counterId'] ?? '';if(!in_array($channel,['ALL','WEB','POS'],true)||!is_string($counter)||$counter!==''&&!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$counter))self::fail('Kênh hoặc quầy không hợp lệ.');if($channel!=='ALL')$where[]=($channel==='POS'?'EXISTS':'NOT EXISTS').'(SELECT 1 FROM jingo_pos_orders po WHERE po.order_id=jingo_orders.id)';if($counter!==''){$where[]='EXISTS(SELECT 1 FROM jingo_pos_orders po WHERE po.order_id=jingo_orders.id AND po.counter_id=?)';$args[]=$counter;}
        if($q!==''){$where[]="LOCATE(LOWER(?),LOWER(CONCAT_WS(' ',code,JSON_UNQUOTE(JSON_EXTRACT(contact,'$.name')),JSON_UNQUOTE(JSON_EXTRACT(contact,'$.email')),JSON_UNQUOTE(JSON_EXTRACT(contact,'$.phone')))))>0";$args[]=$q;}
        $kind=$input['dateKind'] ?? 'CREATED';if(!in_array($kind,['CREATED','VISIT'],true))self::fail('Loại ngày không hợp lệ.');$from=$input['from'] ?? '';$to=$input['to'] ?? '';foreach([$from,$to] as $date){if(!is_string($date))self::fail();if($date!=='')Pricing::validateDate($date);}if($from!==''&&$to!==''&&$from>$to)self::fail('Ngày kết thúc phải từ ngày bắt đầu.');
        foreach([[$from,'>='],[$to,$kind==='VISIT'?'<=':'<']] as [$date,$operator])if($date!==''){if($kind==='VISIT'){$where[]="JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.date')) $operator ?";$args[]=$date;}else{$time=new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Asia/Ho_Chi_Minh'));if($operator==='<')$time=$time->modify('+1 day');$where[]="created_at $operator ?";$args[]=$time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}}
        return [implode(' AND ',$where),$args,$numbers,in_array($archived,['1','true'],true)];
    }
    public function list(array $input):array
    {
        [$where,$args,$numbers]=self::filters($input);
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
            $s=$this->query("SELECT COUNT(*) AS total,COALESCE(SUM(booking_status='CANCELLED'),0) AS cancelled,COALESCE(SUM(payment_status='UNPAID' AND booking_status<>'CANCELLED'),0) AS unpaid,COALESCE(SUM(IF(payment_status='PAID',CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.total')) AS DECIMAL(20,0)),0)),0) AS paid_value FROM jingo_orders WHERE $where",$args)->fetch(PDO::FETCH_ASSOC);
            $total=(int)$s['total'];$page=min($numbers['page'],max(1,(int)ceil($total/$numbers['pageSize'])));$offset=($page-1)*$numbers['pageSize'];$limit=$numbers['pageSize'];
            $rows=$this->query("SELECT * FROM jingo_orders WHERE $where ORDER BY created_at DESC,id DESC LIMIT $limit OFFSET $offset",$args)->fetchAll(PDO::FETCH_ASSOC);$data=array_map(fn($row)=>$this->detail($row,true),$rows);$this->db->commit();return ['data'=>$data,'page'=>$page,'pageSize'=>$limit,'summary'=>['total'=>$total,'cancelled'=>(int)$s['cancelled'],'unpaid'=>(int)$s['unpaid'],'paidValue'=>(int)$s['paid_value']]];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function export(array $input):array
    {
        [$where,$args,,$archived]=self::filters($input);
        // One SELECT is one consistent snapshot; never silently truncate an export.
        $rows=$this->query("SELECT code,created_at,snapshot,contact,booking_status,payment_status,IF(EXISTS(SELECT 1 FROM jingo_pos_orders po WHERE po.order_id=jingo_orders.id),'POS','WEB') AS channel FROM jingo_orders WHERE $where ORDER BY created_at DESC,id DESC LIMIT 10001",$args)->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>10000)throw new HttpError(400,'Có hơn 10.000 đơn phù hợp. Thu hẹp khoảng ngày hoặc bộ lọc rồi xuất lại.');
        return ['rows'=>$rows,'archived'=>$archived];
    }
    public static function csv(array $report):string
    {
        $rows=[['Mã đơn','Ngày tạo (GMT+7)','Khách hàng','Điện thoại','Email','Sản phẩm','Gói vé','Ngày sử dụng','Suất giờ','Người lớn','Trẻ em','Người cao tuổi','Tổng khách','Giá trị đơn (VND)','Trạng thái đơn','Thanh toán','Kênh bán','Lưu trữ','Mã giảm giá','Tiền giảm (VND)','Trước giảm (VND)']];
        $booking=['REQUESTED'=>'Chờ xác nhận','CONFIRMED'=>'Đã đặt vé','CANCELLED'=>'Đã hủy'];$payment=['UNPAID'=>'Chưa thu tiền','PAID'=>'Đã thu tiền','CREDIT'=>'Công nợ CTV'];
        foreach($report['rows'] as $row){$s=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);$c=json_decode($row['contact'],true,512,JSON_THROW_ON_ERROR);$quantities=['ADULT'=>0,'CHILD'=>0,'SENIOR'=>0];$guests=0;foreach($s['lines'] as $line){$type=preg_replace('/^LOCAL_/','',$line['customerType']);if(isset($quantities[$type]))$quantities[$type]+=$line['quantity'];$guests+=$line['quantity'];}$slot=$s['timeSlot'] ?? null;$date=(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');$rows[]=[$row['code'],$date,$c['name'],$c['phone']!==''?"'".$c['phone']:'',$c['email'] ?? '',$s['productName'],$s['variantName'],$s['date'],$slot?$slot['start'].(!empty($slot['end'])?' – '.$slot['end']:''):'',$quantities['ADULT'],$quantities['CHILD'],$quantities['SENIOR'],$guests,$s['total'],$booking[$row['booking_status']] ?? $row['booking_status'],$payment[$row['payment_status']] ?? $row['payment_status'],($row['channel'] ?? 'WEB')==='POS'?'POS':'Website',$report['archived']?'Có':'Không',$s['discount']['code'] ?? '',($s['discount']['amount'] ?? 0)+($s['onlineDiscount']['amount'] ?? 0),$s['onlineDiscount']['subtotal'] ?? $s['discount']['subtotal'] ?? $s['total']];}
        $cell=static function(mixed $v):string{$v=str_replace("\0",'',(string)$v);if(preg_match('/^[\s\x{FEFF}]*[=+@-]|^[\t\r\n]/u',$v))$v="'".$v;return '"'.str_replace('"','""',$v).'"';};
        return "\xEF\xBB\xBF".implode("\r\n",array_map(fn($row)=>implode(',',array_map($cell,$row)),$rows))."\r\n";
    }
    private static function ticketText(mixed $value,int $min,int $max):string
    {
        if(!is_string($value))self::fail();$value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);$length=$value===null?0:strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2;if($value===null||$length<$min||$length>$max||str_contains($value,"\0"))self::fail('Mã vé hoặc ghi chú không hợp lệ.');return $value;
    }
    public function act(string $id,array $input,string $actor,?string $staffId=null):array
    {
        $action=$input['action'] ?? null;$keys=['requestId','version','action','note'];if($action==='DELIVER')$keys[]='tickets';if(in_array($action,['INSTRUCTIONS','CONFIRM'],true))$keys[]='instructions';if($action==='PAY')$keys=array_merge($keys,['method','amount','reference']);
        if(array_diff(array_keys($input),$keys)||!in_array($action,['INSTRUCTIONS','CANCEL','PAY','ARCHIVE','RESTORE','CONFIRM','DELIVER'],true)||!self::uuid($input['requestId'] ?? null)||!is_int($input['version'] ?? null)||$input['version']<1||$input['version']>=2147483647)self::fail();
        $input['note']=self::text($input,'note',1,1000);if(in_array($action,['INSTRUCTIONS','CONFIRM'],true))$input['instructions']=self::text($input,'instructions',1,4000);
        if($action==='PAY'){
            if(array_key_exists('method',$input)&&$input['method']===null)self::fail();$input['method']=$input['method'] ?? 'BANK';if(!in_array($input['method'],['BANK','CASH'],true)||!is_int($input['amount'] ?? null)||$input['amount']<0||$input['amount']>15000000000)self::fail();
            $reference=mb_strtoupper(preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',self::text($input,'reference',1,200)) ?? '','UTF-8');if($reference===''||mb_strlen($reference,'UTF-8')>200||preg_match('/[\x00-\x1f\x7f]/u',$reference))self::fail('Mã giao dịch không hợp lệ.');
            if($input['method']==='BANK'&&str_starts_with($reference,'CASH:'))self::fail('Tiền tố CASH: dành cho phiếu thu tiền mặt.');
            $input['reference']=$input['method']==='CASH'?'CASH:'.$input['requestId']:$reference;
        }
        if($action==='DELIVER'){
            if(!is_array($input['tickets'] ?? null)||!array_is_list($input['tickets'])||!$input['tickets']||count($input['tickets'])>150)self::fail('Nhập từ 1 đến 150 mã vé.');$tickets=[];$codes=[];
            foreach($input['tickets'] as $t){if(!is_array($t)||array_diff(array_keys($t),['code','kind','covers','note'])||!in_array($t['kind'] ?? null,['SUPPLIER_CODE','TOUR_VOUCHER'],true)||!is_int($t['covers'] ?? null)||$t['covers']<1||$t['covers']>150)self::fail();$code=self::ticketText($t['code'] ?? null,1,2000);$note=self::ticketText($t['note'] ?? '',0,1000);$digest=hash('sha256',$code);if(isset($codes[$digest]))self::fail('Danh sách có mã vé trùng.');$codes[$digest]=true;$tickets[]=['code'=>$code,'kind'=>$t['kind'],'covers'=>$t['covers'],'note'=>$note];}$input['tickets']=$tickets;
        }
        $hash=hash('sha256',json_encode(self::canonical(['id'=>$id,'action'=>$input]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            if($action==='PAY'||$staffId!==null){$counter=$this->query('SELECT counter_id FROM jingo_pos_orders WHERE order_id=?',[$id])->fetchColumn();if($counter!==false)$this->query('SELECT id FROM jingo_counters WHERE id=? FOR UPDATE',[$counter]);}
            if($staffId!==null){if($counter===false)throw new HttpError(404,'Không tìm thấy đơn quầy.');$permission=match($action){'PAY'=>'COLLECT','CANCEL'=>'CANCEL','DELIVER'=>'DELIVER',default=>null};if($permission===null)throw new HttpError(403,'Thao tác này chỉ dành cho admin.');PosAccess::live($this->db,$staffId,$counter,$permission);$actor='pos-user:'.$staffId;}
            $row=$this->query('SELECT * FROM jingo_orders WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new HttpError(404,'Không tìm thấy đơn.');
            $prior=$this->query('SELECT request_hash FROM jingo_order_actions WHERE id=?',[$input['requestId']])->fetchColumn();if($prior!==false){if(!hash_equals($prior,$hash))throw new HttpError(409,'Mã thao tác đã dùng.');$result=$this->get($id);$this->db->commit();return $result;}
            if((int)$row['version']!==$input['version'])throw new HttpError(409,'Đơn đã thay đổi. Tải lại trước khi xử lý.');if($row['booking_status']==='CANCELLED'&&!in_array($action,['ARCHIVE','RESTORE'],true))throw new HttpError(409,'Đơn đã hủy.');
            $archived=$this->query('SELECT order_id FROM jingo_order_archives WHERE order_id=?',[$id])->fetchColumn();if($archived&&$action!=='RESTORE')throw new HttpError(409,'Đơn đang lưu trữ. Khôi phục trước khi xử lý.');if(!$archived&&$action==='RESTORE')throw new HttpError(409,'Đơn chưa được lưu trữ.');
            if($action==='ARCHIVE'){
                $this->query('INSERT INTO jingo_order_archives(order_id,archived_by,reason) VALUES (?,?,?)',[$id,$actor,$input['note']]);if($row['payment_status']==='UNPAID'&&$row['booking_status']!=='CANCELLED'&&!$this->query('SELECT id FROM jingo_order_tickets WHERE order_id=? LIMIT 1',[$id])->fetchColumn()){(new SupplyAllocation($this->db))->release($row);(new Inventory($this->db))->release($id);(new Vouchers($this->db))->release($id);$this->query("UPDATE jingo_orders SET booking_status='CANCELLED' WHERE id=?",[$id]);}$this->query('UPDATE jingo_orders SET version=version+1 WHERE id=?',[$id]);
            }elseif($action==='RESTORE'){
                $this->query('DELETE FROM jingo_order_archives WHERE order_id=?',[$id]);$this->query('UPDATE jingo_orders SET version=version+1 WHERE id=?',[$id]);
            }elseif($action==='CONFIRM'){
                if($row['booking_status']!=='REQUESTED')throw new HttpError(409,'Đơn đã được xác nhận.');$this->query("UPDATE jingo_orders SET booking_status='CONFIRMED',instructions=?,version=version+1 WHERE id=?",[$input['instructions'],$id]);
            }elseif($action==='CANCEL'){
                if($row['payment_status']!=='UNPAID')throw new HttpError(409,'Đơn đã thu tiền hoặc ghi công nợ cần quy trình hoàn riêng; không thể hủy trực tiếp.');
                (new SupplyAllocation($this->db))->release($row);(new Inventory($this->db))->release($id);(new Vouchers($this->db))->release($id);$this->query("UPDATE jingo_orders SET booking_status='CANCELLED',version=version+1 WHERE id=?",[$id]);
            }elseif($action==='DELIVER'){
                if($row['booking_status']!=='CONFIRMED'||$row['payment_status']!=='PAID')throw new HttpError(409,'Chỉ giao vé cho đơn đã đặt và đã thu đủ tiền.');$snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);$issued=(int)$this->query('SELECT COALESCE(SUM(covers),0) FROM jingo_order_tickets WHERE order_id=?',[$id])->fetchColumn();if($issued+array_sum(array_column($input['tickets'],'covers'))>array_sum(array_column($snapshot['lines'],'quantity')))self::fail('Số khách được cấp vé vượt số khách trong đơn.');
                require_once __DIR__.'/TicketStock.php';$stock=new TicketStock($this->db);$stock->prepareManual($row,$input['tickets']);(new SupplyAllocation($this->db))->forDelivery($row,$actor);
                foreach($input['tickets'] as $ticket){$hex=bin2hex(random_bytes(16));$ticketId=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);$this->query('INSERT INTO jingo_order_tickets(id,order_id,code,code_hash,kind,covers,note) VALUES (?,?,?,?,?,?,?)',[$ticketId,$id,$ticket['code'],hash('sha256',$ticket['code']),$ticket['kind'],$ticket['covers'],$ticket['note']]);$stock->useManual($id,$ticket['code'],$ticketId);}
                $this->query('UPDATE jingo_orders SET version=version+1 WHERE id=?',[$id]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'order.deliver',$id]);
            }elseif($action==='PAY'){
                if($row['booking_status']!=='CONFIRMED'||$row['payment_status']!=='UNPAID')throw new HttpError(409,'Chỉ ghi nhận thu tiền cho đơn đã đặt và chưa thanh toán.');
                $snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);if($input['amount']!==$snapshot['total'])self::fail('Số tiền phải đúng tổng đơn. Thanh toán thiếu/thừa cần xử lý riêng.');
                $referenceHash=hash('sha256',$input['reference']);$this->query("INSERT INTO jingo_payment_references(reference_hash,reference,kind,entity_id,amount) VALUES (?,?,'ORDER_PAYMENT',?,?)",[$referenceHash,$input['reference'],$id,$input['amount']]);
                $hex=bin2hex(random_bytes(16));$paymentId=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
                $this->query('INSERT INTO jingo_order_payments(id,order_id,reference_hash,amount,method,admin_id) VALUES (?,?,?,?,?,?)',[$paymentId,$id,$referenceHash,$input['amount'],$input['method'],$actor]);
                if($counter!==false){$shift=$this->query('SELECT shift_id FROM jingo_pos_open_shifts WHERE counter_id=?',[$counter])->fetchColumn();if($shift!==false)$this->query('INSERT INTO jingo_pos_shift_receipts(payment_id,shift_id) VALUES (?,?)',[$paymentId,$shift]);}
                $this->query("UPDATE jingo_orders SET payment_status='PAID',version=version+1 WHERE id=?",[$id]);
            }else{
                if($row['booking_status']!=='CONFIRMED')throw new HttpError(409,'Chỉ cập nhật hướng dẫn cho đơn đã đặt.');$this->query('UPDATE jingo_orders SET instructions=?,version=version+1 WHERE id=?',[$input['instructions'],$id]);
            }
            $this->query('INSERT INTO jingo_order_actions(id,order_id,request_hash,admin_id,action,order_version,data) VALUES (?,?,?,?,?,?,?)',[$input['requestId'],$id,$hash,$actor,$action,$input['version']+1,json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $result=$this->get($id);$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,$action==='PAY'?'Mã giao dịch hoặc thao tác đã được ghi nhận. Kiểm tra lại trước khi thu.':'Mã thao tác đã dùng.');throw $e;}
    }
}
