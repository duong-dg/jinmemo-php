<?php
declare(strict_types=1);

final class StaffTicketFiles
{
    public function __construct(private PDO $db,private string $directory){}
    public function assertOrder(array $actor,string $id,bool $write=false):array
    {
        if(!array_intersect($write?['DELIVER']:['PRINT','DELIVER'],$actor['permissions']))throw new HttpError(404,'Không tìm thấy vé được cấp quyền.');
        $order=(new PosPortal($this->db))->detail($actor,$id);if($order['archived']||$order['bookingStatus']!=='CONFIRMED'||$order['paymentStatus']!=='PAID')throw new HttpError(404,'Vé không còn khả dụng.');return $order;
    }
    private function read(array $actor,string $id,callable $load):array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
            $order=$this->assertOrder($actor,$id);PosAccess::any($this->db,$actor['id'],$order['counter']['id'],['PRINT','DELIVER']);$result=$load(new TicketFiles($this->db,$this->directory));$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function list(array $actor,string $id):array{return $this->read($actor,$id,fn($files)=>$files->list($id,false));}
    public function download(array $actor,string $order,string $file):array{return $this->read($actor,$order,fn($files)=>$files->download($order,$file,true));}
    public function upload(array $actor,string $id,array $input,string $path,string $name):array
    {
        $this->assertOrder($actor,$id,true);return (new TicketFiles($this->db,$this->directory))->upload($id,$input,$path,$name,'pos-user:'.$actor['id'],$actor['id']);
    }
    public function revoke(array $actor,string $id,string $file,array $input):array
    {
        $this->assertOrder($actor,$id,true);return (new TicketFiles($this->db,$this->directory))->revoke($id,$file,$input,'pos-user:'.$actor['id'],$actor['id']);
    }
}
