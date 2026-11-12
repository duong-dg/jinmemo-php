<?php
declare(strict_types=1);

final class Destinations
{
    public function __construct(private PDO $db) {}
    private function query(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private static function uuid(mixed $value): bool { return is_string($value) && (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$value); }
    private static function text(array $input,string $key,int $max,bool $required=false): string
    {
        $value=$input[$key] ?? ($required?null:'');
        if(!is_string($value)) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
        $value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);
        if($value===null || ($required && $value==='') || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
        return $value;
    }
    private static function slug(mixed $slug): bool { return is_string($slug) && strlen($slug)<=160 && (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug); }
    public static function validate(array $input): array
    {
        if(array_diff(array_keys($input),['id','slug','name','description','englishName','englishDescription','englishSlug','parentId','status','sortOrder','version','coordinates','cover'])) throw new HttpError(400,'Có trường không được hỗ trợ.');
        if(!self::uuid($input['id'] ?? null) || !self::slug($input['slug'] ?? null)) throw new HttpError(400,'Mã hoặc đường dẫn điểm đến không hợp lệ.');
        if(!array_key_exists('parentId',$input) || ($input['parentId']!==null && !self::uuid($input['parentId']))) throw new HttpError(400,'Cấp cha không hợp lệ.');
        if(!is_int($input['version'] ?? null) || $input['version']<0 || $input['version']>2147483646 || !in_array($input['status'] ?? null,['ACTIVE','ARCHIVED'],true)) throw new HttpError(400,'Trạng thái hoặc phiên bản không hợp lệ.');
        $order=$input['sortOrder'] ?? 0;
        if(!is_int($order) || $order<0 || $order>10000) throw new HttpError(400,'Thứ tự không hợp lệ.');
        $node=['id'=>$input['id'],'slug'=>$input['slug'],'name'=>self::text($input,'name',150,true),'description'=>self::text($input,'description',2000),'englishName'=>self::text($input,'englishName',150),'englishDescription'=>self::text($input,'englishDescription',2000),'parentId'=>$input['parentId'],'status'=>$input['status'],'sortOrder'=>$order,'version'=>$input['version']];
        if(array_key_exists('englishSlug',$input)) {
            if($input['englishSlug']!=='' && !self::slug($input['englishSlug'])) throw new HttpError(400,'URL English không hợp lệ.');
            $node['englishSlug']=$input['englishSlug'];
        }
        if(array_key_exists('coordinates',$input)) {
            $c=$input['coordinates'];
            if($c!==null) {
                if(!is_array($c) || count($c)!==2 || !isset($c['lat'],$c['lon'])) throw new HttpError(400,'Tọa độ không hợp lệ.');
                foreach(['lat'=>90,'lon'=>180] as $key=>$max) if((!is_int($c[$key]) && !is_float($c[$key])) || !is_finite((float)$c[$key]) || abs($c[$key])>$max) throw new HttpError(400,'Tọa độ ngoài phạm vi.');
            }
            $node['coordinates']=$c;
        }
        if(array_key_exists('cover',$input)) {
            $c=$input['cover'];
            if($c!==null) {
                if(!is_array($c) || count($c)!==2 || !self::uuid($c['id'] ?? null)) throw new HttpError(400,'Ảnh bìa không hợp lệ.');
                $c=['id'=>$c['id'],'alt'=>self::text($c,'alt',300)];
            }
            $node['cover']=$c;
        }
        return $node;
    }
    public function list(bool $admin=false): array
    {
        $map=[];
        foreach($this->query('SELECT data,version FROM jingo_destinations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $node=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR); $node['version']=(int)$row['version']; $map[$node['id']]=$node;
        }
        $nodes=array_values(array_filter($map,function($node)use($admin,$map){
            if($admin) return true; $seen=[];
            while($node) {
                if($node['status']!=='ACTIVE' || isset($seen[$node['id']])) return false;
                $seen[$node['id']]=true;
                if(!$node['parentId']) return true;
                $node=$map[$node['parentId']] ?? null;
            }
            return false;
        }));
        usort($nodes,fn($a,$b)=>($a['sortOrder']<=>$b['sortOrder']) ?: strnatcasecmp($a['name'],$b['name']));
        return $nodes;
    }
    public static function publicView(array $node,string $lang='vi'): array
    {
        $vi=$node['slug']; $en=($node['englishSlug'] ?? '') ?: $vi;
        unset($node['version'],$node['status']);
        if($lang==='en') { $node['name']=$node['englishName'] ?: $node['name']; $node['description']=$node['englishDescription'] ?: ''; $node['slug']=$en; }
        $node['languages']=['vi','en']; $node['localePaths']=['vi'=>'/diem-den/'.$vi,'en'=>'/en/destinations/'.$en]; return $node;
    }
    public function find(string $slug,string $lang='vi'): array
    {
        $nodes=$this->list();
        foreach($nodes as $node) if(($lang==='en'?(($node['englishSlug'] ?? '') ?: $node['slug']):$node['slug'])===$slug) return self::publicView($node,$lang);
        $id=$this->query('SELECT entity_id FROM jingo_slug_aliases WHERE kind=? AND slug=?',[$lang==='en'?'DESTINATION_EN':'DESTINATION',$slug])->fetchColumn();
        foreach($nodes as $node) if($node['id']===$id) return self::publicView($node,$lang);
        if($lang==='en') foreach($nodes as $node) if($node['slug']===$slug) return self::publicView($node,$lang);
        throw new HttpError(404,'Không tìm thấy điểm đến.');
    }
    private function alias(string $kind,string $id,string $slug,?string $old): void
    {
        $owner=$this->query('SELECT entity_id FROM jingo_slug_aliases WHERE kind=? AND slug=?',[$kind,$slug])->fetchColumn();
        if($owner!==false && $owner!==$id) throw new HttpError(409,'Đường dẫn đang thuộc điểm đến khác.');
        $this->query('DELETE FROM jingo_slug_aliases WHERE kind=? AND slug=? AND entity_id=?',[$kind,$slug,$id]);
        if($old && $old!==$slug) $this->query('INSERT INTO jingo_slug_aliases(kind,slug,entity_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE entity_id=VALUES(entity_id)',[$kind,$old,$id]);
    }
    public function save(array $input,string $actor): array
    {
        $node=self::validate($input); $this->db->beginTransaction();
        try {
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn()) throw new HttpError(503,'Chưa cài khóa danh mục.');
            $nodes=$this->list(true); $map=[]; foreach($nodes as $item) $map[$item['id']]=$item;
            $current=$map[$node['id']] ?? null;
            if(($node['version']===0 && $current) || ($node['version']>0 && (!$current || $current['version']!==$node['version']))) throw new HttpError(409,'Điểm đến đã thay đổi. Tải lại trước khi lưu.');
            $node['cover']=array_key_exists('cover',$node)?$node['cover']:($current['cover'] ?? null);
            $node['coordinates']=array_key_exists('coordinates',$node)?$node['coordinates']:($current['coordinates'] ?? null);
            if($node['cover']!==null) (new Media($this->db,''))->validateIds([$node['cover']['id']]);
            $text=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',str_replace(['đ','Đ'],['d','D'],$node['englishName'] ?: $node['name']));
            $generated=rtrim(substr(trim(preg_replace('/[^a-z0-9]+/','-',strtolower($text ?: '')),'-'),0,150),'-');
            $node['englishSlug']=($node['englishSlug'] ?? '') ?: ($current['englishSlug'] ?? '') ?: ($generated ?: $node['slug']);
            foreach($map as $item) if($item['id']!==$node['id'] && (in_array($node['englishSlug'],[($item['englishSlug'] ?? '') ?: $item['slug'],$item['slug']],true) || $node['slug']===(($item['englishSlug'] ?? '') ?: $item['slug']))) throw new HttpError(409,'Đường dẫn điểm đến đã tồn tại.');
            $map[$node['id']]=$node;
            foreach($map as $item) {
                $seen=[]; $cursor=$item;
                while($cursor) {
                    if(isset($seen[$cursor['id']])) throw new HttpError(400,'Không thể chọn điểm đến con làm cấp cha.');
                    $seen[$cursor['id']]=true;
                    if(count($seen)>10) throw new HttpError(400,'Cây điểm đến tối đa 10 cấp.');
                    if(!$cursor['parentId']) break;
                    $parent=$map[$cursor['parentId']] ?? null;
                    if(!$parent) throw new HttpError(400,'Điểm đến cha không tồn tại.');
                    if($cursor['status']==='ACTIVE' && $parent['status']!=='ACTIVE') throw new HttpError(400,'Điểm đến đang hoạt động cần cấp cha đang hoạt động.');
                    $cursor=$parent;
                }
            }
            if($node['status']==='ARCHIVED') foreach($this->query('SELECT data FROM jingo_products')->fetchAll(PDO::FETCH_ASSOC) as $row) if((json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)['destinationId'] ?? '')===$node['id']) throw new HttpError(409,'Điểm đến đang có sản phẩm. Chuyển sản phẩm trước khi lưu trữ.');
            $this->alias('DESTINATION',$node['id'],$node['slug'],$current['slug'] ?? null);
            $this->alias('DESTINATION_EN',$node['id'],$node['englishSlug'],$current['englishSlug'] ?? $current['slug'] ?? null);
            $node['version']++;
            $json=json_encode($node,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($current) $this->query('UPDATE jingo_destinations SET slug=?,data=?,version=? WHERE id=?',[$node['slug'],$json,$node['version'],$node['id']]);
            else $this->query('INSERT INTO jingo_destinations(id,slug,data,version) VALUES (?,?,?,?)',[$node['id'],$node['slug'],$json,$node['version']]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'destination.save',$node['id']]);
            $this->db->commit(); return $node;
        } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); if($e instanceof PDOException && ($e->errorInfo[1] ?? null)===1062) throw new HttpError(409,'Đường dẫn điểm đến đã tồn tại.'); throw $e; }
    }
}
