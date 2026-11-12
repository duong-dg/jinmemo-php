<?php
declare(strict_types=1);

final class Inventory
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function id(mixed $value,bool $empty=false):string{if(!is_string($value)||!($empty&&$value==='')&&!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$value))throw new HttpError(400,'Mã sản phẩm/gói/suất không hợp lệ.');return $value;}
    private static function number(mixed $value,int $max):int{if(!is_int($value)||$value<0||$value>$max)throw new HttpError(400,'Số tồn hoặc phiên bản không hợp lệ.');return $value;}
    private static function dates(string $from,string $to):array
    {
        Pricing::validateDate($from);Pricing::validateDate($to);$start=new DateTimeImmutable($from.'T00:00:00Z');$end=new DateTimeImmutable($to.'T00:00:00Z');if($from>$to||$end->getTimestamp()-$start->getTimestamp()>366*86400)throw new HttpError(400,'Khoảng ngày tối đa 367 ngày.');
        $days=[];for($day=$start;$day<=$end;$day=$day->modify('+1 day'))$days[]=$day->format('Y-m-d');return $days;
    }
    public static function usage(array $rows,string $variant,string $slot):int
    {
        $total=0;foreach($rows as $row)if(!$row['released']&&($row['variant_id']===''||$row['variant_id']===$variant)&&($slot===''||$row['slot_id']===''||$row['slot_id']===$slot))$total+=(int)$row['quantity'];return $total;
    }
    private function product(string $id,bool $lock=false):array
    {
        $raw=$this->query('SELECT data FROM jingo_products WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->fetchColumn();if($raw===false)throw new HttpError(404,'Sản phẩm không tồn tại.');return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    private function scope(array $product,string $variantId,string $slotId):void
    {
        $variant=null;foreach($product['variants'] as $v)if($v['id']===$variantId)$variant=$v;if(!$variant)throw new HttpError(400,'Gói vé không tồn tại.');
        if($slotId!==''){foreach($variant['timeSlots'] ?? [] as $slot)if($slot['id']===$slotId)return;throw new HttpError(400,'Suất giờ không tồn tại.');}
    }
    private function config(string $productId,?int $expected=null):array
    {
        $row=$this->query('SELECT enabled,version FROM jingo_inventory_configs WHERE product_id=?',[$productId])->fetch(PDO::FETCH_ASSOC);$c=['enabled'=>$row?(bool)$row['enabled']:false,'version'=>$row?(int)$row['version']:0];if($expected!==null&&$expected!==$c['version'])throw new HttpError(409,'Tồn vé đã thay đổi. Tải lại trước khi lưu.');return $c;
    }
    private function bump(string $productId,array $config,string $actor,string $action):array
    {
        $this->query('INSERT INTO jingo_inventory_configs(product_id,enabled,version) VALUES (?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),version=VALUES(version)',[$productId,(int)$config['enabled'],$config['version']+1]);
        $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,$action,$productId]);return ['enabled'=>$config['enabled'],'version'=>$config['version']+1];
    }
    public function availability(string $productId,string $variantId,string $date,string $slotId='',bool $lock=false):array
    {
        if($lock&&!$this->db->inTransaction())throw new LogicException('Locking stock checks require caller transaction.');
        Pricing::validateDate($date);$suffix=$lock?' FOR UPDATE':'';
        $enabled=$this->query('SELECT enabled FROM jingo_inventory_configs WHERE product_id=?'.$suffix,[$productId])->fetchColumn();if(!$enabled)return ['limited'=>false,'configured'=>true,'remaining'=>null,'lowStock'=>false];
        $buckets=$this->query('SELECT slot_id,capacity,low_stock_threshold FROM jingo_inventory_buckets WHERE product_id=? AND variant_id=? AND visit_date=?'.$suffix,[$productId,$variantId,$date])->fetchAll(PDO::FETCH_ASSOC);
        $selected=array_values(array_filter($buckets,fn($b)=>$b['slot_id']===''||$b['slot_id']===$slotId));if(!$selected)return ['limited'=>true,'configured'=>false,'remaining'=>0,'lowStock'=>false];
        $rows=$this->query('SELECT * FROM jingo_inventory_allocations WHERE product_id=? AND visit_date=? AND released=0'.$suffix,[$productId,$date])->fetchAll(PDO::FETCH_ASSOC);$remaining=PHP_INT_MAX;$low=false;
        foreach($selected as $b){$free=max(0,(int)$b['capacity']-self::usage($rows,$variantId,$b['slot_id']));$remaining=min($remaining,$free);$low=$low||$free<=(int)$b['low_stock_threshold'];}return ['limited'=>true,'configured'=>true,'remaining'=>$remaining,'lowStock'=>$low];
    }
    public function check(string $productId,string $variantId,string $date,string $slotId,int $quantity,bool $lock=false):array
    {
        $stock=$this->availability($productId,$variantId,$date,$slotId,$lock);if($stock['limited']&&$quantity>$stock['remaining'])throw new HttpError(409,$stock['configured']?'Chỉ còn '.$stock['remaining'].' suất cho ngày / khung giờ này. Vui lòng giảm số khách hoặc chọn ngày khác.':'Ngày / khung giờ này chưa được mở tồn vé. Vui lòng chọn ngày khác.');return $stock;
    }
    public function configure(array $input,string $actor):array
    {
        if(array_diff(array_keys($input),['productId','version','enabled'])||!is_bool($input['enabled'] ?? null))throw new HttpError(400,'Cấu hình tồn vé không hợp lệ.');$id=self::id($input['productId'] ?? null);$version=self::number($input['version'] ?? null,2147483646);$this->db->beginTransaction();
        try{$this->product($id,true);$config=$this->config($id,$version);if($input['enabled']){$rows=$this->query('SELECT * FROM jingo_inventory_allocations WHERE product_id=? AND released=0',[$id])->fetchAll(PDO::FETCH_ASSOC);foreach($this->query('SELECT * FROM jingo_inventory_buckets WHERE product_id=?',[$id])->fetchAll(PDO::FETCH_ASSOC) as $b)if(self::usage(array_filter($rows,fn($r)=>$r['visit_date']===$b['visit_date']),$b['variant_id'],$b['slot_id'])>(int)$b['capacity'])throw new HttpError(409,'Có ngày đã nhận đơn vượt tồn. Tăng tổng tồn trước khi bật giới hạn.');}$config['enabled']=$input['enabled'];$result=$this->bump($id,$config,$actor,'inventory.configure');$this->db->commit();return $result;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function capacity(array $input,string $actor):array
    {
        if(array_diff(array_keys($input),['productId','version','variantId','slotId','startDate','endDate','weekdays','capacity','lowStockThreshold']))throw new HttpError(400,'Cấu hình tồn vé không hợp lệ.');
        foreach(['slotId','weekdays','lowStockThreshold'] as $key)if(array_key_exists($key,$input)&&$input[$key]===null)throw new HttpError(400,'Cấu hình tồn không được null.');
        $id=self::id($input['productId'] ?? null);$variant=self::id($input['variantId'] ?? null);$slot=self::id($input['slotId'] ?? '',true);$version=self::number($input['version'] ?? null,2147483646);$capacity=self::number($input['capacity'] ?? null,1000000);$threshold=self::number($input['lowStockThreshold'] ?? 5,1000000);$weekdays=$input['weekdays'] ?? [1,2,3,4,5,6,7];
        if(!is_array($weekdays)||!array_is_list($weekdays)||!count($weekdays)||count($weekdays)>7)throw new HttpError(400,'Chọn thứ áp dụng.');foreach($weekdays as $day)if(!is_int($day)||$day<1||$day>7)throw new HttpError(400,'Thứ không hợp lệ.');
        if(!is_string($input['startDate'] ?? null)||!is_string($input['endDate'] ?? null))throw new HttpError(400,'Chọn khoảng ngày hợp lệ.');$days=array_values(array_filter(self::dates($input['startDate'],$input['endDate']),fn($date)=>in_array((int)(new DateTimeImmutable($date.'T00:00:00Z'))->format('N'),$weekdays,true)));if(!$days)throw new HttpError(400,'Không có ngày nào khớp các thứ được chọn.');
        $this->db->beginTransaction();try{$product=$this->product($id,true);$this->scope($product,$variant,$slot);$config=$this->config($id,$version);$rows=$this->query('SELECT * FROM jingo_inventory_allocations WHERE product_id=? AND released=0',[$id])->fetchAll(PDO::FETCH_ASSOC);
            foreach($days as $date){$used=self::usage(array_filter($rows,fn($r)=>$r['visit_date']===$date),$variant,$slot);if($used>$capacity)throw new HttpError(409,'Ngày '.$date.' đã có '.$used.' khách. Tổng tồn không được nhỏ hơn số đã đặt.');}
            foreach($days as $date)$this->query('INSERT INTO jingo_inventory_buckets(product_id,variant_id,visit_date,slot_id,capacity,low_stock_threshold) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE capacity=VALUES(capacity),low_stock_threshold=VALUES(low_stock_threshold)',[$id,$variant,$date,$slot,$capacity,$threshold]);$result=$this->bump($id,$config,$actor,'inventory.capacity');$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function read(string $id,string $from,string $to):array
    {
        self::id($id);self::dates($from,$to);$this->db->beginTransaction();try{$this->product($id);$config=$this->config($id);$allocations=$this->query('SELECT * FROM jingo_inventory_allocations WHERE product_id=? AND visit_date>=? AND visit_date<=? AND released=0',[$id,$from,$to])->fetchAll(PDO::FETCH_ASSOC);$rows=$this->query('SELECT * FROM jingo_inventory_buckets WHERE product_id=? AND visit_date>=? AND visit_date<=? ORDER BY visit_date,variant_id,slot_id',[$id,$from,$to])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$b){$b['capacity']=(int)$b['capacity'];$b['low_stock_threshold']=(int)$b['low_stock_threshold'];$b['used']=self::usage(array_filter($allocations,fn($r)=>$r['visit_date']===$b['visit_date']),$b['variant_id'],$b['slot_id']);$b['remaining']=max(0,$b['capacity']-$b['used']);$b['lowStock']=$b['remaining']<=$b['low_stock_threshold'];$b['pending']=null;}unset($b);$result=array_merge($config,['rows'=>$rows,'legacy'=>[],'orderDetailsAvailable'=>false]);$this->db->commit();return $result;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function allocate(string $orderId,string $productId,string $variantId,string $date,string $slotId,int $quantity):void
    {
        if(!$this->db->inTransaction())throw new LogicException('Allocation requires caller transaction.');if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$orderId)||$quantity<1||$quantity>150)throw new HttpError(400,'Phân bổ tồn không hợp lệ.');$p=$this->product($productId,true);$this->scope($p,$variantId,$slotId);$this->check($productId,$variantId,$date,$slotId,$quantity,true);$this->query('INSERT INTO jingo_inventory_allocations(order_id,product_id,variant_id,visit_date,slot_id,quantity) VALUES (?,?,?,?,?,?)',[$orderId,$productId,$variantId,$date,$slotId,$quantity]);
    }
    public function release(string $orderId):void
    {
        if(!$this->db->inTransaction())throw new LogicException('Release requires caller transaction.');$id=$this->query('SELECT product_id FROM jingo_inventory_allocations WHERE order_id=?',[$orderId])->fetchColumn();if($id===false)return;$this->product($id,true);$this->query('UPDATE jingo_inventory_allocations SET released=1,version=version+1 WHERE order_id=? AND released=0',[$orderId]);
    }
}
