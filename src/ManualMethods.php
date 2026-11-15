<?php
declare(strict_types=1);
final class ManualMethods
{
    public function __construct(private PDO $db){}
    public function read():array{$s=$this->db->query("SELECT data FROM jingo_settings WHERE id='manual-payments'");$raw=$s->fetchColumn();return $raw===false?[['id'=>'PAY_LATER','name'=>'Thanh toán sau','englishName'=>'Pay later','instructions'=>'Nhân viên sẽ liên hệ xác nhận và hướng dẫn thanh toán.','englishInstructions'=>'Our staff will contact you to confirm the booking and arrange payment.','enabled'=>true]]:json_decode($raw,true,512,JSON_THROW_ON_ERROR)['methods'];}
    public function active():array{$out=[];foreach($this->read() as $m)if($m['enabled'])$out[]=array_intersect_key($m,array_flip(['id','name','englishName','instructions','englishInstructions']));return $out;}
    public function select(string $id):array{foreach($this->active() as $m)if($m['id']===$id){$m['englishName']=($m['englishName'] ?? '')?:$m['name'];$m['englishInstructions']=($m['englishInstructions'] ?? '')?:($m['instructions'] ?? '');return $m;}throw new HttpError(400,'Phương thức thanh toán đã tắt hoặc không tồn tại. Vui lòng chọn lại.');}
}
