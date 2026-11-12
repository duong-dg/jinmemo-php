<?php
declare(strict_types=1);

final class SiteSettings
{
    public function __construct(private PDO $db,private array $config=[]) {}
    private function query(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    private static function text(array $input,string $key,int $max,bool $required=false): string
    {
        $value=$input[$key] ?? null;
        if(!is_string($value)) throw new HttpError(400,'Trường '.$key.' không hợp lệ.');
        $value=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value);
        if($value===null || ($required && $value==='') || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$max) throw new HttpError(400,'Trường '.$key.' không hợp lệ.'); return $value;
    }
    private static function image(mixed $image): ?array
    {
        if($image===null) return null;
        if(!is_array($image) || count($image)!==2 || !is_string($image['id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$image['id'])) throw new HttpError(400,'Ảnh không hợp lệ.');
        return ['id'=>$image['id'],'alt'=>self::text($image,'alt',300)];
    }
    public function defaults(string $id): array
    {
        $name=$this->config['APP_NAME'] ?? 'JinMemo';
        if($id==='brand') return ['name'=>$name,'companyName'=>'','email'=>'','phone'=>'','address'=>'','logo'=>null];
        if($id==='site-seo') return ['vi'=>['title'=>$name.' — Vé tham quan, show diễn & tour địa phương','description'=>'Đặt vé tham quan, show diễn và tour địa phương tại Đà Nẵng, Hội An cùng '.$name.'. Chọn ngày, gói vé và nhận vé qua email.'],'en'=>['title'=>$name.' — Attraction tickets, shows & local tours','description'=>'Book attraction tickets, shows and local tours in Da Nang and Hoi An with '.$name.'. Choose your dates and ticket options, and receive your tickets by email.'],'image'=>null];
        throw new HttpError(404,'Không tìm thấy cấu hình.');
    }
    public function get(string $id,bool $public=false): array
    {
        $defaults=$this->defaults($id);$row=$this->query('SELECT data,version FROM jingo_settings WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        $result=$row?array_merge($defaults,json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)):$defaults;
        if(!$public)$result['version']=$row?(int)$row['version']:0;return $result;
    }
    public function save(string $id,array $input,string $actor): array
    {
        $defaults=$this->defaults($id);
        if(array_diff(array_keys($input),array_merge(array_keys($defaults),['version'])) || count($input)!==count($defaults)+1 || !is_int($input['version'] ?? null) || $input['version']<0 || $input['version']>2147483646) throw new HttpError(400,'Cấu hình hoặc phiên bản không hợp lệ.');
        if($id==='site-seo') {
            $data=[];foreach(['vi','en'] as $lang) {
                $text=$input[$lang];if(!is_array($text) || count($text)!==2 || array_diff(array_keys($text),['title','description'])) throw new HttpError(400,'Nội dung SEO không hợp lệ.');
                $data[$lang]=['title'=>self::text($text,'title',200,true),'description'=>self::text($text,'description',500,true)];
            }
            $data['image']=self::image($input['image']);$image=$data['image'];
        } else {
            $data=['name'=>self::text($input,'name',100,true),'companyName'=>self::text($input,'companyName',200),'email'=>self::text($input,'email',200),'phone'=>self::text($input,'phone',40),'address'=>self::text($input,'address',500),'logo'=>self::image($input['logo'])];
            if(($data['email']!=='' && !filter_var($data['email'],FILTER_VALIDATE_EMAIL)) || !preg_match('/^[+0-9 ()-]*$/D',$data['phone'])) throw new HttpError(400,'Email hoặc điện thoại không hợp lệ.');$image=$data['logo'];
        }
        (new Media($this->db,''))->validateIds($image?[$image['id']]:[]);
        $this->db->beginTransaction();
        try {
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn()) throw new HttpError(503,'Chưa cài khóa cấu hình.');
            $row=$this->query('SELECT version FROM jingo_settings WHERE id=? FOR UPDATE',[$id])->fetchColumn();
            if(($row===false?0:(int)$row)!==$input['version']) throw new HttpError(409,'Cấu hình đã thay đổi. Tải lại trước khi lưu.');
            $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$version=$input['version']+1;
            if($row===false)$this->query('INSERT INTO jingo_settings(id,data,version) VALUES (?,?,?)',[$id,$json,$version]);else $this->query('UPDATE jingo_settings SET data=?,version=? WHERE id=?',[$json,$version,$id]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,$id.'.save',$id]);$this->db->commit();return array_merge($data,['version'=>$version]);
        } catch(Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
