<?php
declare(strict_types=1);
require_once __DIR__.'/TicketFiles.php';
final class PartnerTicketFiles
{
    public function __construct(private PDO $db,private string $directory){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function read(array $actor,string $id,callable $load):array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
            $raw=$this->query('SELECT data FROM jingo_partners WHERE id=? LOCK IN SHARE MODE',[$actor['id']])->fetchColumn();$account=$raw===false?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!$account||!in_array($account['status'] ?? '',['ACTIVE','PAUSED'],true))throw new HttpError(403,'Tài khoản CTV không còn hợp lệ.');
            $order=$this->query('SELECT o.id,o.booking_status,o.payment_status FROM jingo_orders o JOIN jingo_partner_orders p ON p.order_id=o.id WHERE o.id=? AND p.partner_id=? LOCK IN SHARE MODE',[$id,$actor['id']])->fetch(PDO::FETCH_ASSOC);if(!$order||$order['booking_status']!=='CONFIRMED'||!in_array($order['payment_status'],['PAID','CREDIT'],true)||$this->query('SELECT order_id FROM jingo_order_archives WHERE order_id=?',[$id])->fetchColumn())throw new HttpError(404,'Không tìm thấy file của đơn CTV.');
            $result=$load(new TicketFiles($this->db,$this->directory));$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function list(array $actor,string $id):array{return $this->read($actor,$id,fn($files)=>$files->list($id,false));}
    public function download(array $actor,string $order,string $file):array{return $this->read($actor,$order,fn($files)=>$files->download($order,$file,true));}
}
