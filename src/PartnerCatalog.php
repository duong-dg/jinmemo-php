<?php
declare(strict_types=1);
require_once __DIR__.'/PriceGroups.php';
require_once __DIR__.'/Products.php';
require_once __DIR__.'/Inventory.php';
final class PartnerCatalog
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function read(array $actor,callable $load):array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' LOCK IN SHARE MODE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');$raw=$this->query('SELECT data FROM jingo_partners WHERE id=? LOCK IN SHARE MODE',[$actor['id']])->fetchColumn();$account=$raw===false?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!$account||($account['status'] ?? '')!=='ACTIVE')throw new HttpError(403,'Tài khoản tạm dừng nhận đơn mới.');$row=$this->query('SELECT data,version FROM jingo_price_groups WHERE id=? LOCK IN SHARE MODE',[$account['groupId'] ?? ''])->fetch(PDO::FETCH_ASSOC);$group=$row?json_decode($row['data'],true,512,JSON_THROW_ON_ERROR):null;if($group)$group['version']=(int)$row['version'];$result=$load($group);$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function applies(array $r,array $quote,string $type):bool{return $r['productId']===$quote['productId']&&$r['variantId']===$quote['variantId']&&$r['customerType']===$type&&$r['startDate']<=$quote['date']&&$quote['date']<=$r['endDate'];}
    public static function net(array $quote,array $group):array
    {
        if(($group['status'] ?? '')!=='ACTIVE')throw new HttpError(400,'Bảng giá CTV tạm ngừng.');$group=PriceGroups::validate($group);$lines=[];$total=0;
        foreach($quote['lines'] as $line){$rules=array_values(array_filter($group['rules'],fn($r)=>self::applies($r,$quote,$line['customerType'])));usort($rules,fn($a,$b)=>$b['priority']<=>$a['priority']);if(count($rules)>1&&$rules[0]['priority']===$rules[1]['priority'])throw new HttpError(400,'Giá CTV bị xung đột.');if(($group['pricingMode'] ?? '')==='DIRECT'&&!$rules)throw new HttpError(400,'Chưa có giá cấp CTV cho gói / nhóm khách trong ngày này.');
            if($rules)$price=$rules[0]['amount'];elseif($group['discountType']==='AMOUNT')$price=max(0,$line['unitPrice']-$group['discountValue']);else{$factor=10000-$group['discountValue'];$price=intdiv($line['unitPrice'],10000)*$factor+intdiv(($line['unitPrice']%10000)*$factor,10000);}$subtotal=$price*$line['quantity'];$total+=$subtotal;if($total>Pricing::MAX_SAFE)throw new HttpError(400,'Tổng tiền CTV vượt giới hạn.');$lines[]=['customerType'=>$line['customerType'],'quantity'=>$line['quantity'],'unitPrice'=>$price,'subtotal'=>$subtotal];
        }$out=array_intersect_key($quote,array_flip(['timeSlot','productId','productName','variantId','variantName','date','currency','demo']));return $out+['lines'=>$lines,'total'=>$total];
    }
    public function products(array $actor):array
    {
        return $this->read($actor,function($group){if(!$group||($group['status'] ?? '')!=='ACTIVE')return ['data'=>[]];$group=PriceGroups::validate($group);$items=[];
            foreach((new Products($this->db))->list(true) as $p){if($p['status']!=='PUBLISHED'||($p['demo'] ?? false))continue;$variants=[];foreach($p['variants'] as $v){$types=array_values(array_filter(array_keys($v['prices']),fn($type)=>($group['pricingMode'] ?? '')!=='DIRECT'||count(array_filter($group['rules'],fn($r)=>$r['productId']===$p['id']&&$r['variantId']===$v['id']&&$r['customerType']===$type))>0));if(!$types)continue;$variants[]=array_intersect_key($v,array_flip(['id','name','saleWindow','maxPassengers','roundTrip','schedulePeriods','timeSlots','conditions']))+['customerTypes'=>$types];}if(!$variants)continue;$item=array_intersect_key($p,array_flip(['id','slug','name','destination','description','cover','transport']));$item['variants']=$variants;$item['saleClosures']=array_map(fn($c)=>array_intersect_key($c,array_flip(['variantId','startDate','endDate','weekdays'])),$p['closures'] ?? []);$type=$this->query('SELECT data FROM jingo_product_types WHERE id=?',[$p['type'] ?? ''])->fetchColumn();if($type){$type=json_decode($type,true,512,JSON_THROW_ON_ERROR);if(($type['status'] ?? '')==='ACTIVE')$item['typeName']=$type['name'];}$items[]=$item;}return ['data'=>$items];
        });
    }
    public function quote(array $actor,array $input):array
    {
        if(array_diff(array_keys($input),['productId','variantId','date','quantities','slotId']))throw new HttpError(400,'Dữ liệu báo giá CTV không hợp lệ.');foreach(['productId','variantId'] as $key)if(!is_string($input[$key] ?? null)||$input[$key]===''||strlen(mb_convert_encoding($input[$key],'UTF-16LE','UTF-8'))/2>80)throw new HttpError(400,'Sản phẩm/gói không hợp lệ.');if(!is_string($input['date'] ?? null)||!is_array($input['quantities'] ?? null)||array_diff(array_keys($input['quantities']),Pricing::GROUPS))throw new HttpError(400,'Ngày hoặc nhóm khách không hợp lệ.');Pricing::validateDate($input['date']);foreach($input['quantities'] as $q)if(!is_int($q)||$q<0||$q>50)throw new HttpError(400,'Số khách phải là số nguyên 0–50.');if(array_key_exists('slotId',$input)&&(!is_string($input['slotId'])||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$input['slotId'])))throw new HttpError(400,'Suất giờ không hợp lệ.');
        return $this->read($actor,function($group)use($input){if(!$group)throw new HttpError(400,'Chưa có bảng giá CTV.');if(($group['status'] ?? '')!=='ACTIVE')throw new HttpError(400,'Bảng giá CTV tạm ngừng.');$group=PriceGroups::validate($group);$p=(new Products($this->db))->find($input['productId']);if($p['demo'] ?? false)throw new HttpError(400,'Sản phẩm mẫu chưa nhận đơn.');$retail=Pricing::calculate($p,$input['variantId'],$input['date'],$input['quantities'],$p['rules'] ?? [],$p['closures'] ?? [],$input['slotId'] ?? null);$net=self::net($retail,$group);$variant=null;foreach($p['variants'] as $v)if($v['id']===$input['variantId'])$variant=$v;$types=[];foreach(array_keys($variant['prices']) as $type)if(($group['pricingMode'] ?? '')!=='DIRECT'||array_filter($group['rules'],fn($r)=>self::applies($r,$retail,$type)))$types[$type]=1;
            $rates=self::net(Pricing::calculate($p,$input['variantId'],$input['date'],$types,$p['rules'] ?? [],$p['closures'] ?? [],$input['slotId'] ?? null,null,false),$group);$net['unitPrices']=(object)array_column($rates['lines'],'unitPrice','customerType');$net['inventory']=(new Inventory($this->db))->check($p['id'],$input['variantId'],$input['date'],$net['timeSlot']['id'] ?? '',array_sum(array_column($net['lines'],'quantity')));return $net;
        });
    }
}
