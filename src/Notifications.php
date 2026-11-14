<?php
declare(strict_types=1);
final class Notifications
{
    private const MAX=9007199254740991;
    private const KINDS=['TICKET'=>'last_ticket_id','CAR'=>'last_car_id','STAY'=>'last_stay_id','GIFT'=>'last_gift_id'];
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function number(mixed $v):int{if(!is_int($v)&&!(is_string($v)&&ctype_digit($v)))throw new HttpError(409,'Dữ liệu thông báo không hợp lệ.');if($v<0||$v>self::MAX)throw new HttpError(409,'Mã thông báo vượt giới hạn xử lý.');return (int)$v;}
    public function enqueue(string $kind,string $code):void
    {
        if(!$this->db->inTransaction())throw new LogicException('Notification requires an order transaction.');
        if(!in_array($kind,['TICKET','GIFT'],true))throw new LogicException('Notification producer is not supported.');
        // Serialize event IDs through commit so read cursors cannot skip a late commit.
        if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='notifications' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa thông báo.');$this->query('INSERT INTO jingo_admin_notifications(kind,code,href) VALUES (?,?,?)',[$kind,$code,$kind==='GIFT'?'#gifts':'#orders']);self::number($this->db->lastInsertId());
    }
    private function cursors(array $r):array{$out=[];foreach(self::KINDS as $kind=>$column)$out[$kind]=self::number($r[$column] ?? $r['last_id'] ?? 0);return $out;}
    public function list(string $admin):array
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{$read=$this->query('SELECT * FROM jingo_admin_notification_reads WHERE admin_id=?',[$admin])->fetch(PDO::FETCH_ASSOC)?:[];$cursors=$this->cursors($read);$latest=self::number($this->query('SELECT COALESCE(MAX(id),0) FROM jingo_admin_notifications')->fetchColumn());$counts=array_fill_keys(array_keys(self::KINDS),0);$where=[];foreach(self::KINDS as $kind=>$column)$where[]="(kind='".$kind."' AND id>?)";$rows=$this->query('SELECT kind,COUNT(*) AS total FROM jingo_admin_notifications WHERE '.implode(' OR ',$where).' GROUP BY kind',array_values($cursors))->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $r)$counts[$r['kind']]=self::number($r['total']);$items=$this->query('SELECT id,kind,code,created_at FROM jingo_admin_notifications ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);foreach($items as &$r){$r['id']=self::number($r['id']);$r['unread']=$r['id']>($cursors[$r['kind']] ?? 0);$r['href']=match($r['kind']){'TICKET'=>'#orders','GIFT'=>'#gifts',default=>null};}unset($r);$out=['data'=>$items,'cursor'=>$latest,'ticket'=>$counts['TICKET'],'car'=>$counts['CAR'],'stay'=>$counts['STAY'],'gift'=>$counts['GIFT'],'total'=>self::number(array_sum($counts))];$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function read(string $admin,array $input):array
    {
        if(array_diff(array_keys($input),['cursor','kind'])||array_key_exists('kind',$input)&&!is_string($input['kind'])||!is_int($input['cursor'] ?? null)||$input['cursor']<0||$input['cursor']>self::MAX||!in_array($input['kind'] ?? 'ALL',['ALL',...array_keys(self::KINDS)],true))throw new HttpError(400,'Yêu cầu đọc thông báo không hợp lệ.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{if(!$this->query('SELECT id FROM jingo_admins WHERE id=? FOR UPDATE',[$admin])->fetchColumn())throw new HttpError(404,'Không tìm thấy tài khoản.');$last=self::number($this->query('SELECT COALESCE(MAX(id),0) FROM jingo_admin_notifications')->fetchColumn());$old=$this->query('SELECT * FROM jingo_admin_notification_reads WHERE admin_id=?',[$admin])->fetch(PDO::FETCH_ASSOC)?:[];$cursor=min($last,$input['cursor']);$kind=$input['kind'] ?? 'ALL';$values=$this->cursors($old);foreach($values as $k=>&$value)if($kind==='ALL'||$kind===$k)$value=max($value,$cursor);unset($value);$legacy=self::number($old['last_id'] ?? 0);if($kind==='ALL')$legacy=max($legacy,$cursor);$this->query('INSERT INTO jingo_admin_notification_reads(admin_id,last_id,last_ticket_id,last_car_id,last_stay_id,last_gift_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE last_id=VALUES(last_id),last_ticket_id=VALUES(last_ticket_id),last_car_id=VALUES(last_car_id),last_stay_id=VALUES(last_stay_id),last_gift_id=VALUES(last_gift_id)',[$admin,$legacy,...array_values($values)]);$this->db->commit();return ['ok'=>true];}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
