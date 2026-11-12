<?php
declare(strict_types=1);

final class Suppliers
{
    public function __construct(private PDO $db) {}
    private function query(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private static function text(array $input,string $key,int $max,bool $required=false): string
    {
        $value=$input[$key] ?? ($required?null:'');
        if(!is_string($value)) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
        $value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);
        if($value===null || ($required && $value==='') || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
        return $value;
    }
    public static function validate(array $input): array
    {
        if(array_diff(array_keys($input),['id','name','contactName','phone','email','website','notes','status','version'])) throw new HttpError(400,'Có trường dữ liệu không được hỗ trợ.');
        if(!is_string($input['id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$input['id'])) throw new HttpError(400,'Mã nhà cung cấp không hợp lệ.');
        if(!is_int($input['version'] ?? null) || $input['version']<0 || $input['version']>2147483646 || !in_array($input['status'] ?? null,['ACTIVE','ARCHIVED'],true)) throw new HttpError(400,'Trạng thái hoặc phiên bản không hợp lệ.');
        $data=['id'=>$input['id'],'name'=>self::text($input,'name',150,true),'contactName'=>self::text($input,'contactName',100),'phone'=>self::text($input,'phone',30),'notes'=>self::text($input,'notes',4000),'status'=>$input['status'],'version'=>$input['version']];
        if(!preg_match('/^[+\d ()-]*$/D',$data['phone'])) throw new HttpError(400,'Số điện thoại không hợp lệ.');
        // The original schema does not trim email or website.
        foreach(['email'=>200,'website'=>500] as $key=>$max) {
            $value=$input[$key] ?? '';
            if(!is_string($value) || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
            $data[$key]=$value;
        }
        if($data['email']!=='' && !filter_var($data['email'],FILTER_VALIDATE_EMAIL)) throw new HttpError(400,'Email không hợp lệ.');
        if($data['website']!=='' && (!filter_var($data['website'],FILTER_VALIDATE_URL) || !preg_match('#^https?://#i',$data['website']))) throw new HttpError(400,'Website phải bắt đầu bằng http:// hoặc https://.');
        return $data;
    }
    public function list(): array
    {
        $counts=[];
        foreach($this->query('SELECT data FROM jingo_products')->fetchAll(PDO::FETCH_ASSOC) as $row) { $id=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)['supplierId'] ?? ''; if(is_string($id)) $counts[$id]=($counts[$id] ?? 0)+1; }
        $items=[];
        foreach($this->query('SELECT data,version FROM jingo_suppliers')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR); $item['version']=(int)$row['version']; $item['productCount']=$counts[$item['id']] ?? 0; $items[]=$item;
        }
        usort($items,fn($a,$b)=>strnatcasecmp($a['name'],$b['name'])); return $items;
    }
    public function save(array $input,string $actor): array
    {
        $data=self::validate($input); $this->db->beginTransaction();
        try {
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn()) throw new HttpError(503,'Chưa cài khóa danh mục.');
            $current=$this->query('SELECT version FROM jingo_suppliers WHERE id=? FOR UPDATE',[$data['id']])->fetchColumn();
            if(($current===false && $data['version']!==0) || ($current!==false && (int)$current!==$data['version'])) throw new HttpError(409,'Nhà cung cấp đã thay đổi. Tải lại trước khi lưu.');
            if($data['status']==='ARCHIVED') {
                if($this->query('SELECT id FROM jingo_supply_lots WHERE supplier_id=? AND enabled=1 LIMIT 1',[$data['id']])->fetchColumn()) throw new HttpError(409,'Nhà cung cấp còn lô đang bật. Tắt lô trước khi lưu trữ.');
                foreach($this->query('SELECT data FROM jingo_products')->fetchAll(PDO::FETCH_ASSOC) as $row) if((json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)['supplierId'] ?? '')===$data['id']) throw new HttpError(409,'Nhà cung cấp còn sản phẩm liên kết. Chuyển hoặc bỏ liên kết trước khi lưu trữ.');
            }
            $data['version']++; $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($current===false) $this->query('INSERT INTO jingo_suppliers(id,data,version) VALUES (?,?,?)',[$data['id'],$json,$data['version']]);
            else $this->query('UPDATE jingo_suppliers SET data=?,version=? WHERE id=?',[$json,$data['version'],$data['id']]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'supplier.save',$data['id']]);
            $counts=0; foreach($this->query('SELECT data FROM jingo_products')->fetchAll(PDO::FETCH_ASSOC) as $row) if((json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)['supplierId'] ?? '')===$data['id']) $counts++;
            $this->db->commit(); $data['productCount']=$counts; return $data;
        } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); if($e instanceof PDOException && ($e->errorInfo[1] ?? null)===1062) throw new HttpError(409,'Nhà cung cấp đã tồn tại.'); throw $e; }
    }
}
