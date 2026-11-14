<?php
declare(strict_types=1);

final class PosAuth
{
    public function __construct(private PDO $db,private array $config){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function mutation():void
    {
        if(($_SERVER['HTTP_X_JINGO_POS'] ?? '')!=='1')throw new HttpError(403,'Thiếu xác minh yêu cầu quầy.');
        $url=parse_url($this->config['PUBLIC_BASE_URL'] ?? '');if(!$url||!isset($url['scheme'],$url['host'])||!in_array($url['scheme'],['http','https'],true))throw new HttpError(503,'Chưa cấu hình PUBLIC_BASE_URL.');
        if(($this->config['APP_ENV'] ?? 'production')==='production'&&$url['scheme']!=='https')throw new HttpError(503,'Hosting cần HTTPS.');
        $origin=$url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'');if(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin)throw new HttpError(403,'Nguồn yêu cầu không hợp lệ.');
    }
    private function token():?string{$token=$_COOKIE['jingo_pos'] ?? null;return is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)?$token:null;}
    private function cookie(string $token,int $expires):void
    {
        setcookie('jingo_pos',$token,['expires'=>$expires,'path'=>'/api/v1/pos','secure'=>str_starts_with($this->config['PUBLIC_BASE_URL'] ?? '','https://'),'httponly'=>true,'samesite'=>'Strict']);
    }
    private function throttle(string $key,int $limit):void
    {
        $id=hash('sha256',$key);$this->db->beginTransaction();try{
            $this->query('DELETE FROM jingo_pos_login_limits WHERE until_at<=UTC_TIMESTAMP()');
            $this->query('INSERT INTO jingo_pos_login_limits(id,until_at) VALUES (?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE id=id',[$id]);
            $count=min($limit+1,(int)$this->query('SELECT attempts FROM jingo_pos_login_limits WHERE id=? FOR UPDATE',[$id])->fetchColumn()+1);$this->query('UPDATE jingo_pos_login_limits SET attempts=? WHERE id=?',[$count,$id]);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        if($count>$limit)throw new HttpError(429,'Thử lại sau 15 phút.');
    }
    public function login(array $input):array
    {
        $this->mutation();$email=$input['email'] ?? null;$password=$input['password'] ?? null;
        if(array_diff(array_keys($input),['email','password'])||!is_string($email)||strlen($email)>200||!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||$password===''||str_contains($password,"\0")||strlen(mb_convert_encoding($password,'UTF-16LE','UTF-8'))/2>200)throw new HttpError(400,'Email hoặc mật khẩu không hợp lệ.');
        $email=strtolower($email);$this->throttle('ip:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'),50);$this->throttle('account:'.$email,5);
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            $row=$this->query('SELECT id,password_hash,data FROM jingo_pos_users WHERE email=? LOCK IN SHARE MODE',[$email])->fetch(PDO::FETCH_ASSOC);
            $hash=$row['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $ok=password_verify($password,$hash)&&!(preg_match('/^\$2[aby]\$/',$hash)&&strlen($password)>72);$data=$row?json_decode($row['data'],true,512,JSON_THROW_ON_ERROR):null;
            if(!$ok||!$row||($data['status'] ?? '')!=='ACTIVE')throw new HttpError(401,'Email hoặc mật khẩu không đúng.');
            $token=bin2hex(random_bytes(32));$old=$this->token();if($old)$this->query("DELETE FROM jingo_pos_sessions WHERE token_hash=? AND role='STAFF'",[hash('sha256',$old)]);
            $this->query('DELETE FROM jingo_pos_sessions WHERE expires_at<=UTC_TIMESTAMP()');$this->query("INSERT INTO jingo_pos_sessions(token_hash,user_id,role,expires_at) VALUES (?,?,'STAFF',?)",[hash('sha256',$token),$row['id'],gmdate('Y-m-d H:i:s',time()+28800)]);
            $this->query('DELETE FROM jingo_pos_login_limits WHERE id=?',[hash('sha256','account:'.$email)]);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $this->cookie($token,time()+28800);return ['ok'=>true];
    }
    public function me():array
    {
        $token=$this->token();if(!$token)throw new HttpError(401,'Vui lòng đăng nhập quầy.');
        $row=$this->query('SELECT s.role,u.id,u.email,u.data FROM jingo_pos_sessions s JOIN jingo_pos_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP()',[hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['role']!=='STAFF')throw new HttpError(401,'Phiên đăng nhập quầy đã hết hạn.');$data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);
        if(($data['status'] ?? '')!=='ACTIVE'||!is_string($data['name'] ?? null)||!is_array($data['counterIds'] ?? null)||!is_array($data['permissions'] ?? null)||!array_is_list($data['counterIds'])||!array_is_list($data['permissions'])||count($data['counterIds'])>50||count($data['permissions'])>6)throw new HttpError(401,'Tài khoản quầy không còn hợp lệ.');
        foreach($data['counterIds'] as $id)if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id))throw new HttpError(401,'Quầy được gán không còn hợp lệ.');
        foreach($data['permissions'] as $permission)if(!is_string($permission)||!in_array($permission,PosUsers::PERMISSIONS,true))throw new HttpError(401,'Quyền nhân viên không còn hợp lệ.');
        return ['id'=>$row['id'],'email'=>$row['email'],'name'=>$data['name'],'role'=>'STAFF','counterIds'=>$data['counterIds'],'permissions'=>$data['permissions']];
    }
    public static function permit(array $actor,string $permission):void{if(!in_array($permission,$actor['permissions'],true))throw new HttpError(403,'Bạn chưa được cấp quyền thực hiện thao tác này.');}
    public function logout():array
    {
        $this->mutation();$token=$this->token();if($token)$this->query("DELETE FROM jingo_pos_sessions WHERE token_hash=? AND role='STAFF'",[hash('sha256',$token)]);$this->cookie('',time()-3600);return ['ok'=>true];
    }
}
