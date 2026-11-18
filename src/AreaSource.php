<?php
declare(strict_types=1);

/** Fixed upstream paths only; saved transport configuration is never modified here. */
final class AreaSource
{
    public function __construct(private PDO $db,private ?Closure $fetch=null,private ?Closure $clock=null) {}
    private function query(string $sql,array $args=[]): PDOStatement { $q=$this->db->prepare($sql);$q->execute($args);return $q; }
    private function now(): int { return $this->clock ? ($this->clock)() : time(); }
    public static function parameters(array $in,bool $gift=false): array
    {
        if(array_diff(array_keys($in),$gift?['province']:['version','province','district']))throw new HttpError(400,'Tham số khu vực không hợp lệ.');
        $version=$gift?'v2':($in['version']??'v2');$province=$in['province']??'';$district=$gift?'':($in['district']??'');
        if(!is_string($version)||!in_array($version,['v1','v2'],true)||!is_string($province)||!is_string($district)||$province!==''&&!preg_match('/^\d{1,3}$/D',$province)||$district!==''&&!preg_match('/^\d{1,4}$/D',$district)||$district!==''&&($province===''||$version==='v2'))throw new HttpError(400,'Tham số khu vực không hợp lệ.');
        return [$version,$province,$district];
    }
    private static function unit(mixed $value): array
    {
        if(!is_array($value)||!is_int($value['code']??null)||$value['code']<1||$value['code']>9007199254740991||!is_string($value['name']??null)||!preg_match('//u',$value['name'])||mb_strlen($value['name'],'UTF-8')<1||strlen(mb_convert_encoding($value['name'],'UTF-16LE','UTF-8'))/2>150||str_contains($value['name'],"\0"))throw new RuntimeException('Invalid upstream unit.');
        return ['code'=>$value['code'],'name'=>$value['name']];
    }
    private static function units(mixed $values): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>500)throw new RuntimeException('Invalid upstream list.');
        $out=[];$seen=[];foreach($values as $value){$unit=self::unit($value);if(isset($seen[$unit['code']]))throw new RuntimeException('Duplicate upstream unit.');$seen[$unit['code']]=true;$out[]=$unit;}return $out;
    }
    public static function importedAreas(string $version,mixed $province,mixed $district=null): array
    {
        if(!in_array($version,['v1','v2'],true))throw new InvalidArgumentException('Invalid area version.');
        $p=self::unit($province);$root='vn-'.$version.'-p-'.$p['code'];$old=$version==='v1'?' (cũ)':'';
        $areas=[['id'=>$root,'name'=>$p['name'].$old,'surcharges'=>(object)[]]];$parent=$root;$source=$province;
        if($version==='v1'){$d=self::unit($district);$parent='vn-v1-d-'.$d['code'];$source=$district;$areas[]=['id'=>$parent,'name'=>$d['name'].$old,'parentId'=>$root,'surcharges'=>(object)[]];}
        foreach(self::units($source['wards']??null) as $w)$areas[]=['id'=>'vn-'.$version.'-w-'.$w['code'],'name'=>$w['name'].$old,'parentId'=>$parent,'surcharges'=>(object)[]];
        // The saved plan has the same 150 UTF-16-unit name limit, including the legacy suffix.
        foreach($areas as $a)if(strlen(mb_convert_encoding($a['name'],'UTF-16LE','UTF-8'))/2>150)throw new RuntimeException('Imported area name too long.');
        return $areas;
    }
    private function request(string $path): mixed
    {
        if($this->fetch)return ($this->fetch)($path);
        if(!function_exists('curl_init'))throw new RuntimeException('Missing curl.');
        $c=curl_init('https://provinces.open-api.vn/api/'.$path);$body='';
        curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>1000000)return 0;$body.=$chunk;return strlen($chunk);}]);
        try{if(curl_exec($c)===false||(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE)!==200)throw new RuntimeException('Upstream unavailable.');return json_decode($body,true,64,JSON_THROW_ON_ERROR);}finally{curl_close($c);}
    }
    private function get(string $path): mixed
    {
        $id=hash('sha256',$path);$now=$this->now();
        $this->db->beginTransaction();
        try {
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='area-source' FOR UPDATE")->fetchColumn())throw new RuntimeException('Area cache not installed.');
            $this->query('INSERT IGNORE INTO jingo_area_source_cache(id) VALUES (?)',[$id]);
            $excess=(int)$this->db->query('SELECT COUNT(*) FROM jingo_area_source_cache')->fetchColumn()-128;
            if($excess>0)$this->query('DELETE FROM jingo_area_source_cache WHERE id<>? ORDER BY fetched_at,id LIMIT '.min(1000,$excess),[$id]);
            $row=$this->query('SELECT data,fetched_at,next_attempt FROM jingo_area_source_cache WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            $cached=$row['data']!==null&&(int)$row['fetched_at']>$now-86400;
            $claimed=!$cached&&(int)$row['next_attempt']<=$now;
            if($claimed)$this->query('UPDATE jingo_area_source_cache SET next_attempt=? WHERE id=?',[$now+30,$id]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        if($cached)return json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);
        if(!$claimed)throw new RuntimeException('Upstream retry delayed.');
        $data=$this->request($path);
        // Validate before caching; never replace good data with malformed upstream JSON.
        if(str_ends_with($path,'/'))self::units($data);
        else {self::unit($data);self::units($data[str_starts_with($path,'v1/p/')?'districts':'wards']??null);}
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>1000000)throw new RuntimeException('Upstream too large.');
        $this->query('UPDATE jingo_area_source_cache SET data=?,fetched_at=?,next_attempt=0 WHERE id=? AND next_attempt=?',[$json,$now,$id,$now+30]);
        return $data;
    }
    public function load(array $in,bool $gift=false): array
    {
        [$version,$province,$district]=self::parameters($in,$gift);
        try {
            if($province==='')return ['data'=>self::units($this->get($version.'/'))];
            $p=$this->get($version.'/p/'.$province.'?depth=2');
            if((string)self::unit($p)['code']!==(string)(int)$province)throw new RuntimeException('Upstream province differs.');
            if($version==='v2')return ['data'=>$gift?self::units($p['wards']??null):self::importedAreas('v2',$p)];
            $districts=self::units($p['districts']??null);
            if($district==='')return ['data'=>$districts];
            if(!in_array((int)$district,array_column($districts,'code'),true))throw new HttpError(400,'Quận/huyện không thuộc tỉnh đã chọn.');
            $d=$this->get('v1/d/'.$district.'?depth=2');
            if(self::unit($d)['code']!==(int)$district)throw new RuntimeException('Upstream district differs.');
            return ['data'=>self::importedAreas('v1',$p,$d)];
        }catch(HttpError $e){throw $e;}catch(Throwable){throw new HttpError(503,'Chưa tải được danh mục tỉnh/thành. Thử lại sau; khu vực đã lưu và địa chỉ nhập tay vẫn dùng bình thường.');}
    }
}
