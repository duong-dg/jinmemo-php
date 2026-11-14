<?php
declare(strict_types=1);
require_once __DIR__.'/Orders.php';
final class PartnerOrders
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function create(array $actor,array $input):array{return (new Orders($this->db))->create($input,null,$actor['id']);}
    private function read(array $actor,callable $load):array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{$raw=$this->query('SELECT data FROM jingo_partners WHERE id=? LOCK IN SHARE MODE',[$actor['id']])->fetchColumn();$a=$raw===false?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!$a||!in_array($a['status'] ?? '',['ACTIVE','PAUSED'],true))throw new HttpError(403,'Tài khoản CTV không còn hợp lệ.');$out=$load();$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function list(array $actor):array
    {
        return $this->read($actor,function()use($actor){$rows=$this->query('SELECT o.id,o.code,o.version,o.created_at,o.snapshot,o.contact,o.booking_status,o.payment_status,EXISTS(SELECT 1 FROM jingo_order_archives a WHERE a.order_id=o.id) AS archived FROM jingo_orders o JOIN jingo_partner_orders p ON p.order_id=o.id WHERE p.partner_id=? ORDER BY o.created_at DESC,o.id DESC LIMIT 200',[$actor['id']])->fetchAll(PDO::FETCH_ASSOC);$out=[];foreach($rows as $r)$out[]=['id'=>$r['id'],'code'=>$r['code'],'version'=>(int)$r['version'],'createdAt'=>str_replace(' ','T',$r['created_at']).'Z','snapshot'=>json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR),'contact'=>json_decode($r['contact'],true,512,JSON_THROW_ON_ERROR),'bookingStatus'=>$r['booking_status'],'paymentStatus'=>$r['payment_status'],'archived'=>(bool)$r['archived']];return ['data'=>$out];});
    }
    public function detail(array $actor,string $id):array
    {
        return $this->read($actor,function()use($actor,$id){if(!$this->query('SELECT order_id FROM jingo_partner_orders WHERE order_id=? AND partner_id=?',[$id,$actor['id']])->fetchColumn())throw new HttpError(404,'Không tìm thấy đơn CTV.');$o=(new Orders($this->db))->get($id);$out=array_intersect_key($o,array_flip(['id','code','version','createdAt','snapshot','contact','bookingStatus','paymentStatus','fulfillmentStatus','tickets','deliveredGuests','totalGuests','instructions','archived']));if($o['archived']||$o['bookingStatus']!=='CONFIRMED'||$o['paymentStatus']!=='PAID')$out['tickets']=[];return $out;});
    }
}
