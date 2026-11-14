<?php
declare(strict_types=1);
require_once __DIR__.'/TicketFiles.php';
final class PartnerShares
{
    public function __construct(private PDO $db,private string $directory){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function transaction(callable $fn):array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{$out=$fn();$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')throw new HttpError(409,'Mã yêu cầu hoặc khóa chia sẻ đã dùng.');throw $e;}
    }
    private function account(string $id,int $status=403):void
    {
        $raw=$this->query('SELECT data FROM jingo_partners WHERE id=? LOCK IN SHARE MODE',[$id])->fetchColumn();$a=$raw===false?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!$a||!in_array($a['status'] ?? '',['ACTIVE','PAUSED'],true))throw new HttpError($status,'Không tìm thấy quyền chia sẻ.');
    }
    private function order(string $id,string $partner,bool $write):array
    {
        $o=$this->query('SELECT o.* FROM jingo_orders o JOIN jingo_partner_orders p ON p.order_id=o.id WHERE o.id=? AND p.partner_id=?'.($write?' FOR UPDATE':' LOCK IN SHARE MODE'),[$id,$partner])->fetch(PDO::FETCH_ASSOC);if(!$o)throw new HttpError(404,'Không tìm thấy đơn CTV.');return $o;
    }
    private function eligible(array $o,bool $paid):void
    {
        if($o['booking_status']!=='CONFIRMED'||$paid&&!in_array($o['payment_status'],['PAID','CREDIT'],true)||$this->query('SELECT order_id FROM jingo_order_archives WHERE order_id=?',[$o['id']])->fetchColumn())throw new HttpError(404,'Không tìm thấy vé chia sẻ.');
    }
    private function audit(string $actor,string $action,string $id):void{$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),'partner:'.$actor,$action,$id]);}
    private function request(array $in,array $keys):void
    {
        if(array_diff(array_keys($in),$keys)||count($in)!==count($keys)||!is_string($in['requestId'] ?? null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$in['requestId']))throw new HttpError(400,'Yêu cầu chia sẻ không hợp lệ.');
    }
    public function create(array $actor,string $id,array $in):array
    {
        $this->request($in,['requestId','accessKey']);if(!is_string($in['accessKey'])||!preg_match('/^[a-f0-9]{64}$/D',$in['accessKey']))throw new HttpError(400,'Khóa chia sẻ không hợp lệ.');return $this->transaction(function()use($actor,$id,$in){$this->account($actor['id']);$o=$this->order($id,$actor['id'],true);$this->eligible($o,false);$hash=hash('sha256',$in['accessKey']);$prior=$this->query('SELECT order_id,access_hash,revoked_at FROM jingo_partner_shares WHERE id=?',[$in['requestId']])->fetch(PDO::FETCH_ASSOC);if($prior){if($prior['order_id']!==$id||!hash_equals($prior['access_hash'],$hash)||$prior['revoked_at']!==null)throw new HttpError(409,'Yêu cầu đã dùng hoặc liên kết đã thu hồi.');}else{$this->query('INSERT INTO jingo_partner_shares(id,order_id,access_hash) VALUES (?,?,?)',[$in['requestId'],$id,$hash]);$this->audit($actor['id'],'partner-share.create',$id);}return ['code'=>$o['code'],'accessKey'=>$in['accessKey']];});
    }
    public function revoke(array $actor,string $id,array $in):array
    {
        $this->request($in,['requestId']);return $this->transaction(function()use($actor,$id,$in){$this->account($actor['id']);$this->order($id,$actor['id'],true);$prior=$this->query('SELECT order_id FROM jingo_partner_share_actions WHERE id=?',[$in['requestId']])->fetchColumn();if($prior!==false){if($prior!==$id)throw new HttpError(409,'Yêu cầu đã dùng.');return ['ok'=>true];}$this->query('UPDATE jingo_partner_shares SET revoked_at=UTC_TIMESTAMP(6) WHERE order_id=? AND revoked_at IS NULL',[$id]);$this->query('INSERT INTO jingo_partner_share_actions(id,order_id) VALUES (?,?)',[$in['requestId'],$id]);$this->audit($actor['id'],'partner-share.revoke',$id);return ['ok'=>true];});
    }
    public function sharing(array $actor,string $id,string $file,array $in):array
    {
        if(array_diff(array_keys($in),['version','shared'])||count($in)!==2||!is_int($in['version'] ?? null)||$in['version']<1||$in['version']>=2147483647||!is_bool($in['shared'] ?? null))throw new HttpError(400,'Quyền chia sẻ file không hợp lệ.');return $this->transaction(function()use($actor,$id,$file,$in){$this->account($actor['id']);$this->eligible($this->order($id,$actor['id'],true),true);$v=$this->query('SELECT sharing_version FROM jingo_ticket_files WHERE id=? AND order_id=? AND revoked_at IS NULL AND deleted_at IS NULL FOR UPDATE',[$file,$id])->fetchColumn();if($v===false)throw new HttpError(404,'Không tìm thấy file vé.');if((int)$v!==$in['version'])throw new HttpError(409,'Quyền file đã thay đổi. Mở lại đơn trước khi thao tác.');$this->query('UPDATE jingo_ticket_files SET partner_shared=?,sharing_version=sharing_version+1 WHERE id=?',[(int)$in['shared'],$file]);$this->audit($actor['id'],$in['shared']?'ticket-file.share':'ticket-file.unshare',$file);return ['shared'=>$in['shared'],'version'=>$in['version']+1];});
    }
    public function guest(array $in,string $kind,?string $file=null):array
    {
        if(array_diff(array_keys($in),['code','accessKey'])||count($in)!==2||!is_string($in['code'] ?? null)||!preg_match('/^J[GM][A-F0-9]{10}$/D',$in['code'])||!is_string($in['accessKey'] ?? null)||!preg_match('/^[a-f0-9]{64}$/D',$in['accessKey']))throw new HttpError(400,'Liên kết nhận vé không hợp lệ.');
        return $this->transaction(function()use($in,$kind,$file){$hash=hash('sha256',$in['accessKey']);$ref=$this->query('SELECT s.id,s.order_id,p.partner_id FROM jingo_partner_shares s JOIN jingo_orders o ON o.id=s.order_id JOIN jingo_partner_orders p ON p.order_id=o.id WHERE o.code=? AND s.access_hash=? AND s.revoked_at IS NULL',[$in['code'],$hash])->fetch(PDO::FETCH_ASSOC);if(!$ref)throw new HttpError(404,'Liên kết không còn hiệu lực.');$this->account($ref['partner_id'],404);$o=$this->order($ref['order_id'],$ref['partner_id'],false);$this->eligible($o,true);if(!$this->query('SELECT id FROM jingo_partner_shares WHERE id=? AND order_id=? AND access_hash=? AND revoked_at IS NULL LOCK IN SHARE MODE',[$ref['id'],$o['id'],$hash])->fetchColumn())throw new HttpError(404,'Liên kết không còn hiệu lực.');
            if($kind==='lookup'){$s=json_decode($o['snapshot'],true,512,JSON_THROW_ON_ERROR);return ['code'=>$o['code'],'productName'=>$s['productName'],'variantName'=>$s['variantName'],'date'=>$s['date'],'timeSlot'=>$s['timeSlot'] ?? null,'bookingStatus'=>$o['booking_status']];}
            if($kind==='download'){if(!$this->query('SELECT id FROM jingo_ticket_files WHERE id=? AND order_id=? AND partner_shared=1 AND revoked_at IS NULL AND deleted_at IS NULL LOCK IN SHARE MODE',[$file,$o['id']])->fetchColumn())throw new HttpError(404,'Không tìm thấy file vé.');return (new TicketFiles($this->db,$this->directory))->download($o['id'],$file,true);}
            $rows=$this->query('SELECT id,mime,bytes,created_at,file_kind FROM jingo_ticket_files WHERE order_id=? AND partner_shared=1 AND revoked_at IS NULL AND deleted_at IS NULL ORDER BY created_at,id LOCK IN SHARE MODE',[$o['id']])->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $i=>&$r){$ext=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$r['mime']] ?? 'bin';$r['name']='File vé '.($i+1).'.'.$ext;$r['bytes']=(int)$r['bytes'];}unset($r);return ['data'=>$rows];
        });
    }
}
