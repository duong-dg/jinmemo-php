<?php
declare(strict_types=1);

final class ProductTypes
{
    public function __construct(private PDO $db) {}
    private function query(string $sql,array $args=[]): PDOStatement
    {
        $s=$this->db->prepare($sql); $s->execute($args); return $s;
    }
    private function unpack(array $row): array
    {
        return array_merge(json_decode($row['data'],true,512,JSON_THROW_ON_ERROR),['version'=>(int)$row['version']]);
    }
    public static function sourceHash(array $type): string
    {
        // Matches Node JSON.stringify insertion order, including Unicode and slashes.
        return hash('sha256',json_encode(['name'=>$type['name'],'description'=>$type['description'] ?? ''],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
    }
    public static function translated(array $type): bool
    {
        return ($type['english']['status'] ?? '')==='PUBLISHED' && ($type['english']['sourceHash'] ?? '')===self::sourceHash($type);
    }
    private static function text(array $data,string $key,int $max,bool $required=false): string
    {
        $value=$data[$key] ?? ($required ? null : '');
        if (!is_string($value)) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
        $value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);
        // Zod/JavaScript length counts UTF-16 code units.
        if ($value===null || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max || ($required && $value==='')) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
        return $value;
    }
    public static function validate(array $input): array
    {
        if (array_diff(array_keys($input),['id','slug','name','description','bookingKind','status','version','english'])) throw new HttpError(400,'Có trường dữ liệu không được hỗ trợ.');
        if (!is_string($input['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$input['id'])) throw new HttpError(400,'Mã loại không hợp lệ.');
        if (!is_string($input['slug'] ?? null) || strlen($input['slug'])>160 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$input['slug'])) throw new HttpError(400,'Đường dẫn loại không hợp lệ.');
        if (!in_array($input['bookingKind'] ?? null,['TICKET','TOUR'],true) || !in_array($input['status'] ?? null,['ACTIVE','ARCHIVED'],true)) throw new HttpError(400,'Trạng thái hoặc cách đặt không hợp lệ.');
        if (!is_int($input['version'] ?? null) || $input['version']<0 || $input['version']>2147483646) throw new HttpError(400,'Phiên bản không hợp lệ.');
        $type=['id'=>$input['id'],'slug'=>$input['slug'],'name'=>self::text($input,'name',100,true),'description'=>self::text($input,'description',1000),'bookingKind'=>$input['bookingKind'],'status'=>$input['status'],'version'=>$input['version']];
        if (array_key_exists('english',$input)) {
            $en=$input['english'];
            if (!is_array($en) || array_diff(array_keys($en),['name','description','status','sourceHash']) || !array_key_exists('name',$en) || !array_key_exists('description',$en)) throw new HttpError(400,'Bản dịch không hợp lệ.');
            $status=$en['status'] ?? 'DRAFT';
            if (!in_array($status,['DRAFT','PUBLISHED','NEEDS_REVIEW'],true)) throw new HttpError(400,'Trạng thái bản dịch không hợp lệ.');
            $type['english']=['name'=>self::text($en,'name',100),'description'=>self::text($en,'description',1000),'status'=>$status];
            if (array_key_exists('sourceHash',$en)) {
                if (!is_string($en['sourceHash']) || !preg_match('/^[a-f0-9]{64}$/D',$en['sourceHash'])) throw new HttpError(400,'Mã nguồn bản dịch không hợp lệ.');
                $type['english']['sourceHash']=$en['sourceHash'];
            }
            if ($status==='PUBLISHED' && !self::translated($type)) $type['english']['status']='NEEDS_REVIEW';
        }
        return $type;
    }
    private function publicView(array $type,string $lang): array
    {
        $english=$type['english'] ?? null; $languages=self::translated($type)?['vi','en']:['vi'];
        unset($type['english'],$type['version']);
        if ($lang==='en') { $type['name']=$english['name']; $type['description']=$english['description']; }
        return array_merge($type,['languages'=>$languages]);
    }
    public function list(bool $admin=false,string $lang='vi'): array
    {
        $result=[];
        foreach ($this->query('SELECT data,version FROM jingo_product_types ORDER BY slug')->fetchAll() as $row) {
            $type=$this->unpack($row);
            if ($admin) { $result[]=$type; continue; }
            if ($type['status']!=='ACTIVE' || ($lang==='en' && !self::translated($type))) continue;
            $result[]=$this->publicView($type,$lang);
        }
        return $result;
    }
    public function find(string $slug,string $lang='vi'): array
    {
        $row=$this->query('SELECT data,version FROM jingo_product_types WHERE slug=?',[$slug])->fetch();
        if (!$row) $row=$this->query("SELECT t.data,t.version FROM jingo_slug_aliases a JOIN jingo_product_types t ON t.id=a.entity_id WHERE a.kind='TYPE' AND a.slug=?",[$slug])->fetch();
        if (!$row) throw new HttpError(404,'Không tìm thấy loại sản phẩm.');
        $type=$this->unpack($row);
        if ($type['status']!=='ACTIVE' || ($lang==='en' && !self::translated($type))) throw new HttpError(404,'Không tìm thấy loại sản phẩm.');
        return $this->publicView($type,$lang);
    }
    public function save(array $input,string $adminId): array
    {
        $type=self::validate($input); $this->db->beginTransaction();
        try {
            // Product writes in later modules must acquire this same lock first.
            if (!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn()) throw new HttpError(503,'Chưa cài khóa danh mục.');
            $current=$this->query('SELECT slug,version FROM jingo_product_types WHERE id=? FOR UPDATE',[$type['id']])->fetch();
            if (($type['version']===0 && $current) || ($type['version']>0 && (!$current || (int)$current['version']!==$type['version']))) throw new HttpError(409,'Loại đã thay đổi. Tải lại trước khi lưu.');
            $owner=$this->query("SELECT entity_id FROM jingo_slug_aliases WHERE kind='TYPE' AND slug=?",[$type['slug']])->fetchColumn();
            if ($owner!==false && $owner!==$type['id']) throw new HttpError(409,'Đường dẫn đang thuộc nội dung khác.');
            if ($type['status']==='ARCHIVED') {
                foreach ($this->query('SELECT data FROM jingo_products')->fetchAll() as $row) {
                    if ((json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)['type'] ?? '')===$type['id']) throw new HttpError(409,'Loại đang có sản phẩm. Chuyển sản phẩm sang loại khác trước khi lưu trữ.');
                }
            }
            $this->query("DELETE FROM jingo_slug_aliases WHERE kind='TYPE' AND slug=? AND entity_id=?",[$type['slug'],$type['id']]);
            if ($current && $current['slug']!==$type['slug']) $this->query("INSERT INTO jingo_slug_aliases(kind,slug,entity_id) VALUES ('TYPE',?,?) ON DUPLICATE KEY UPDATE entity_id=VALUES(entity_id)",[$current['slug'],$type['id']]);
            $type['version']++;
            $json=json_encode($type,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if ($current) $this->query('UPDATE jingo_product_types SET slug=?,data=?,version=? WHERE id=?',[$type['slug'],$json,$type['version'],$type['id']]);
            else $this->query('INSERT INTO jingo_product_types(id,slug,data,version) VALUES (?,?,?,?)',[$type['id'],$type['slug'],$json,$type['version']]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$adminId,'product-type.save',$type['id']]);
            $this->db->commit(); return $type;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? null)===1062) throw new HttpError(409,'Mã hoặc đường dẫn loại đã tồn tại.');
            throw $e;
        }
    }
}
