<?php
declare(strict_types=1);

final class SupplyAllocation
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function uuid(mixed $v):bool{return is_string($v)&&(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$v);}
    private function order(string $id,bool $lock=false):array{$row=$this->query('SELECT * FROM jingo_orders WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new HttpError(404,'Không tìm thấy đơn.');return $row;}
    private function scope(array $order):array
    {
        if(!$this->db->inTransaction())throw new LogicException('Supply writes require a transaction.');if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');$snapshot=json_decode($order['snapshot'],true,512,JSON_THROW_ON_ERROR);$this->query('SELECT id FROM jingo_products WHERE id=? FOR UPDATE',[$snapshot['productId']]);return $snapshot;
    }
    private function entries(string $id):array
    {
        $rows=$this->query('SELECT a.*,l.supplier_id,l.customer_type,l.data FROM jingo_supply_allocations a JOIN jingo_supply_lots l ON l.id=a.lot_id WHERE a.order_id=? AND a.released=0 ORDER BY a.lot_id',[$id])->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$r){$r['quantity']=(int)$r['quantity'];$r['unit_cost']=(int)$r['unit_cost'];$r['released']=(bool)$r['released'];$r['data']=json_decode($r['data'],true,512,JSON_THROW_ON_ERROR);}unset($r);return $rows;
    }
    private function variant(array $snapshot,string $id):string{$variant=$snapshot['variantId'] ?? $this->query('SELECT variant_id FROM jingo_inventory_allocations WHERE order_id=? LIMIT 1',[$id])->fetchColumn();if(!is_string($variant)||$variant==='')throw new HttpError(400,'Đơn cũ cần phân loại đúng gói trong Tồn vé trước.');return $variant;}
    private function available(array $snapshot,string $id):array
    {
        $variant=$this->variant($snapshot,$id);$lots=array_values(array_filter((new SupplyLots($this->db))->list(),fn($l)=>$l['product_id']===$snapshot['productId']&&!$l['archived']&&$l['enabled']&&$l['variant_id']===$variant&&$l['valid_from']<=$snapshot['date']&&$l['valid_to']>=$snapshot['date']&&($l['unlimited']||$l['remaining']>0)));usort($lots,fn($a,$b)=>($a['priority']<=>$b['priority'])?:($a['unit_cost']<=>$b['unit_cost'])?:strcmp($a['created_at'],$b['created_at'])?:strcmp($a['id'],$b['id']));return $lots;
    }
    public function get(string $id):array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{$order=$this->order($id);$snapshot=json_decode($order['snapshot'],true,512,JSON_THROW_ON_ERROR);$entries=$this->entries($id);$needed=array_sum(array_column($snapshot['lines'],'quantity'));$allocated=array_sum(array_column($entries,'quantity'));$cost=0;foreach($entries as $e){$cost+=$e['quantity']*$e['unit_cost'];if($cost>Pricing::MAX_SAFE)throw new HttpError(409,'Giá vốn vượt phạm vi xử lý.');}$result=['entries'=>$entries,'needed'=>$needed,'allocated'=>$allocated,'cost'=>$cost,'complete'=>$allocated===$needed,'lots'=>$this->available($snapshot,$id)];$this->db->commit();return $result;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function plan(array $order,string $mode,array $picks):array
    {
        $snapshot=$this->scope($order);$remaining=[];foreach($snapshot['lines'] as $line)$remaining[$line['customerType']]=($remaining[$line['customerType']] ?? 0)+$line['quantity'];foreach($this->entries($order['id']) as $entry)$remaining[$entry['customer_type']]=($remaining[$entry['customer_type']] ?? 0)-$entry['quantity'];foreach($remaining as $quantity)if($quantity<0)throw new HttpError(409,'Phân bổ hiện có vượt số khách. Cần kiểm tra dữ liệu nguồn.');$lots=$this->available($snapshot,$order['id']);$planned=[];
        if($mode==='AUTO'){foreach($remaining as $group=>$needed){foreach($lots as $lot){if($lot['customer_type']!==$group)continue;$quantity=$lot['unlimited']?$needed:min($needed,$lot['remaining']);if($quantity>0){$planned[]=['lot'=>$lot,'quantity'=>$quantity];$needed-=$quantity;}}if($needed>0)throw new HttpError(409,'Thiếu '.$needed.' suất nguồn cho nhóm '.$group.'. Nhập thêm nguồn hoặc chọn nguồn khác.');}}
        else{if(!$picks||count(array_unique(array_column($picks,'lotId')))!==count($picks))throw new HttpError(400,'Chọn lô và số lượng, không lặp lô.');foreach($picks as $pick){$lot=null;foreach($lots as $candidate)if($candidate['id']===$pick['lotId']){$lot=$candidate;break;}if(!$lot||!$lot['unlimited']&&$pick['quantity']>$lot['remaining']||$pick['quantity']>($remaining[$lot['customer_type']] ?? 0))throw new HttpError(409,'Lô không phù hợp hoặc vượt số còn lại của lô / đơn.');$remaining[$lot['customer_type']]-=$pick['quantity'];$planned[]=['lot'=>$lot,'quantity'=>$pick['quantity']];}}
        return $planned;
    }
    private function commit(array $order,array $planned,string $actor):void
    {
        foreach($planned as $item){$lot=$item['lot'];$prior=$this->query('SELECT quantity,unit_cost,released FROM jingo_supply_allocations WHERE order_id=? AND lot_id=?',[$order['id'],$lot['id']])->fetch(PDO::FETCH_ASSOC);if($prior&&!$prior['released']&&(int)$prior['unit_cost']!==$lot['unit_cost'])throw new HttpError(409,'Giá nguồn đã thay đổi; chọn nguồn có giá mới.');$quantity=$item['quantity']+($prior&&!$prior['released']?(int)$prior['quantity']:0);if($prior)$this->query('UPDATE jingo_supply_allocations SET quantity=?,unit_cost=?,released=0 WHERE order_id=? AND lot_id=?',[$quantity,$lot['unit_cost'],$order['id'],$lot['id']]);else $this->query('INSERT INTO jingo_supply_allocations(order_id,lot_id,quantity,unit_cost) VALUES (?,?,?,?)',[$order['id'],$lot['id'],$quantity,$lot['unit_cost']]);
            if($lot['unlimited']){$h=bin2hex(random_bytes(16));$id=substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);$data=$lot['data'];$data['orderCode']=$order['code'];$this->query('INSERT INTO jingo_supply_purchases(id,lot_id,supplier_id,order_id,customer_type,quantity,unit_cost,data) VALUES (?,?,?,?,?,?,?,?)',[$id,$lot['id'],$lot['supplier_id'],$order['id'],$lot['customer_type'],$item['quantity'],$lot['unit_cost'],json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
        }
        if($planned)$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'supply.allocate',$order['id']]);
    }
    public function allocate(string $id,array $input,string $actor):array
    {
        if(array_diff(array_keys($input),['requestId','version','mode','picks'])||!self::uuid($input['requestId'] ?? null)||!is_int($input['version'] ?? null)||$input['version']<1||$input['version']>2147483646||!in_array($input['mode'] ?? null,['AUTO','MANUAL'],true))throw new HttpError(400,'Dữ liệu phân bổ không hợp lệ.');$picks=$input['picks'] ?? [];if(!is_array($picks)||!array_is_list($picks)||count($picks)>150)throw new HttpError(400,'Danh sách nguồn không hợp lệ.');foreach($picks as $p)if(!is_array($p)||array_diff(array_keys($p),['lotId','quantity'])||!self::uuid($p['lotId'] ?? null)||!is_int($p['quantity'] ?? null)||$p['quantity']<1||$p['quantity']>150)throw new HttpError(400,'Số lượng phân bổ không hợp lệ.');if($input['mode']==='AUTO'&&$picks)throw new HttpError(400,'Phân bổ tự động không nhận danh sách chọn tay.');$normalized=['orderId'=>$id,'input'=>['requestId'=>$input['requestId'],'version'=>$input['version'],'mode'=>$input['mode'],'picks'=>array_map(fn($p)=>['lotId'=>$p['lotId'],'quantity'=>$p['quantity']],$picks)]];$hash=hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{$order=$this->order($id,true);$prior=$this->query('SELECT request_hash FROM jingo_supply_actions WHERE id=?',[$input['requestId']])->fetchColumn();if($prior!==false){if(!hash_equals($prior,$hash))throw new HttpError(409,'Mã thao tác đã dùng.');$this->db->commit();return ['ok'=>true];}if((int)$order['version']!==$input['version'])throw new HttpError(409,'Đơn đã thay đổi. Tải lại trước khi phân bổ.');if($order['booking_status']==='CANCELLED'||$this->query('SELECT order_id FROM jingo_order_archives WHERE order_id=?',[$id])->fetchColumn())throw new HttpError(409,'Đơn hủy/lưu trữ không được phân bổ nguồn.');$planned=$this->plan($order,$input['mode'],$picks);if(!$planned)throw new HttpError(400,'Đơn đã phân bổ đủ nguồn.');$this->commit($order,$planned,$actor);$this->query('UPDATE jingo_orders SET version=version+1 WHERE id=?',[$id]);$this->query('INSERT INTO jingo_supply_actions(id,order_id,request_hash) VALUES (?,?,?)',[$input['requestId'],$id,$hash]);$this->db->commit();return ['ok'=>true];}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function forDelivery(array $order,string $actor):void
    {
        $snapshot=$this->scope($order);if(!$this->query('SELECT id FROM jingo_supply_lots WHERE product_id=? AND archived=0 LIMIT 1',[$snapshot['productId']])->fetchColumn())return;$this->commit($order,$this->plan($order,'AUTO',[]),$actor);
    }
    public function release(array $order):void{$this->scope($order);$this->query('UPDATE jingo_supply_allocations SET released=1 WHERE order_id=? AND released=0',[$order['id']]);}
}
