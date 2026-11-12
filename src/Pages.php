<?php
declare(strict_types=1);

final class Pages
{
    public const CONTACT_ID='00000000-0000-4000-8000-000000000001';
    public function __construct(private PDO $db) {}
    private function query(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private static function text(array $data,string $key,int $max,bool $required=false,bool $trim=true): string
    {
        $value=$data[$key] ?? ($required?null:''); if(!is_string($value)) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
        if($trim) $value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);
        if($value===null || ($required && $value==='') || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max) throw new HttpError(400,'Trường '.$key.' không hợp lệ.'); return $value;
    }
    private static function uuid(mixed $id): bool { return is_string($id) && (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$id); }
    private static function slug(mixed $slug): bool { return is_string($slug) && strlen($slug)<=160 && (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug); }
    private static function content(array $input,bool $english=false): array
    {
        if(!array_key_exists('contentHtml',$input)) throw new HttpError(400,'Thiếu nội dung HTML.');
        return ['title'=>self::text($input,'title',180,!$english),'contentHtml'=>ContentHtml::clean(self::text($input,'contentHtml',200000,false,false)),'description'=>self::text($input,'description',1000),'seoTitle'=>self::text($input,'seoTitle',200),'seoDescription'=>self::text($input,'seoDescription',500),'menuLabel'=>self::text($input,'menuLabel',60)];
    }
    public static function validate(array $input): array
    {
        if(array_diff(array_keys($input),['id','slug','kind','title','contentHtml','description','seoTitle','seoDescription','menuLabel','status','version','showInMenu','showInFooter','sortOrder','cover','english','contact'])) throw new HttpError(400,'Có trường không được hỗ trợ.');
        if(!self::uuid($input['id'] ?? null) || !self::slug($input['slug'] ?? null) || !in_array($input['kind'] ?? null,['PAGE','CONTACT'],true) || !in_array($input['status'] ?? null,['DRAFT','PUBLISHED','TRASH'],true)) throw new HttpError(400,'Mã, đường dẫn hoặc trạng thái trang không hợp lệ.');
        if(!is_int($input['version'] ?? null) || $input['version']<0 || $input['version']>2147483646 || !is_int($input['sortOrder'] ?? null) || $input['sortOrder']<0 || $input['sortOrder']>10000 || !is_bool($input['showInMenu'] ?? null) || !is_bool($input['showInFooter'] ?? null)) throw new HttpError(400,'Phiên bản hoặc cấu hình menu không hợp lệ.');
        if(($input['id']===self::CONTACT_ID)!==($input['kind']==='CONTACT')) throw new HttpError(400,'Trang Liên hệ dùng mã có sẵn.');
        if($input['kind']==='CONTACT' && $input['status']==='TRASH') throw new HttpError(400,'Trang Liên hệ không đưa vào thùng rác.');
        $page=array_merge(self::content($input),array_intersect_key($input,array_flip(['id','slug','kind','status','version','sortOrder','showInMenu','showInFooter'])));
        $page['cover']=$input['cover'] ?? null;
        if($page['cover']!==null) {
            $c=$page['cover']; if(!is_array($c) || count($c)!==2 || !self::uuid($c['id'] ?? null) || !array_key_exists('alt',$c)) throw new HttpError(400,'Ảnh bìa không hợp lệ.');
            $page['cover']=['id'=>$c['id'],'alt'=>self::text($c,'alt',300)];
        }
        $page['english']=null;
        if(($input['english'] ?? null)!==null) {
            $en=$input['english'];
            if(!is_array($en) || array_diff(array_keys($en),['title','contentHtml','description','seoTitle','seoDescription','menuLabel','slug','published']) || !self::slug($en['slug'] ?? null) || !is_bool($en['published'] ?? null) || !array_key_exists('title',$en)) throw new HttpError(400,'Bản English không hợp lệ.');
            $page['english']=array_merge(self::content($en,true),['slug'=>$en['slug'],'published'=>$en['published']]);
        }
        $page['contact']=null; $contact=$input['contact'] ?? null;
        if($contact!==null) {
            if(!is_array($contact) || array_diff(array_keys($contact),['phone','email','address','hours','mapUrl']) || count($contact)!==5) throw new HttpError(400,'Thông tin liên hệ không hợp lệ.');
            $contact=['phone'=>self::text($contact,'phone',40),'email'=>self::text($contact,'email',254,false,false),'address'=>self::text($contact,'address',500),'hours'=>self::text($contact,'hours',300),'mapUrl'=>self::text($contact,'mapUrl',4096,false,false)];
            if(!preg_match('/^[+0-9 ()-]*$/D',$contact['phone']) || ($contact['email']!=='' && !filter_var($contact['email'],FILTER_VALIDATE_EMAIL)) || ($contact['mapUrl']!=='' && (!str_starts_with($contact['mapUrl'],'https://') || !filter_var($contact['mapUrl'],FILTER_VALIDATE_URL)))) throw new HttpError(400,'Điện thoại, email hoặc bản đồ không hợp lệ.');
        }
        if($page['kind']==='CONTACT') $page['contact']=$contact ?? ['phone'=>'','email'=>'','address'=>'','hours'=>'','mapUrl'=>''];
        if($page['status']==='PUBLISHED' && trim(strip_tags($page['contentHtml']))==='') throw new HttpError(400,'Thêm nội dung trước khi xuất bản.');
        if($page['english']['published'] ?? false) if($page['english']['title']==='' || trim(strip_tags($page['english']['contentHtml']))==='') throw new HttpError(400,'Thêm tiêu đề và nội dung English trước khi xuất bản.');
        return $page;
    }
    public function list(): array
    {
        $items=[]; foreach($this->query('SELECT data,version,updated_at FROM jingo_pages')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $p=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR); $p['version']=(int)$row['version']; $p['updatedAt']=gmdate('Y-m-d\TH:i:s.000\Z',strtotime($row['updated_at'].' UTC')); $items[]=$p;
        }
        usort($items,fn($a,$b)=>($a['sortOrder']<=>$b['sortOrder']) ?: strnatcasecmp($a['title'],$b['title'])); return $items;
    }
    public static function view(array $p,string $lang='vi'): array
    {
        if($p['status']!=='PUBLISHED' || ($lang==='en' && !($p['english']['published'] ?? false))) throw new HttpError(404,'Trang chưa xuất bản.');
        $source=$lang==='en'?$p['english']:$p; $contact=$p['kind']==='CONTACT'; $paths=['vi'=>$contact?'/lien-he':'/trang/'.$p['slug']];
        if($p['english']['published'] ?? false) $paths['en']=$contact?'/en/contact':'/en/pages/'.$p['english']['slug'];
        return ['id'=>$p['id'],'kind'=>$p['kind'],'slug'=>$lang==='en'?$p['english']['slug']:$p['slug'],'title'=>$source['title'],'description'=>$source['description'],'contentHtml'=>$source['contentHtml'],'seoTitle'=>$source['seoTitle'],'seoDescription'=>$source['seoDescription'],'menuLabel'=>$source['menuLabel'] ?: $source['title'],'cover'=>$p['cover'],'contact'=>$contact?$p['contact']:null,'updatedAt'=>$p['updatedAt'],'localePaths'=>$paths,'languages'=>isset($paths['en'])?['vi','en']:['vi']];
    }
    public function published(string $lang='vi'): array { $items=[]; foreach($this->list() as $p) if($p['status']==='PUBLISHED' && ($lang!=='en' || ($p['english']['published'] ?? false))) $items[]=self::view($p,$lang); return $items; }
    public function navigation(string $lang='vi'): array
    {
        $items=[]; foreach($this->list() as $p) if($p['status']==='PUBLISHED' && ($lang!=='en' || ($p['english']['published'] ?? false)) && ($p['showInMenu'] || $p['showInFooter'])) { $v=self::view($p,$lang); $items[]=['id'=>$p['id'],'label'=>$v['menuLabel'],'href'=>$v['localePaths'][$lang],'menu'=>$p['showInMenu'],'footer'=>$p['showInFooter']]; } return $items;
    }
    public function find(string $slug,string $lang='vi',bool $contact=false): array
    {
        $pages=$this->list(); foreach($pages as $p) if($contact?$p['id']===self::CONTACT_ID:($p['kind']==='PAGE' && ($lang==='en'?($p['english']['slug'] ?? null):$p['slug'])===$slug)) return self::view($p,$lang);
        if(!$contact) { $id=$this->query('SELECT entity_id FROM jingo_slug_aliases WHERE kind=? AND slug=?',[$lang==='en'?'PAGE_EN':'PAGE',$slug])->fetchColumn(); foreach($pages as $p) if($p['id']===$id && $p['kind']==='PAGE') return self::view($p,$lang); }
        throw new HttpError(404,'Không tìm thấy trang.');
    }
    private function alias(string $kind,string $id,string $slug,?string $old): void
    {
        $owner=$this->query('SELECT entity_id FROM jingo_slug_aliases WHERE kind=? AND slug=?',[$kind,$slug])->fetchColumn(); if($owner!==false && $owner!==$id) throw new HttpError(409,'Đường dẫn trang đã được dùng.');
        $this->query('DELETE FROM jingo_slug_aliases WHERE kind=? AND slug=? AND entity_id=?',[$kind,$slug,$id]); if($old && $old!==$slug) $this->query('INSERT INTO jingo_slug_aliases(kind,slug,entity_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE entity_id=VALUES(entity_id)',[$kind,$old,$id]);
    }
    public function save(array $input,string $actor): array
    {
        $p=self::validate($input); $ids=ContentHtml::imageIds($p['contentHtml']); if($p['english']) $ids=array_merge($ids,ContentHtml::imageIds($p['english']['contentHtml'])); if($p['cover']) $ids[]=$p['cover']['id']; (new Media($this->db,''))->validateIds($ids);
        $this->db->beginTransaction();
        try {
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn()) throw new HttpError(503,'Chưa cài khóa danh mục.');
            $current=null; foreach($this->list() as $other) {
                if($other['id']===$p['id']) { $current=$other; continue; }
                if($other['slug']===$p['slug'] || ($p['english'] && ($other['english']['slug'] ?? null)===$p['english']['slug'])) throw new HttpError(409,'Đường dẫn trang đã được dùng.');
            }
            if(($current && $current['version']!==$p['version']) || (!$current && $p['version']!==0)) throw new HttpError(409,'Trang đã thay đổi. Tải lại trước khi lưu.');
            $this->alias('PAGE',$p['id'],$p['slug'],$current['slug'] ?? null); if($p['english']) $this->alias('PAGE_EN',$p['id'],$p['english']['slug'],$current['english']['slug'] ?? null);
            $p['version']++; $json=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($current) $this->query('UPDATE jingo_pages SET slug=?,data=?,version=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$p['slug'],$json,$p['version'],$p['id']]); else $this->query('INSERT INTO jingo_pages(id,slug,data,version) VALUES (?,?,?,?)',[$p['id'],$p['slug'],$json,$p['version']]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'page.save',$p['id']]); $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); if($e instanceof PDOException && ($e->errorInfo[1] ?? null)===1062) throw new HttpError(409,'Đường dẫn trang đã được dùng.'); throw $e; }
        foreach($this->list() as $saved) if($saved['id']===$p['id']) return $saved;
        throw new RuntimeException('Page not found after save.');
    }
}
