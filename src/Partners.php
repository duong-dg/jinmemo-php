<?php
declare(strict_types=1);
final class Partners
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function uuid(mixed $v):bool{return is_string($v)&&(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$v);}
    private static function fail(string $message='Thông tin tài khoản CTV không hợp lệ.'):never{throw new HttpError(400,$message);}
    private static function text(mixed $v,int $max,int $min=0):string{if(!is_string($v))self::fail();$v=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$v);$length=$v===null?0:strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2;if($v===null||str_contains($v,"\0")||$length<$min||$length>$max)self::fail();return $v;}
    public static function validate(array $input):array
    {
        if(array_diff(array_keys($input),['id','name','email','phone','groupId','status','creditEnabled','creditLimit','creditDays','version','password'])||!self::uuid($input['id'] ?? null)||!self::uuid($input['groupId'] ?? null)||!in_array($input['status'] ?? null,['ACTIVE','PAUSED','DISABLED'],true)||!is_bool($input['creditEnabled'] ?? null)||!is_int($input['creditLimit'] ?? null)||$input['creditLimit']<0||$input['creditLimit']>1000000000000||!is_int($input['creditDays'] ?? null)||$input['creditDays']<1||$input['creditDays']>90||!is_int($input['version'] ?? null)||$input['version']<0||$input['version']>2147483646)self::fail();
        $email=$input['email'] ?? null;if(!is_string($email)||strlen($email)>200||!filter_var($email,FILTER_VALIDATE_EMAIL))self::fail('Email CTV không hợp lệ.');$out=['id'=>$input['id'],'name'=>self::text($input['name'] ?? null,100,1),'email'=>strtolower($email),'phone'=>self::text($input['phone'] ?? null,30),'groupId'=>$input['groupId'],'status'=>$input['status'],'creditEnabled'=>$input['creditEnabled'],'creditLimit'=>$input['creditLimit'],'creditDays'=>$input['creditDays'],'version'=>$input['version']];
        if(array_key_exists('password',$input)){$p=$input['password'];if(!is_string($p)||str_contains($p,"\0"))self::fail('Mật khẩu không hợp lệ.');$length=strlen(mb_convert_encoding($p,'UTF-16LE','UTF-8'))/2;if($length<12||$length>200)self::fail('Mật khẩu từ 12 đến 200 ký tự.');if(!defined('PASSWORD_ARGON2ID')&&strlen($p)>72)self::fail('Hosting chưa hỗ trợ Argon2id; dùng mật khẩu tối đa 72 byte.');$out['password']=$p;}elseif($out['version']===0)self::fail('Tài khoản mới cần mật khẩu ít nhất 12 ký tự.');return $out;
    }
    public function list():array
    {
        $out=[];foreach($this->query('SELECT id,email,data,version FROM jingo_partners ORDER BY email,id')->fetchAll(PDO::FETCH_ASSOC) as $row){$data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$out[]=['id'=>$row['id'],'email'=>$row['email']]+array_intersect_key($data,array_flip(['name','phone','groupId','status','creditEnabled','creditLimit','creditDays']))+['version'=>(int)$row['version']];}return $out;
    }
    public function save(array $input,string $actor):array
    {
        $data=self::validate($input);$password=$data['password'] ?? null;unset($data['password']);$hash=$password!==null?password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT):null;
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            // Shared with PriceGroups::save, so assigning and archiving cannot race.
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');$group=$this->query('SELECT data FROM jingo_price_groups WHERE id=? LOCK IN SHARE MODE',[$data['groupId']])->fetchColumn();if($group===false||(json_decode($group,true,512,JSON_THROW_ON_ERROR)['status'] ?? '')!=='ACTIVE')self::fail('Chọn nhóm giá đang hoạt động.');
            $version=$this->query('SELECT version FROM jingo_partners WHERE id=? FOR UPDATE',[$data['id']])->fetchColumn();if(($version===false&&$data['version']!==0)||($version!==false&&(int)$version!==$data['version']))throw new HttpError(409,'CTV đã thay đổi hoặc mã đã tồn tại. Tải lại trước khi lưu.');$data['version']++;$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($version===false)$this->query('INSERT INTO jingo_partners(id,email,password_hash,data,version) VALUES (?,?,?,?,?)',[$data['id'],$data['email'],$hash,$json,$data['version']]);else{$this->query('UPDATE jingo_partners SET email=?,data=?,version=? WHERE id=?',[$data['email'],$json,$data['version'],$data['id']]);if($hash!==null)$this->query('UPDATE jingo_partners SET password_hash=? WHERE id=?',[$hash,$data['id']]);$this->query('DELETE FROM jingo_partner_sessions WHERE partner_id=?',[$data['id']]);}
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'partner.save',$data['id']]);$this->db->commit();return $data;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,'Email hoặc mã CTV đã tồn tại.');throw $e;}
    }
}
