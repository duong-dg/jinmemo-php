<?php
declare(strict_types=1);
require_once __DIR__.'/Partners.php';

final class PartnerAuth
{
    public function __construct(private PDO $db,private array $config){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function mutation():void
    {
        if(($_SERVER['HTTP_X_JINMEMO_PARTNER'] ?? '')!=='1')throw new HttpError(403,'Thiếu xác minh yêu cầu CTV.');
        $url=parse_url($this->config['PUBLIC_BASE_URL'] ?? '');if(!$url||!isset($url['scheme'],$url['host'])||!in_array($url['scheme'],['http','https'],true))throw new HttpError(503,'Chưa cấu hình PUBLIC_BASE_URL.');
        if(($this->config['APP_ENV'] ?? 'production')==='production'&&$url['scheme']!=='https')throw new HttpError(503,'Hosting cần HTTPS.');
        $origin=$url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'');if(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==$origin)throw new HttpError(403,'Nguồn yêu cầu không hợp lệ.');
    }
    private function token():?string{$token=$_COOKIE['jingo_partner'] ?? null;return is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)?$token:null;}
    private function cookie(string $token,int $expires):void
    {
        setcookie('jingo_partner',$token,['expires'=>$expires,'path'=>'/api/v1/partner','secure'=>str_starts_with($this->config['PUBLIC_BASE_URL'] ?? '','https://'),'httponly'=>true,'samesite'=>'Strict']);
    }
    private function throttle(string $key,int $limit):void
    {
        $id=hash('sha256',$key);$this->db->beginTransaction();try{
            $this->query('DELETE FROM jingo_partner_login_limits WHERE until_at<=UTC_TIMESTAMP()');
            $this->query('INSERT INTO jingo_partner_login_limits(id,until_at) VALUES (?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE id=id',[$id]);
            $count=min($limit+1,(int)$this->query('SELECT attempts FROM jingo_partner_login_limits WHERE id=? FOR UPDATE',[$id])->fetchColumn()+1);$this->query('UPDATE jingo_partner_login_limits SET attempts=? WHERE id=?',[$count,$id]);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        if($count>$limit)throw new HttpError(429,'Thử lại sau 15 phút.');
    }
    public function login(array $input):array
    {
        $this->mutation();$email=$input['email'] ?? null;$password=$input['password'] ?? null;
        if(array_diff(array_keys($input),['email','password'])||!is_string($email)||strlen($email)>200||!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||$password===''||str_contains($password,"\0")||strlen(mb_convert_encoding($password,'UTF-16LE','UTF-8'))/2>200)throw new HttpError(400,'Email hoặc mật khẩu không hợp lệ.');
        $email=strtolower($email);$this->throttle('ip:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'),50);$this->throttle('account:'.$email,5);
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            $row=$this->query('SELECT id,email,password_hash,data,version FROM jingo_partners WHERE email=? LOCK IN SHARE MODE',[$email])->fetch(PDO::FETCH_ASSOC);
            $hash=$row['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $ok=password_verify($password,$hash)&&!(preg_match('/^\$2[aby]\$/',$hash)&&strlen($password)>72);$data=$row?json_decode($row['data'],true,512,JSON_THROW_ON_ERROR):null;
            if(!$ok||!$row||!in_array($data['status'] ?? '',['ACTIVE','PAUSED'],true))throw new HttpError(401,'Email hoặc mật khẩu không đúng.');
            $account=$this->account($row);$token=bin2hex(random_bytes(32));$old=$this->token();if($old)$this->query("DELETE FROM jingo_partner_sessions WHERE token_hash=?",[hash('sha256',$old)]);
            $this->query('DELETE FROM jingo_partner_sessions WHERE expires_at<=UTC_TIMESTAMP()');$this->query("INSERT INTO jingo_partner_sessions(token_hash,partner_id,expires_at) VALUES (?,?,?)",[hash('sha256',$token),$row['id'],gmdate('Y-m-d H:i:s',time()+28800)]);
            $this->query('DELETE FROM jingo_partner_login_limits WHERE id=?',[hash('sha256','account:'.$email)]);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $this->cookie($token,time()+28800);return ['name'=>$account['name'],'email'=>$account['email']];
    }
    private function account(array $row):array
    {
        $data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$data=array_intersect_key($data,array_flip(['name','phone','groupId','status','creditEnabled','creditLimit','creditDays']));$data=['id'=>$row['id'],'email'=>$row['email'],'version'=>(int)$row['version']]+$data;
        try{$data=Partners::validate($data);}catch(HttpError){throw new HttpError(401,'Tài khoản CTV không còn hợp lệ.');}if($data['status']==='DISABLED')throw new HttpError(401,'Phiên CTV đã hết hạn hoặc tài khoản đã khóa.');return $data;
    }
    public function me():array
    {
        $token=$this->token();if(!$token)throw new HttpError(401,'Vui lòng đăng nhập CTV.');$row=$this->query('SELECT p.id,p.email,p.data,p.version FROM jingo_partner_sessions s JOIN jingo_partners p ON p.id=s.partner_id WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP()',[hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new HttpError(401,'Phiên CTV đã hết hạn hoặc tài khoản đã khóa.');$account=$this->account($row);
        $raw=$this->query('SELECT data FROM jingo_price_groups WHERE id=?',[$account['groupId']])->fetchColumn();$g=$raw===false?null:json_decode($raw,true,512,JSON_THROW_ON_ERROR);$group=$g?['id'=>$account['groupId'],'name'=>$g['name'] ?? '', 'status'=>$g['status'] ?? 'ARCHIVED']:null;require_once __DIR__.'/PartnerCredit.php';return ['account'=>$account,'group'=>$group,'credit'=>(new PartnerCredit($this->db))->ledger($account['id'],$account['id'])];
    }
    public function logout():array
    {
        $this->mutation();$token=$this->token();if($token)$this->query("DELETE FROM jingo_partner_sessions WHERE token_hash=?",[hash('sha256',$token)]);$this->cookie('',time()-3600);return ['ok'=>true];
    }
}
