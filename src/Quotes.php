<?php
declare(strict_types=1);

final class Quotes
{
    public function __construct(private PDO $db){}
    private function translated(array $product,string $lang):array
    {
        if($lang==='en'){if(!Products::translated($product))throw new HttpError(404,'Translation unavailable.');$product['name']=$product['english']['name'];foreach($product['variants'] as &$v)foreach($product['english']['variants'] as $t)if($t['id']===$v['id']){$v['name']=$t['name'];break;}unset($v);}return $product;
    }
    public function quote(array $input,string $lang='vi'):array
    {
        if(array_diff(array_keys($input),['productId','variantId','date','quantities','slotId','voucherCode']))throw new HttpError(400,'Dữ liệu báo giá không hợp lệ.');
        foreach(['productId','variantId','date'] as $key)if(!is_string($input[$key] ?? null)||strlen($input[$key])>80)throw new HttpError(400,'Dữ liệu báo giá không hợp lệ.');
        if(!is_array($input['quantities'] ?? null)||array_diff(array_keys($input['quantities']),Pricing::GROUPS)||array_key_exists('slotId',$input)&&(!is_string($input['slotId'])||strlen($input['slotId'])>80))throw new HttpError(400,'Nhóm khách hoặc suất giờ không hợp lệ.');
        if(array_key_exists('voucherCode',$input)&&(!is_string($input['voucherCode'])||strlen($input['voucherCode'])>40))throw new HttpError(400,'Mã giảm giá không hợp lệ.');
        $this->db->beginTransaction();try{
            $s=$this->db->prepare('SELECT id FROM jingo_products WHERE id=? LOCK IN SHARE MODE');$s->execute([$input['productId']]);if(!$s->fetchColumn())throw new HttpError(404,'Không tìm thấy sản phẩm.');
            $p=$this->translated((new Products($this->db))->find($input['productId']),$lang);$quote=Pricing::online($p,$input['variantId'],$input['date'],$input['quantities'],$p['rules'],$p['closures'],$input['slotId'] ?? null);$quantity=array_sum(array_column($quote['lines'],'quantity'));
            $voucher=$input['voucherCode'] ?? '';Pricing::checkVoucher($quote,$voucher);if($voucher!==''){$discount=(new Vouchers($this->db))->discount($voucher,$quote['total'],['productId'=>$p['id'],'variantId'=>$input['variantId'],'date'=>$input['date']]);$quote['discount']=['code'=>$discount['code'],'amount'=>$discount['amount'],'subtotal'=>$discount['subtotal']];$quote['total']-=$discount['amount'];}
            $quote['inventory']=(new Inventory($this->db))->check($p['id'],$input['variantId'],$input['date'],$quote['timeSlot']['id'] ?? '',$quantity);$this->db->commit();return $quote;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function prices(string $slug,string $variantId,string $date,?string $slotId,string $lang='vi'):array
    {
        $products=new Products($this->db);$raw=$products->find($slug,true,false,$lang);$this->db->beginTransaction();try{
            $s=$this->db->prepare('SELECT id FROM jingo_products WHERE id=? LOCK IN SHARE MODE');$s->execute([$raw['id']]);$p=$this->translated($products->find($raw['id'],false,false,$lang),$lang);$quantities=null;foreach($p['variants'] as $v)if($v['id']===$variantId)$quantities=array_fill_keys(array_keys($v['prices']),1);if($quantities===null)throw new HttpError(400,'Gói vé không tồn tại.');
            $quote=Pricing::online($p,$variantId,$date,$quantities,$p['rules'],$p['closures'],$slotId);$quote['inventory']=(new Inventory($this->db))->availability($p['id'],$variantId,$date,$quote['timeSlot']['id'] ?? '');$this->db->commit();return $quote;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
