<?php
declare(strict_types=1);

/** Original supplier files; never extracts QR codes or rewrites PDFs. */
final class TicketFiles
{
    public function __construct(private PDO $db,private string $directory){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function uuid(mixed $id):bool{return is_string($id)&&(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$id);}
    private static function newId():string{$s=bin2hex(random_bytes(16));return substr($s,0,8).'-'.substr($s,8,4).'-4'.substr($s,13,3).'-8'.substr($s,17,3).'-'.substr($s,20,12);}
    private function file(string $id):string{if(!self::uuid($id))throw new HttpError(404,'Không tìm thấy file vé.');return rtrim($this->directory,'/\\').'/'.$id.'.bin';}
    public function list(string $orderId,bool $admin=true):array
    {
        if(!$this->query('SELECT id FROM jingo_orders WHERE id=?',[$orderId])->fetchColumn())throw new HttpError(404,'Không tìm thấy đơn.');
        $columns=$admin?'id,order_id,ticket_id,name,mime,bytes,hash,created_at,revoked_at,revoke_reason,file_kind,deleted_at,partner_shared,sharing_version':'id,ticket_id,name,mime,bytes,created_at,file_kind';
        $rows=$this->query('SELECT '.$columns.' FROM jingo_ticket_files WHERE order_id=?'.($admin?'':' AND revoked_at IS NULL AND deleted_at IS NULL').' ORDER BY created_at,id',[$orderId])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$row['bytes']=(int)$row['bytes'];if($admin){$row['partner_shared']=(bool)$row['partner_shared'];$row['sharing_version']=(int)$row['sharing_version'];}}unset($row);return $rows;
    }
    public function customer(array $lookup):array
    {
        $order=(new Orders($this->db))->lookup($lookup);if($this->query('SELECT order_id FROM jingo_order_archives WHERE order_id=?',[$order['id']])->fetchColumn())throw new HttpError(404,'File vé không khả dụng.');if($order['bookingStatus']!=='CONFIRMED'||$order['paymentStatus']!=='PAID')throw new HttpError(404,'File vé không khả dụng.');return $order;
    }
    public function customerList(array $lookup):array{return $this->list($this->customer($lookup)['id'],false);}
    public function revoke(string $orderId,string $id,array $input,string $actor):array
    {
        $reason=$input['reason'] ?? null;if(array_diff(array_keys($input),['version','reason'])||!is_int($input['version'] ?? null)||$input['version']<1||$input['version']>2147483646||!is_string($reason))throw new HttpError(400,'Nhập lý do thu hồi file.');$reason=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$reason);$length=$reason===null?0:strlen(mb_convert_encoding($reason,'UTF-16LE','UTF-8'))/2;if($length<3||$length>500)throw new HttpError(400,'Lý do thu hồi từ 3 đến 500 ký tự.');
        $this->db->beginTransaction();try{$order=$this->query('SELECT version FROM jingo_orders WHERE id=? FOR UPDATE',[$orderId])->fetch(PDO::FETCH_ASSOC);$file=$this->query('SELECT revoked_at,deleted_at FROM jingo_ticket_files WHERE id=? AND order_id=? FOR UPDATE',[$id,$orderId])->fetch(PDO::FETCH_ASSOC);if(!$order||!$file)throw new HttpError(404,'Không tìm thấy file vé.');if($file['revoked_at']!==null){$this->db->commit();return ['ok'=>true];}if((int)$order['version']!==$input['version'])throw new HttpError(409,'Đơn đã thay đổi. Tải lại trước khi thu hồi.');
            $this->query('UPDATE jingo_ticket_files SET revoked_at=UTC_TIMESTAMP(6),revoke_reason=?,partner_shared=0,sharing_version=sharing_version+1 WHERE id=?',[$reason,$id]);$this->query('UPDATE jingo_orders SET version=version+1 WHERE id=?',[$orderId]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'ticket-file.revoke',$id]);$this->query("INSERT INTO jingo_order_actions(id,order_id,request_hash,admin_id,action,order_version,data) VALUES (?,?,?,?,'REVOKE_FILE',?,?)",[self::newId(),$orderId,hash('sha256',$id),$actor,$input['version']+1,json_encode(['note'=>$reason,'fileId'=>$id],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$this->db->commit();return ['ok'=>true];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function upload(string $orderId,array $input,string $source,string $name,string $actor):array
    {
        if(array_diff(array_keys($input),['requestId','version','covers','ticketId'])||!self::uuid($input['requestId'] ?? null))throw new HttpError(400,'Thông tin đính kèm không hợp lệ.');
        foreach(['version'=>2147483646,'covers'=>150] as $key=>$max){$value=$input[$key] ?? null;if((!is_int($value)&&(!is_string($value)||!preg_match('/^[1-9][0-9]*$/D',$value)))||(int)$value<1||(int)$value>$max)throw new HttpError(400,'Phiên bản hoặc số khách không hợp lệ.');$input[$key]=(int)$value;}
        if(isset($input['ticketId'])&&!self::uuid($input['ticketId']))throw new HttpError(400,'Mã vé không hợp lệ.');
        $size=is_file($source)?filesize($source):false;if($size===false||$size<1||$size>10*1024*1024)throw new HttpError(400,'Chọn PDF/JPG/PNG/WebP tối đa 10 MB.');$bytes=file_get_contents($source);if($bytes===false||strlen($bytes)!==$size)throw new HttpError(400,'Không đọc được file vé.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$pdf=str_starts_with($bytes,'%PDF-');
        if($pdf){if($mime!=='application/pdf'||!preg_match('/%%EOF\s*$/D',substr($bytes,-2048)))throw new HttpError(400,'File PDF không hợp lệ.');$mime='application/pdf';}
        else{$image=@getimagesizefromstring($bytes);if(!$image||!in_array($image['mime'],['image/jpeg','image/png','image/webp'],true)||$image[0]*$image[1]>25000000)throw new HttpError(400,'File ảnh không hợp lệ hoặc quá lớn.');$mime=$image['mime'];}
        $name=mb_substr(preg_replace('/[\\\\\/\x00-\x1f\x7f]/u','_',mb_convert_encoding($name,'UTF-8','UTF-8')) ?? '',0,150);if(trim($name)==='')$name='Vé đính kèm';$hash=hash('sha256',$bytes);
        $requestData=[$orderId,$input['version'],$input['covers'],$hash,$name];if(isset($input['ticketId']))$requestData[]=$input['ticketId'];$requestHash=hash('sha256',json_encode($requestData,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$saved=null;$committing=false;
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            $order=$this->query('SELECT * FROM jingo_orders WHERE id=? FOR UPDATE',[$orderId])->fetch(PDO::FETCH_ASSOC);if(!$order)throw new HttpError(404,'Không tìm thấy đơn.');if($order['booking_status']!=='CONFIRMED'||$order['payment_status']!=='PAID'||$this->query('SELECT order_id FROM jingo_order_archives WHERE order_id=?',[$orderId])->fetchColumn())throw new HttpError(409,'Chỉ giao file cho đơn đã thu đủ tiền và đang hoạt động.');
            $prior=$this->query('SELECT request_hash,data FROM jingo_order_actions WHERE id=?',[$input['requestId']])->fetch(PDO::FETCH_ASSOC);if($prior){if(!hash_equals($prior['request_hash'],$requestHash))throw new HttpError(409,'Mã thao tác đã dùng.');$data=json_decode($prior['data'],true,512,JSON_THROW_ON_ERROR);$id=$data['fileId'] ?? '';if(!$this->query('SELECT id FROM jingo_ticket_files WHERE id=? AND order_id=? AND revoked_at IS NULL AND deleted_at IS NULL',[$id,$orderId])->fetchColumn())throw new HttpError(409,'File đã bị thu hồi hoặc xóa.');$this->db->commit();return ['id'=>$id,'ticketId'=>$data['ticketId'],'duplicate'=>true];}
            $ticket=null;if(isset($input['ticketId'])){$ticket=$this->query('SELECT id,covers FROM jingo_order_tickets WHERE id=? AND order_id=?',[$input['ticketId'],$orderId])->fetch(PDO::FETCH_ASSOC);if(!$ticket)throw new HttpError(404,'Mã vé không thuộc đơn.');if((int)$ticket['covers']!==$input['covers'])throw new HttpError(400,'Số khách phải khớp vé đã giao.');}
            $duplicate=$this->query('SELECT f.id,f.ticket_id,t.covers FROM jingo_ticket_files f JOIN jingo_order_tickets t ON t.id=f.ticket_id WHERE f.order_id=? AND f.hash=? AND f.revoked_at IS NULL AND f.deleted_at IS NULL'.($ticket?' AND f.ticket_id=?':''),$ticket?[$orderId,$hash,$ticket['id']]:[$orderId,$hash])->fetch(PDO::FETCH_ASSOC);if($duplicate){if((int)$duplicate['covers']!==$input['covers'])throw new HttpError(409,'File này đã giao với số khách khác.');$this->db->commit();return ['id'=>$duplicate['id'],'ticketId'=>$duplicate['ticket_id'],'duplicate'=>true];}
            if((int)$order['version']!==$input['version'])throw new HttpError(409,'Đơn đã thay đổi. Tải lại trước khi đính kèm.');$snapshot=json_decode($order['snapshot'],true,512,JSON_THROW_ON_ERROR);$needed=array_sum(array_column($snapshot['lines'],'quantity'));$issued=(int)$this->query('SELECT COALESCE(SUM(covers),0) FROM jingo_order_tickets WHERE order_id=?',[$orderId])->fetchColumn();if(!$ticket&&$issued+$input['covers']>$needed)throw new HttpError(409,'Số khách nhận vé vượt số khách trong đơn.');if((int)$this->query('SELECT COUNT(*) FROM jingo_ticket_files WHERE order_id=?',[$orderId])->fetchColumn()>=50)throw new HttpError(400,'Mỗi đơn tối đa 50 file.');
            if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new RuntimeException('Cannot create ticket storage.');$id=self::newId();$ticketId=$ticket['id'] ?? self::newId();$path=$this->file($id);$stream=fopen($path,'xb');if(!$stream)throw new RuntimeException('Cannot save ticket file.');$saved=$path;try{if(fwrite($stream,$bytes)!==strlen($bytes)||!fflush($stream))throw new RuntimeException('Incomplete ticket file.');}finally{fclose($stream);}@chmod($path,0600);
            $code='FILE-'.$ticketId;$note='File gốc từ đối tác; dùng nội dung/QR trong file để vào cổng.';if(!$ticket)$this->query("INSERT INTO jingo_order_tickets(id,order_id,code,code_hash,kind,covers,note) VALUES (?,?,?,?,'TOUR_VOUCHER',?,?)",[$ticketId,$orderId,$code,hash('sha256',$code),$input['covers'],$note]);
            $this->query('INSERT INTO jingo_ticket_files(id,order_id,ticket_id,name,mime,bytes,hash) VALUES (?,?,?,?,?,?,?)',[$id,$orderId,$ticketId,$name,$mime,$size,$hash]);$this->query('UPDATE jingo_orders SET version=version+1 WHERE id=?',[$orderId]);
            $data=['note'=>'Đính kèm file: '.$name,'fileId'=>$id,'ticketId'=>$ticketId,'covers'=>$input['covers']];$this->query("INSERT INTO jingo_order_actions(id,order_id,request_hash,admin_id,action,order_version,data) VALUES (?,?,?,?,'ATTACH_FILE',?,?)",[$input['requestId'],$orderId,$requestHash,$actor,$input['version']+1,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$committing=true;$this->db->commit();$saved=null;return ['id'=>$id,'ticketId'=>$ticketId,'duplicate'=>false];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($saved!==null&&!$committing&&is_file($saved))unlink($saved);if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,'Mã thao tác hoặc file đã được ghi nhận.');throw $e;}
    }
    public function download(string $orderId,string $id,bool $customer=false):array
    {
        $row=$this->query('SELECT * FROM jingo_ticket_files WHERE id=? AND order_id=?',[$id,$orderId])->fetch(PDO::FETCH_ASSOC);if(!$row||$row['deleted_at']!==null||$customer&&$row['revoked_at']!==null)throw new HttpError(404,'Không tìm thấy file vé.');$path=$this->file($id);if(!is_file($path)||filesize($path)!==(int)$row['bytes']||!hash_equals($row['hash'],hash_file('sha256',$path)))throw new HttpError(404,'File vé không còn khả dụng.');return ['path'=>$path,'mime'=>$row['mime'],'id'=>$row['id']];
    }
}
