<?php
declare(strict_types=1);

final class PosPortal
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function counters(array $actor):array{return array_values(array_filter((new Counters($this->db))->list(),fn($c)=>in_array($c['id'],$actor['counterIds'],true)));}
    public function products(array $actor):array
    {
        if(!in_array('SELL',$actor['permissions'],true))return [];$items=[];
        foreach((new Products($this->db))->list(true) as $p)if($p['status']==='PUBLISHED'&&!($p['demo'] ?? false)){
            $variants=[];foreach($p['variants'] as $v)$variants[]=array_intersect_key($v,array_flip(['id','name','prices','timeSlots','schedulePeriods','saleWindow','displayOrder']));
            $items[]=['id'=>$p['id'],'name'=>$p['name'],'variants'=>$variants];
        }return $items;
    }
    public static function counter(array $actor,mixed $id):string
    {
        if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id)||!in_array($id,$actor['counterIds'],true))throw new HttpError(404,'Không tìm thấy quầy được cấp quyền.');return $id;
    }
    private function project(array $order,array $actor):array
    {
        $out=array_intersect_key($order,array_flip(['id','code','version','createdAt','channel','counter','bookingStatus','paymentStatus','fulfillmentStatus','snapshot','contact','instructions','deliveredGuests','totalGuests','archived','openShiftId']));
        $show=array_intersect(['PRINT','DELIVER'],$actor['permissions'])&&!$order['archived']&&$order['bookingStatus']==='CONFIRMED'&&$order['paymentStatus']==='PAID';$out['tickets']=$show?$order['tickets']:[];return $out;
    }
    public function detail(array $actor,string $id):array
    {
        $counter=$this->query('SELECT counter_id FROM jingo_pos_orders WHERE order_id=?',[$id])->fetchColumn();self::counter($actor,$counter);return $this->project((new Orders($this->db))->get($id),$actor);
    }
    public function list(array $actor,array $input):array
    {
        if(array_diff(array_keys($input),['counterId']))throw new HttpError(400,'Bộ lọc đơn quầy không hợp lệ.');$ids=$actor['counterIds'];if(array_key_exists('counterId',$input))$ids=[self::counter($actor,$input['counterId'])];if(!$ids)return ['data'=>[]];
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $rows=$this->query('SELECT o.id,o.code,o.snapshot,o.contact,o.booking_status,o.payment_status,o.created_at,po.counter_id,c.data AS counter_data FROM jingo_orders o JOIN jingo_pos_orders po ON po.order_id=o.id JOIN jingo_counters c ON c.id=po.counter_id WHERE po.counter_id IN ('.$marks.') AND NOT EXISTS(SELECT 1 FROM jingo_order_archives a WHERE a.order_id=o.id) ORDER BY o.created_at DESC,o.id DESC LIMIT 200',$ids)->fetchAll(PDO::FETCH_ASSOC);
        $out=[];foreach($rows as $r)$out[]=['id'=>$r['id'],'code'=>$r['code'],'snapshot'=>json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR),'contact'=>json_decode($r['contact'],true,512,JSON_THROW_ON_ERROR),'bookingStatus'=>$r['booking_status'],'paymentStatus'=>$r['payment_status'],'createdAt'=>str_replace(' ','T',$r['created_at']).'Z','counter'=>['id'=>$r['counter_id'],'name'=>json_decode($r['counter_data'],true,512,JSON_THROW_ON_ERROR)['name']]];return ['data'=>$out];
    }
    public function create(array $actor,array $input):array
    {
        PosAuth::permit($actor,'SELL');self::counter($actor,$input['counterId'] ?? null);$order=(new Orders($this->db))->createPos($input,'pos-user:'.$actor['id'],$actor['id']);return $this->project($order,$actor);
    }
    public function act(array $actor,string $id,array $input):array
    {
        $permission=match($input['action'] ?? null){'PAY'=>'COLLECT','CANCEL'=>'CANCEL','DELIVER'=>'DELIVER',default=>null};if(!$permission)throw new HttpError(403,'Thao tác chưa được hỗ trợ tại cổng nhân viên.');PosAuth::permit($actor,$permission);$this->detail($actor,$id);
        return $this->project((new Orders($this->db))->act($id,$input,'pos-user:'.$actor['id'],$actor['id']),$actor);
    }
}
