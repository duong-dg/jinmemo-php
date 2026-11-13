<?php
declare(strict_types=1);

final class PostInsights
{
    public function __construct(private PDO $db,private array $config){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function record(string $id,array $query,array $body,array $server,array $cookies,?DateTimeImmutable $now=null):array
    {
        if(array_diff(array_keys($query),['lang'])||$body!==[]||!preg_match('/^[a-f0-9-]{36}$/iD',$id)||!in_array($query['lang'] ?? 'vi',['vi','en'],true))throw new HttpError(400,'Bài viết không hợp lệ.');$id=strtolower($id);$lang=$query['lang'] ?? 'vi';$parts=parse_url($this->config['PUBLIC_BASE_URL'] ?? '');if(!$parts||!isset($parts['scheme'],$parts['host']))throw new HttpError(503,'Chưa cấu hình PUBLIC_BASE_URL.');$origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');if(($server['HTTP_ORIGIN'] ?? '')!==$origin||($server['HTTP_X_JINGO_REQUEST'] ?? $server['HTTP_X_JINMEMO_REQUEST'] ?? '')!=='1')throw new HttpError(403,'Nguồn yêu cầu không hợp lệ.');
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));$day=$now->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');$ua=substr($server['HTTP_USER_AGENT'] ?? '',0,512);$bot=$ua===''||preg_match('/bot\b|crawler|spider|facebookexternalhit|facebot|meta-external|preview|slurp|headless|curl|wget/i',$ua);$token=$cookies['jingo_admin'] ?? '';$admin=is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)&&$this->query('SELECT admin_id FROM jingo_sessions WHERE token_hash=? AND expires_at>?',[hash('sha256',$token),$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')])->fetchColumn();
        $this->db->beginTransaction();try{
            $row=$this->query('SELECT data FROM jingo_posts WHERE id=? FOR UPDATE',[$id])->fetchColumn();$post=$row===false?null:json_decode($row,true,512,JSON_THROW_ON_ERROR);if(!$post||$post['status']!=='PUBLISHED'||($post['publishedAt'] ?? null)&&new DateTimeImmutable($post['publishedAt'])>$now||$lang==='en'&&!in_array($post['english']['status'] ?? '',['PUBLISHED','NEEDS_REVIEW'],true))throw new HttpError(404,'Bài viết chưa xuất bản.');$views=(string)($this->query('SELECT views FROM jingo_post_metrics WHERE post_id=?',[$id])->fetchColumn() ?: '0');$counted=false;
            if(!$bot&&!$admin){
                $this->query("INSERT INTO jingo_settings(id,data) VALUES ('post-view-key',?) ON DUPLICATE KEY UPDATE id=id",[json_encode(['key'=>bin2hex(random_bytes(32))],JSON_THROW_ON_ERROR)]);$secret=json_decode($this->query("SELECT data FROM jingo_settings WHERE id='post-view-key'")->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['key'] ?? '';if(!is_string($secret)||!preg_match('/^[a-f0-9]{64}$/D',$secret))throw new HttpError(503,'Khóa thống kê không hợp lệ.');$fingerprint=hash_hmac('sha256',json_encode([$id,$day,$server['REMOTE_ADDR'] ?? '',$ua],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),$secret);$seen=$this->query('SELECT fingerprint FROM jingo_post_view_keys WHERE post_id=? AND fingerprint=? AND view_day=?',[$id,$fingerprint,$day])->fetchColumn();
                if(!$seen&&$views!=='18446744073709551615'){$this->query('INSERT INTO jingo_post_view_keys(post_id,fingerprint,view_day) VALUES (?,?,?)',[$id,$fingerprint,$day]);$this->query('INSERT INTO jingo_post_metrics(post_id,views) VALUES (?,1) ON DUPLICATE KEY UPDATE views=views+1',[$id]);$views=(string)$this->query('SELECT views FROM jingo_post_metrics WHERE post_id=?',[$id])->fetchColumn();$counted=true;}
            }
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        // Old anonymous fingerprints have no further purpose after the local day ends.
        try{$this->query('DELETE FROM jingo_post_view_keys WHERE view_day<?',[$day]);}catch(PDOException){}
        return ['views'=>strlen($views)<16||strlen($views)===16&&strcmp($views,'9007199254740991')<=0?(int)$views:$views,'counted'=>$counted];
    }
}
