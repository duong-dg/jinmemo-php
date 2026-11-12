<?php
declare(strict_types=1);

final class ContactRequests
{
    public function __construct(private PDO $db,private array $config) {}
    private function query(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private static function uuid(mixed $id): bool { return is_string($id) && (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$id); }
    private static function text(array $input,string $key,int $min,int $max,bool $trim=true): string
    {
        $value=$input[$key] ?? ($key==='website'?'':null);
        if(!is_string($value)) throw new HttpError(400,'Kiểm tra thông tin liên hệ.');
        if($trim) $value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);
        if($value===null) throw new HttpError(400,'Kiểm tra thông tin liên hệ.');
        $length=strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2;
        if($length<$min || $length>$max) throw new HttpError(400,'Kiểm tra thông tin liên hệ.'); return $value;
    }
    private function guard(): void
    {
        $parts=parse_url($this->config['PUBLIC_BASE_URL'] ?? '');
        if(!$parts || !isset($parts['scheme'],$parts['host']) || !in_array($parts['scheme'],['https','http'],true)) throw new HttpError(503,'Chưa cấu hình URL website.');
        $expected=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        if(($_SERVER['HTTP_X_JINGO_CONTACT'] ?? '')!=='1' || ($_SERVER['HTTP_ORIGIN'] ?? '')!==$expected) throw new HttpError(403,'Nguồn yêu cầu không hợp lệ.');
    }
    private function limit(string $key,int $max): void
    {
        $id=hash('sha256',$key); $row=$this->query('SELECT attempts,until_at FROM jingo_contact_limits WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
        $expired=!$row || strtotime($row['until_at'].' UTC')<=time();
        if(!$expired && (int)$row['attempts']>=$max) throw new HttpError(429,'Bạn đã gửi nhiều yêu cầu. Thử lại sau 15 phút.');
        $attempts=$expired?1:(int)$row['attempts']+1; $until=$expired?gmdate('Y-m-d H:i:s',time()+900):$row['until_at'];
        $this->query('INSERT INTO jingo_contact_limits(id,attempts,until_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),until_at=VALUES(until_at)',[$id,$attempts,$until]);
    }
    public function submit(array $input): array
    {
        $this->guard();
        if(array_diff(array_keys($input),['id','name','email','phone','subject','message','locale','website']) || !self::uuid($input['id'] ?? null) || !in_array($input['locale'] ?? null,['vi','en'],true)) throw new HttpError(400,'Kiểm tra thông tin liên hệ.');
        $data=['name'=>self::text($input,'name',2,100),'email'=>self::text($input,'email',1,200,false),'phone'=>self::text($input,'phone',0,30),'subject'=>self::text($input,'subject',3,180),'message'=>self::text($input,'message',10,5000),'locale'=>$input['locale']];
        if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL) || !preg_match('/^[+0-9 ()-]*$/D',$data['phone'])) throw new HttpError(400,'Kiểm tra email và điện thoại.');
        if(self::text($input,'website',0,200,false)!=='') return ['ok'=>true];
        $this->db->beginTransaction();
        try {
            // Shared with page writes; serializes publication checks, quota and idempotency across PHP workers.
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn()) throw new HttpError(503,'Chưa cài khóa danh mục.');
            $raw=$this->query('SELECT data FROM jingo_pages WHERE id=?',[Pages::CONTACT_ID])->fetchColumn(); $page=$raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):null;
            if(!$page || $page['status']!=='PUBLISHED' || ($data['locale']==='en' && !($page['english']['published'] ?? false))) throw new HttpError(404,'Trang liên hệ chưa xuất bản.');
            $old=$this->query('SELECT data FROM jingo_contact_requests WHERE id=?',[$input['id']])->fetchColumn();
            if($old!==false) {
                $stored=json_decode($old,true,512,JSON_THROW_ON_ERROR);
                foreach($data as $key=>$value) if(($stored[$key] ?? null)!==$value) throw new HttpError(409,'Mã yêu cầu đã được sử dụng. Tải lại trang và gửi lại.');
                $this->db->commit(); return ['ok'=>true];
            }
            $this->query('DELETE FROM jingo_contact_limits WHERE until_at<=UTC_TIMESTAMP()');
            $this->limit('all',100); $this->limit('email:'.strtolower($data['email']),3);
            $this->query('INSERT INTO jingo_contact_requests(id,data) VALUES (?,?)',[$input['id'],json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $this->db->commit(); return ['ok'=>true];
        } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function list(mixed $status='NEW'): array
    {
        if(!in_array($status,['NEW','DONE','TRASH'],true)) throw new HttpError(400,'Trạng thái không hợp lệ.');
        $items=$this->query('SELECT id,data,status,created_at FROM jingo_contact_requests WHERE status=? ORDER BY created_at DESC,id DESC LIMIT 100',[$status])->fetchAll(PDO::FETCH_ASSOC);
        foreach($items as &$item) { $item['data']=json_decode($item['data'],true,512,JSON_THROW_ON_ERROR); $item['created_at']=gmdate('Y-m-d\TH:i:s.000\Z',strtotime($item['created_at'].' UTC')); } unset($item); return ['data'=>$items];
    }
    public function update(string $id,array $input,string $actor): array
    {
        if(!self::uuid($id) || array_keys($input)!==['status'] || !in_array($input['status'],['NEW','DONE','TRASH'],true)) throw new HttpError(400,'Thao tác không hợp lệ.');
        $this->db->beginTransaction();
        try {
            if(!$this->query('SELECT id FROM jingo_contact_requests WHERE id=? FOR UPDATE',[$id])->fetchColumn()) throw new HttpError(404,'Không tìm thấy yêu cầu.');
            $this->query('UPDATE jingo_contact_requests SET status=? WHERE id=?',[$input['status'],$id]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'contact.'.strtolower($input['status']),$id]);
            $this->db->commit(); return ['ok'=>true];
        } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
