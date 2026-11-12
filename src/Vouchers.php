<?php
declare(strict_types=1);

final class Vouchers
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function fail(string $text):never{throw new HttpError(400,$text);}
    private static function text(array $data,string $key,int $max,bool $required=false):string
    {
        $v=$data[$key] ?? ($required?null:'');if(!is_string($v))self::fail('Trường '.$key.' không hợp lệ.');$v=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$v);if($v===null||($required&&$v==='')||strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2>$max)self::fail('Trường '.$key.' không hợp lệ.');return $v;
    }
    private static function number(mixed $v,int $min,int $max):int{if((!is_int($v)&&!(is_float($v)&&is_finite($v)&&floor($v)===$v))||$v<$min||$v>$max)self::fail('Giá trị hoặc giới hạn ngoài phạm vi cho phép.');return (int)$v;}
    private static function id(mixed $v,bool $uuid=false):string{if(!is_string($v)||!preg_match($uuid?'/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD':'/^[a-zA-Z0-9_-]{1,80}$/D',$v))self::fail('Mã tham chiếu không hợp lệ.');return $v;}
    public static function validate(array $input):array
    {
        if(array_diff(array_keys($input),['id','code','name','discountType','value','minSubtotal','maxDiscount','startDate','endDate','maxUses','enabled','notes','version','scope','targets','visitFrom','visitTo','visitWeekdays']))self::fail('Có trường voucher không được hỗ trợ.');
        foreach(['scope','targets','visitWeekdays','notes'] as $key)if(array_key_exists($key,$input)&&$input[$key]===null)self::fail('Trường '.$key.' không được null.');
        $v=['id'=>self::id($input['id'] ?? null,true),'code'=>strtoupper(self::text($input,'code',40,true)),'name'=>self::text($input,'name',150,true),'discountType'=>$input['discountType'] ?? null,'value'=>self::number($input['value'] ?? null,1,100000000),'minSubtotal'=>self::number($input['minSubtotal'] ?? null,0,15000000000),'version'=>self::number($input['version'] ?? null,0,2147483646),'enabled'=>$input['enabled'] ?? null,'notes'=>self::text($input,'notes',2000),'scope'=>$input['scope'] ?? 'ALL','targets'=>[],'visitFrom'=>null,'visitTo'=>null,'visitWeekdays'=>$input['visitWeekdays'] ?? []];
        if(!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/D',$v['code'])||!in_array($v['discountType'],['PERCENT','FIXED'],true)||!is_bool($v['enabled'])||!in_array($v['scope'],['ALL','PRODUCTS','VARIANTS'],true))self::fail('Mã, loại giảm hoặc trạng thái voucher không hợp lệ.');
        foreach(['maxDiscount'=>15000000000,'maxUses'=>1000000] as $key=>$max){if(!array_key_exists($key,$input))self::fail('Thiếu giới hạn '.$key.'.');$v[$key]=$input[$key]===null?null:self::number($input[$key],1,$max);}
        foreach(['startDate','endDate','visitFrom','visitTo'] as $key){if(str_starts_with($key,'visit')&&($input[$key] ?? null)===null)continue;if(!is_string($input[$key] ?? null))self::fail('Ngày voucher không hợp lệ.');Pricing::validateDate($input[$key]);$v[$key]=$input[$key];}
        if($v['startDate']>$v['endDate']||($v['visitFrom']!==null&&$v['visitTo']!==null&&$v['visitFrom']>$v['visitTo']))self::fail('Khoảng ngày voucher không hợp lệ.');
        if($v['discountType']==='PERCENT'&&$v['value']>100)self::fail('Mức giảm phần trăm tối đa 100%.');if($v['discountType']==='FIXED'&&$v['maxDiscount']!==null)self::fail('Mức giảm tối đa chỉ dùng cho phần trăm.');
        if(array_key_exists('visitWeekdays',$input)&&$input['visitWeekdays']===null)self::fail('Thứ áp dụng không hợp lệ.');if(!is_array($v['visitWeekdays'])||!array_is_list($v['visitWeekdays'])||count($v['visitWeekdays'])>7)self::fail('Thứ áp dụng không hợp lệ.');$v['visitWeekdays']=array_map(fn($day)=>self::number($day,0,6),$v['visitWeekdays']);if(count(array_unique($v['visitWeekdays']))!==count($v['visitWeekdays']))self::fail('Thứ áp dụng bị lặp.');
        $targets=$input['targets'] ?? [];if(array_key_exists('targets',$input)&&$input['targets']===null||!is_array($targets)||!array_is_list($targets)||count($targets)>200)self::fail('Danh sách áp dụng không hợp lệ.');$seen=[];
        foreach($targets as $t){if(!is_array($t)||array_diff(array_keys($t),['productId','variantId']))self::fail('Mục áp dụng không hợp lệ.');$item=['productId'=>self::id($t['productId'] ?? null)];if(array_key_exists('variantId',$t))$item['variantId']=self::id($t['variantId']);$key=$item['productId'].':'.($item['variantId'] ?? '');if(isset($seen[$key]))self::fail('Sản phẩm/gói bị lặp.');$seen[$key]=true;$v['targets'][]=$item;}
        if(($v['scope']==='ALL'&&$v['targets'])||($v['scope']!=='ALL'&&!$v['targets']))self::fail('Chọn danh sách đúng với phạm vi áp dụng.');foreach($v['targets'] as $t)if(($v['scope']==='PRODUCTS'&&isset($t['variantId']))||($v['scope']==='VARIANTS'&&!isset($t['variantId'])))self::fail('Danh sách không khớp phạm vi sản phẩm/gói.');return $v;
    }
    public static function applies(array $voucher,?array $selection):bool
    {
        $scope=$voucher['scope'] ?? 'ALL';if($scope==='ALL')return true;if(!$selection||empty($voucher['targets']))return false;
        foreach($voucher['targets'] as $target)if(($target['productId'] ?? null)===$selection['productId']&&($scope==='PRODUCTS'&&!isset($target['variantId'])||$scope==='VARIANTS'&&($target['variantId'] ?? null)===$selection['variantId']))return true;return false;
    }
    public static function visitApplies(array $voucher,?string $date):bool
    {
        if(empty($voucher['visitFrom'])&&empty($voucher['visitTo'])&&empty($voucher['visitWeekdays']))return true;if($date===null)return false;try{Pricing::validateDate($date);}catch(HttpError){return false;}
        $day=(int)(new DateTimeImmutable($date.'T00:00:00Z'))->format('w');return (empty($voucher['visitFrom'])||$date>=$voucher['visitFrom'])&&(empty($voucher['visitTo'])||$date<=$voucher['visitTo'])&&(empty($voucher['visitWeekdays'])||in_array($day,$voucher['visitWeekdays'],true));
    }
    public function list():array
    {
        $rows=[];foreach($this->query('SELECT data,version FROM jingo_vouchers ORDER BY code')->fetchAll(PDO::FETCH_ASSOC) as $r){$v=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);$rows[]=array_merge(['visitFrom'=>null,'visitTo'=>null,'visitWeekdays'=>[],'scope'=>'ALL','targets'=>[]],$v,['version'=>(int)$r['version']]);}return $rows;
    }
    public function usage():array{$usage=[];foreach($this->query('SELECT voucher_id,COUNT(*) AS total FROM jingo_voucher_uses WHERE released=0 GROUP BY voucher_id')->fetchAll(PDO::FETCH_ASSOC) as $row)$usage[$row['voucher_id']]=(int)$row['total'];return $usage;}
    public function discount(string $raw,int $subtotal,?array $selection=null,bool $lock=false,?string $today=null):array
    {
        if($lock&&!$this->db->inTransaction())throw new LogicException('Voucher locking requires caller transaction.');$code=strtoupper(trim($raw));if(!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/D',$code))self::fail('Mã giảm giá không hợp lệ.');$row=$this->query('SELECT id,data FROM jingo_vouchers WHERE code=?'.($lock?' FOR UPDATE':''),[$code])->fetch(PDO::FETCH_ASSOC);$v=$row?json_decode($row['data'],true,512,JSON_THROW_ON_ERROR):null;$today??=(new DateTimeImmutable('now',new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
        if(!$v||!$v['enabled']||$today<$v['startDate']||$today>$v['endDate'])self::fail('Mã giảm giá không tồn tại, chưa có hiệu lực hoặc đã hết hạn.');if(!self::applies($v,$selection))self::fail('Mã giảm giá không áp dụng cho sản phẩm hoặc gói vé đang chọn.');if(!self::visitApplies($v,$selection['date'] ?? null))self::fail('Mã giảm giá không áp dụng cho ngày sử dụng vé đã chọn.');if($subtotal<$v['minSubtotal'])self::fail('Đơn chưa đạt giá trị tối thiểu của mã giảm giá.');if($subtotal===0)self::fail('Mã giảm giá không áp dụng cho đơn miễn phí.');
        $used=$lock?count($this->query('SELECT order_id FROM jingo_voucher_uses WHERE voucher_id=? AND released=0 FOR UPDATE',[$row['id']])->fetchAll(PDO::FETCH_ASSOC)):(int)$this->query('SELECT COUNT(*) FROM jingo_voucher_uses WHERE voucher_id=? AND released=0',[$row['id']])->fetchColumn();if($v['maxUses']!==null&&$used>=$v['maxUses'])throw new HttpError(409,'Mã giảm giá đã hết lượt sử dụng.');
        $amount=min($subtotal,$v['discountType']==='PERCENT'?intdiv($subtotal*$v['value'],100):$v['value'],$v['discountType']==='PERCENT'&&$v['maxDiscount']!==null?$v['maxDiscount']:$subtotal);return ['id'=>$row['id'],'code'=>$code,'amount'=>$amount,'subtotal'=>$subtotal];
    }
    public function save(array $input,string $actor):array
    {
        $v=self::validate($input);$this->db->beginTransaction();try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');
            foreach($v['targets'] as $t){$raw=$this->query('SELECT data FROM jingo_products WHERE id=?',[$t['productId']])->fetchColumn();if(!$raw)self::fail('Sản phẩm đã chọn không tồn tại.');$p=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(isset($t['variantId'])&&!array_filter($p['variants'],fn($variant)=>$variant['id']===$t['variantId']))self::fail('Gói đã chọn không tồn tại.');}
            $row=$this->query('SELECT code,version FROM jingo_vouchers WHERE id=? FOR UPDATE',[$v['id']])->fetch(PDO::FETCH_ASSOC);if(($row?(int)$row['version']:0)!==$v['version'])throw new HttpError(409,'Voucher đã thay đổi. Tải lại trước khi lưu.');if($row&&$row['code']!==$v['code'])self::fail('Không đổi mã đã tạo. Tạo voucher mới nếu cần mã khác.');
            $used=count($this->query('SELECT order_id FROM jingo_voucher_uses WHERE voucher_id=? AND released=0 FOR UPDATE',[$v['id']])->fetchAll(PDO::FETCH_ASSOC));if($v['maxUses']!==null&&$used>$v['maxUses'])throw new HttpError(409,'Giới hạn lượt không được nhỏ hơn số đã dùng.');$v['version']++;$json=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($row)$this->query('UPDATE jingo_vouchers SET data=?,version=? WHERE id=?',[$json,$v['version'],$v['id']]);else $this->query('INSERT INTO jingo_vouchers(id,code,data,version) VALUES (?,?,?,?)',[$v['id'],$v['code'],$json,$v['version']]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'voucher.save',$v['id']]);$this->db->commit();return $v;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,'Mã voucher đã tồn tại.');throw $e;}
    }
    public function allocate(string $orderId,string $voucherId):void
    {
        if(!$this->db->inTransaction())throw new LogicException('Voucher allocation requires caller transaction.');self::id($orderId,true);self::id($voucherId,true);$raw=$this->query('SELECT data FROM jingo_vouchers WHERE id=? FOR UPDATE',[$voucherId])->fetchColumn();if(!$raw)throw new HttpError(404,'Voucher không tồn tại.');$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$old=$this->query('SELECT voucher_id,released FROM jingo_voucher_uses WHERE order_id=? FOR UPDATE',[$orderId])->fetch(PDO::FETCH_ASSOC);if($old){if($old['voucher_id']===$voucherId&&!$old['released'])return;throw new HttpError(409,'Đơn đã dùng voucher hoặc đã trả lượt.');}
        $uses=$this->query('SELECT order_id FROM jingo_voucher_uses WHERE voucher_id=? AND released=0 FOR UPDATE',[$voucherId])->fetchAll(PDO::FETCH_ASSOC);if($v['maxUses']!==null&&count($uses)>=$v['maxUses'])throw new HttpError(409,'Mã giảm giá đã hết lượt sử dụng.');$this->query('INSERT INTO jingo_voucher_uses(order_id,voucher_id) VALUES (?,?)',[$orderId,$voucherId]);
    }
    public function release(string $orderId):void
    {
        if(!$this->db->inTransaction())throw new LogicException('Voucher release requires caller transaction.');$id=$this->query('SELECT voucher_id FROM jingo_voucher_uses WHERE order_id=?',[$orderId])->fetchColumn();if($id===false)return;$this->query('SELECT id FROM jingo_vouchers WHERE id=? FOR UPDATE',[$id]);$this->query('UPDATE jingo_voucher_uses SET released=1 WHERE order_id=? AND released=0',[$orderId]);
    }
}
