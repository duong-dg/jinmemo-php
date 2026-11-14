<?php
declare(strict_types=1);

final class PrintDocuments
{
    public function __construct(private PDO $db){}
    public function order(string $id):array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();
        try{
            $order=(new Orders($this->db))->get($id);
            $usable=!$order['archived']&&$order['bookingStatus']==='CONFIRMED'&&$order['paymentStatus']==='PAID';
            $files=$usable?(new TicketFiles($this->db,''))->list($id):[];
            $data=array_intersect_key($order,array_flip(['id','code','snapshot','contact','channel','counter','createdAt','bookingStatus','paymentStatus','archived','totalGuests','deliveredGuests','instructions']));
            $data['contact']=array_intersect_key($order['contact'],array_flip(['name','phone','email']));
            $data['tickets']=$usable?array_values(array_filter($order['tickets'],fn($t)=>!str_starts_with($t['code'],'FILE-'))):[];
            $data['files']=array_values(array_map(fn($f)=>['id'=>$f['id'],'name'=>$f['name']],array_filter($files,fn($f)=>$f['revoked_at']===null&&$f['deleted_at']===null)));
            $this->db->commit();return $data;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function shift(string $id):array
    {
        $data=(new Shifts($this->db))->detail($id);
        if(!$data['closedAt'])throw new HttpError(409,'Chốt ca trước khi in biên bản bàn giao.');
        foreach(['cash','bank','cashIn','cashOut','expectedCash','countedCash','receiptCount','movementCount'] as $key){
            $n=$data['summary'][$key] ?? null;
            if(!is_int($n)||$n<0||$n>9007199254740991)throw new HttpError(409,'Ca chưa có đủ số liệu chốt hợp lệ để in bàn giao.');
        }
        $difference=$data['summary']['difference'] ?? null;
        if(!is_int($difference)||abs($difference)>9007199254740991||$difference!==$data['summary']['countedCash']-$data['summary']['expectedCash'])throw new HttpError(409,'Chênh lệch ca chưa hợp lệ. Kiểm tra dữ liệu chốt.');
        $s=$data['summary'];if($s['expectedCash']!==$data['openingCash']+$s['cash']+$s['cashIn']-$s['cashOut'])throw new HttpError(409,'Tiền mặt theo sổ không khớp số liệu chốt ca.');
        return $data;
    }
    public static function html(string $kind,array $document,array $brand):string
    {
        if(!in_array($kind,['order','shift'],true))throw new InvalidArgumentException('Unknown print document');
        ob_start();try{require dirname(__DIR__).'/views/print.php';return ob_get_contents();}finally{ob_end_clean();}
    }
}
