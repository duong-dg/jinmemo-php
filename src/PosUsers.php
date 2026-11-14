<?php
declare(strict_types=1);

final class PosUsers
{
    public const PERMISSIONS=['SELL','COLLECT','DELIVER','PRINT','CANCEL','SHIFT'];
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public static function validate(array $input):array
    {
        if(array_diff(array_keys($input),['id','name','email','status','counterIds','permissions','version','password'])||!is_string($input['id'] ?? null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$input['id'])||!is_int($input['version'] ?? null)||$input['version']<0||$input['version']>2147483646||!in_array($input['status'] ?? null,['ACTIVE','DISABLED'],true))throw new HttpError(400,'Mã, trạng thái hoặc phiên bản nhân viên không hợp lệ.');
        $name=$input['name'] ?? null;if(!is_string($name))throw new HttpError(400,'Nhập tên nhân viên.');$name=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$name);
        if($name===null||$name===''||strlen(mb_convert_encoding($name,'UTF-16LE','UTF-8'))/2>100)throw new HttpError(400,'Tên nhân viên từ 1 đến 100 ký tự.');
        $email=$input['email'] ?? null;if(!is_string($email)||strlen($email)>200||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new HttpError(400,'Email nhân viên không hợp lệ.');$email=strtolower($email);
        foreach(['counterIds'=>50,'permissions'=>6] as $key=>$max){
            $values=$input[$key] ?? null;if(!is_array($values)||!array_is_list($values)||count($values)>$max)throw new HttpError(400,'Danh sách quầy hoặc quyền không hợp lệ.');
            foreach($values as $value)if(!is_string($value)||($key==='counterIds'?!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$value):!in_array($value,self::PERMISSIONS,true)))throw new HttpError(400,'Quầy hoặc quyền không hợp lệ.');
            if(count(array_unique($values,SORT_STRING))!==count($values))throw new HttpError(400,'Quầy hoặc quyền bị trùng.');
        }
        if($input['status']==='ACTIVE'&&!$input['counterIds'])throw new HttpError(400,'Gán ít nhất một quầy cho nhân viên hoạt động.');
        $out=['id'=>$input['id'],'name'=>$name,'email'=>$email,'status'=>$input['status'],'counterIds'=>$input['counterIds'],'permissions'=>$input['permissions'],'version'=>$input['version']];
        if(array_key_exists('password',$input)){
            $p=$input['password'];if(!is_string($p))throw new HttpError(400,'Mật khẩu không hợp lệ.');$length=strlen(mb_convert_encoding($p,'UTF-16LE','UTF-8'))/2;
            if(str_contains($p,"\0")||$length<12||$length>200)throw new HttpError(400,'Mật khẩu từ 12 đến 200 ký tự, không chứa ký tự NUL.');
            if(!defined('PASSWORD_ARGON2ID')&&strlen($p)>72)throw new HttpError(400,'Hosting chưa hỗ trợ Argon2id; dùng mật khẩu tối đa 72 byte.');$out['password']=$p;
        }elseif($input['version']===0)throw new HttpError(400,'Tài khoản mới cần mật khẩu ít nhất 12 ký tự.');
        return $out;
    }
    public function list():array
    {
        $items=[];foreach($this->query('SELECT id,email,data,version FROM jingo_pos_users ORDER BY email,id')->fetchAll(PDO::FETCH_ASSOC) as $row){
            $data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$data=array_intersect_key($data,array_flip(['name','status','counterIds','permissions']));
            $items[]=['id'=>$row['id'],'email'=>$row['email']]+$data+['version'=>(int)$row['version']];
        }return $items;
    }
    public function save(array $input,string $actor):array
    {
        $data=self::validate($input);$password=$data['password'] ?? null;unset($data['password']);
        $hash=$password!==null?password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT):null;
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            if($this->query('SELECT id FROM jingo_admins WHERE email=? LOCK IN SHARE MODE',[$data['email']])->fetchColumn())throw new HttpError(400,'Email này là tài khoản quản trị. Dùng email riêng cho nhân viên quầy.');
            $ids=$data['counterIds'];sort($ids,SORT_STRING);foreach($ids as $id)if($this->query('SELECT id FROM jingo_counters WHERE id=? LOCK IN SHARE MODE',[$id])->fetchColumn()===false)throw new HttpError(400,'Quầy được gán không tồn tại.');
            $version=$this->query('SELECT version FROM jingo_pos_users WHERE id=? FOR UPDATE',[$data['id']])->fetchColumn();
            if(($version===false&&$data['version']!==0)||($version!==false&&(int)$version!==$data['version']))throw new HttpError(409,'Tài khoản đã thay đổi hoặc mã đã tồn tại. Tải lại trước khi lưu.');
            $data['version']++;$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($version===false)$this->query('INSERT INTO jingo_pos_users(id,email,password_hash,data,version) VALUES (?,?,?,?,?)',[$data['id'],$data['email'],$hash,$json,$data['version']]);
            else{
                $this->query('UPDATE jingo_pos_users SET email=?,data=?,version=? WHERE id=?',[$data['email'],$json,$data['version'],$data['id']]);
                if($hash!==null)$this->query('UPDATE jingo_pos_users SET password_hash=? WHERE id=?',[$hash,$data['id']]);
                $this->query("DELETE FROM jingo_pos_sessions WHERE user_id=? AND role='STAFF'",[$data['id']]);
            }
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'pos-user.save',$data['id']]);
            $this->db->commit();return $data;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,'Email hoặc mã nhân viên đã tồn tại.');throw $e;}
    }
}
