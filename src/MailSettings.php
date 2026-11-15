<?php
declare(strict_types=1);
require_once __DIR__.'/SecretCipher.php';
final class MailSettings
{
    public function __construct(private PDO $db,private string $directory){}
    public function config():?array{$r=$this->db->query("SELECT data,version FROM jingo_settings WHERE id='smtp'")->fetch(PDO::FETCH_ASSOC);return $r?array_replace(json_decode($r['data'],true,512,JSON_THROW_ON_ERROR),['version'=>(int)$r['version']]):null;}
    public function read():array{$c=$this->config()??['enabled'=>false,'host'=>'','port'=>587,'security'=>'STARTTLS','user'=>'','fromEmail'=>'','fromName'=>'JinMemo','version'=>0];$out=array_intersect_key($c,array_flip(['enabled','host','port','security','user','fromEmail','fromName','version']));return $out+['passwordConfigured'=>!empty($c['encryptedPassword'])];}
    private static function text(mixed $v,int $min,int $max,bool $trim=true):string{if(!is_string($v)||preg_match('/[\x00-\x1f\x7f]/',$v))throw new HttpError(400,'Trường SMTP không hợp lệ.');if($trim)$v=trim($v);$n=strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2;if($n<$min||$n>$max)throw new HttpError(400,'Trường SMTP vượt giới hạn.');return $v;}
    public function save(array $in,string $actor):array
    {
        if(array_diff(array_keys($in),['enabled','host','port','security','user','password','fromEmail','fromName','version'])||!is_bool($in['enabled']??null)||!is_int($in['port']??null)||$in['port']<1||$in['port']>65535||!in_array($in['security']??null,['STARTTLS','TLS'],true)||!is_int($in['version']??null)||$in['version']<0||$in['version']>2147483646)throw new HttpError(400,'Kiểm tra cấu hình SMTP.');
        $data=['enabled'=>$in['enabled'],'host'=>self::text($in['host']??null,0,250),'port'=>$in['port'],'security'=>$in['security'],'user'=>self::text($in['user']??null,0,250),'fromEmail'=>self::text($in['fromEmail']??null,1,200),'fromName'=>self::text($in['fromName']??null,1,100)];if(!filter_var($data['fromEmail'],FILTER_VALIDATE_EMAIL)||!preg_match('/^[\x21-\x7e]+$/D',$data['fromEmail'])||$data['host']!==''&&!filter_var($data['host'],FILTER_VALIDATE_IP)&&!preg_match('/^(?=.{1,250}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D',$data['host']))throw new HttpError(400,'Máy chủ hoặc email gửi không hợp lệ.');$password=array_key_exists('password',$in)?self::text($in['password'],0,1000,false):'';
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            if(!$this->db->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');$old=$this->config();if(($old['version']??0)!==$in['version'])throw new HttpError(409,'SMTP vừa thay đổi. Tải lại trước khi lưu.');$encrypted=$password!==''?(new SecretCipher($this->directory))->encrypt($password):($old['encryptedPassword']??null);if($data['enabled']&&($data['host']===''||$data['user']===''||!$encrypted))throw new HttpError(400,'Nhập máy chủ, tài khoản và mật khẩu SMTP.');if($encrypted)$data['encryptedPassword']=$encrypted;
            $q=$this->db->prepare("INSERT INTO jingo_settings(id,data,version) VALUES ('smtp',?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),version=VALUES(version)");$q->execute([json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$in['version']+1]);$q=$this->db->prepare('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)');$q->execute([bin2hex(random_bytes(16)),$actor,'smtp.save','smtp']);$this->db->commit();return $this->read();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function test(array $input,?SmtpTransport $transport=null):array
    {
        if(count($input)!==1||!is_string($input['email']??null)||strlen($input['email'])>200||!filter_var($input['email'],FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$input['email']))throw new HttpError(400,'Email nhận thử không hợp lệ.');$c=$this->config();if(!$c||empty($c['encryptedPassword'])||$c['host']==='')throw new HttpError(400,'Lưu SMTP có mật khẩu trước khi gửi thử.');
        require_once __DIR__.'/SmtpTransport.php';try{$c['password']=(new SecretCipher($this->directory))->decrypt($c['encryptedPassword']);($transport??new SmtpTransport())->send($c,$input['email'],$c['fromName'].' · kiểm tra SMTP','Email kiểm tra SMTP. Không có đơn hoặc giao dịch thật.','TEST:'.bin2hex(random_bytes(16)));}catch(Throwable){throw new HttpError(400,'Gửi thử thất bại. Kiểm tra SMTP, TLS, tài khoản và quyền gửi From.');}return ['ok'=>true];
    }
    public function credentials():?array{$c=$this->config();if(!$c||!$c['enabled'])return null;$c['password']=(new SecretCipher($this->directory))->decrypt($c['encryptedPassword']);return $c;}
}
