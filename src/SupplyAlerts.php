<?php
declare(strict_types=1);

final class SupplyAlerts
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public static function classify(array $lot,string $today,int $threshold,int $days):?string
    {
        if(!$lot['enabled']||$lot['archived'])return null;
        if($lot['valid_to']<$today)return $lot['unlimited']||($lot['remaining'] ?? 0)>0?'EXPIRED':null;
        if($lot['valid_from']>$today)return null;
        if(!$lot['unlimited']&&$lot['remaining']===0)return 'EMPTY';
        $end=(new DateTimeImmutable($today.'T00:00:00Z'))->modify('+'.$days.' days')->format('Y-m-d');
        if($lot['valid_to']<=$end)return 'EXPIRING';
        return !$lot['unlimited']&&($lot['remaining'] ?? 0)<=$threshold?'LOW':null;
    }
    private static function quantity(mixed $value):int{if(!is_int($value)&&!is_string($value)||!preg_match('/^[0-9]{1,9}$/D',(string)$value))throw new HttpError(409,'Số lượng nguồn trong dữ liệu không hợp lệ.');return (int)$value;}
    public function get(array $input,?DateTimeImmutable $now=null):array
    {
        if(array_diff(array_keys($input),['threshold','days','page']))throw new HttpError(400,'Bộ lọc cảnh báo không hợp lệ.');$values=[];foreach(['threshold'=>[5,1000000],'days'=>[7,90],'page'=>[1,100000]] as $key=>[$default,$max]){$v=$input[$key] ?? (string)$default;if(!is_string($v)||!preg_match('/^[1-9][0-9]{0,6}$/D',$v)||(int)$v>$max)throw new HttpError(400,'Ngưỡng, số ngày hoặc trang không hợp lệ.');$values[$key]=(int)$v;}$today=($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
            $lots=(new SupplyLots($this->db))->list();$alerts=[];$counts=['EXPIRED'=>0,'EXPIRING'=>0,'EMPTY'=>0,'LOW'=>0];foreach($lots as $lot){if(!$lot['unlimited']&&$lot['remaining']<0)throw new HttpError(409,'Nguồn có phân bổ vượt số lượng. Kiểm tra dữ liệu trước.');$alert=self::classify($lot,$today,$values['threshold'],$values['days']);if($alert!==null){$lot['alert']=$alert;$alerts[]=$lot;$counts[$alert]++;}}
            $orders=$this->query("SELECT o.id,o.code,o.snapshot,o.payment_status FROM jingo_orders o WHERE o.booking_status<>'CANCELLED' AND NOT EXISTS(SELECT 1 FROM jingo_order_archives a WHERE a.order_id=o.id) ORDER BY o.created_at,o.id")->fetchAll(PDO::FETCH_ASSOC);$allocations=$this->query('SELECT a.order_id,a.quantity,l.customer_type FROM jingo_supply_allocations a JOIN jingo_supply_lots l ON l.id=a.lot_id WHERE a.released=0')->fetchAll(PDO::FETCH_ASSOC);$byOrder=[];foreach($allocations as $a)$byOrder[$a['order_id']][$a['customer_type']]=($byOrder[$a['order_id']][$a['customer_type']] ?? 0)+self::quantity($a['quantity']);$pending=[];
            foreach($orders as $order){$snapshot=json_decode($order['snapshot'],true,512,JSON_THROW_ON_ERROR);$needed=[];foreach($snapshot['lines'] as $line)$needed[$line['customerType']]=($needed[$line['customerType']] ?? 0)+self::quantity($line['quantity']);$missing=[];$total=0;foreach($needed as $group=>$quantity){$quantity=max(0,$quantity-($byOrder[$order['id']][$group] ?? 0));if($quantity){$missing[]=['customerType'=>$group,'quantity'=>$quantity];$total+=$quantity;}}if($total)$pending[]=['id'=>$order['id'],'code'=>$order['code'],'productName'=>$snapshot['productName'],'variantName'=>$snapshot['variantName'] ?? '','date'=>$snapshot['date'],'paymentStatus'=>$order['payment_status'],'missing'=>$missing,'missingQuantity'=>$total];}
            $rank=['EXPIRED'=>0,'EXPIRING'=>1,'EMPTY'=>2,'LOW'=>3];usort($alerts,fn($a,$b)=>($rank[$a['alert']]<=>$rank[$b['alert']])?:strcmp($a['valid_to'],$b['valid_to'])?:strcmp($a['id'],$b['id']));$page=min($values['page'],max(1,(int)ceil(count($pending)/25)));$result=['today'=>$today,'threshold'=>$values['threshold'],'days'=>$values['days'],'counts'=>$counts,'lotTotal'=>count($alerts),'lots'=>array_slice($alerts,0,100),'pending'=>['total'=>count($pending),'page'=>$page,'pageSize'=>25,'data'=>array_slice($pending,($page-1)*25,25)]];$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
