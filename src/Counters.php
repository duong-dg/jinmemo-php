<?php
declare(strict_types=1);

final class Counters
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private static function text(array $input,string $key,int $limit,bool $required=false):string
    {
        $value=$input[$key] ?? null;if(!is_string($value))throw new HttpError(400,'Thông tin quầy không hợp lệ.');$value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);if($value===null||$required&&$value===''||strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$limit)throw new HttpError(400,'Kiểm tra độ dài tên và địa chỉ quầy.');return $value;
    }
    public static function validate(array $input):array
    {
        if(array_diff(array_keys($input),['id','name','nameEn','address','addressEn','status','version'])||!is_string($input['id'] ?? null)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$input['id'])||!is_int($input['version'] ?? null)||$input['version']<0||$input['version']>2147483646||!in_array($input['status'] ?? null,['ACTIVE','ARCHIVED'],true))throw new HttpError(400,'Mã, trạng thái hoặc phiên bản quầy không hợp lệ.');$out=['id'=>$input['id'],'name'=>self::text($input,'name',100,true),'status'=>$input['status'],'version'=>$input['version']];foreach(['nameEn'=>100,'address'=>500,'addressEn'=>500] as $key=>$limit)if(array_key_exists($key,$input))$out[$key]=self::text($input,$key,$limit);return $out;
    }
    public function list():array
    {
        $items=[];foreach($this->query('SELECT data,version FROM jingo_counters ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){$data=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$data['version']=(int)$row['version'];$items[]=$data;}return $items;
    }
    public function save(array $input,string $actor):array
    {
        $data=self::validate($input);$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            // Future shift open/close operations must take this same counter lock first.
            $current=$this->query('SELECT version FROM jingo_counters WHERE id=? FOR UPDATE',[$data['id']])->fetchColumn();if(($current===false&&$data['version']!==0)||($current!==false&&(int)$current!==$data['version']))throw new HttpError(409,'Quầy đã thay đổi hoặc mã quầy đã tồn tại. Tải lại trước khi lưu.');if($data['status']==='ARCHIVED'&&$this->query('SELECT shift_id FROM jingo_pos_open_shifts WHERE counter_id=? FOR UPDATE',[$data['id']])->fetchColumn())throw new HttpError(409,'Chốt ca đang mở trước khi lưu trữ quầy.');$data['version']++;$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if($current===false)$this->query('INSERT INTO jingo_counters(id,data,version) VALUES (?,?,?)',[$data['id'],$json,$data['version']]);else $this->query('UPDATE jingo_counters SET data=?,version=? WHERE id=?',[$json,$data['version'],$data['id']]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'counter.save',$data['id']]);$this->db->commit();return $data;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,'Mã quầy đã tồn tại.');throw $e;}
    }
}
