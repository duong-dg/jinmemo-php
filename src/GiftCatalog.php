<?php
declare(strict_types=1);
require_once __DIR__.'/Media.php';
final class GiftCatalog
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public function config():array
    {
        $r=$this->query("SELECT data,version FROM jingo_settings WHERE id='gifts'")->fetch(PDO::FETCH_ASSOC);return $r?array_replace(json_decode($r['data'],true,512,JSON_THROW_ON_ERROR),['version'=>(int)$r['version']]):['version'=>0,'enabled'=>false,'shippingFee'=>50000,'items'=>[]];
    }
    private static function integer(mixed $v,int $min,int $max):bool{return is_int($v)&&$v>=$min&&$v<=$max;}
    private static function text(mixed $v,int $max,bool $trim=false,bool $required=false):string
    {
        if(!is_string($v)||str_contains($v,"\0"))throw new HttpError(400,'Nội dung quà lưu niệm không hợp lệ.');if($trim)$v=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$v);if($v===null||$required&&$v===''||strlen(mb_convert_encoding($v,'UTF-16LE','UTF-8'))/2>$max)throw new HttpError(400,'Kiểm tra độ dài tên và mô tả quà.');return $v;
    }
    private static function uuid(mixed $v):bool{return is_string($v)&&(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$v);}
    private function validate(array $in):array
    {
        if(count($in)!==4||array_diff(array_keys($in),['version','enabled','shippingFee','items'])||!self::integer($in['version'] ?? null,0,2147483646)||!is_bool($in['enabled'] ?? null)||!self::integer($in['shippingFee'] ?? null,0,10000000)||!is_array($in['items'] ?? null)||!array_is_list($in['items'])||count($in['items'])>200)throw new HttpError(400,'Cấu hình quà lưu niệm không hợp lệ.');$items=[];$ids=[];
        foreach($in['items'] as $item){if(!is_array($item)||array_diff(array_keys($item),['id','name','description','nameEn','descriptionEn','imageId','price','stock','active'])||!self::uuid($item['id'] ?? null)||!self::integer($item['price'] ?? null,1,100000000)||!self::integer($item['stock'] ?? null,0,9007199254740991)||!is_bool($item['active'] ?? null))throw new HttpError(400,'Kiểm tra sản phẩm, giá và tồn kho quà.');$id=strtolower($item['id']);if(isset($ids[$id]))throw new HttpError(400,'Mã sản phẩm bị trùng.');$ids[$id]=true;$out=['id'=>$id,'name'=>self::text($item['name'] ?? null,150,true,true),'description'=>self::text($item['description'] ?? null,2000),'price'=>$item['price'],'stock'=>$item['stock'],'active'=>$item['active']];foreach(['nameEn'=>150,'descriptionEn'=>2000] as $key=>$max)if(array_key_exists($key,$item))$out[$key]=self::text($item[$key],$max,$key==='nameEn');if(array_key_exists('imageId',$item)){if(!self::uuid($item['imageId']))throw new HttpError(400,'Ảnh quà không hợp lệ.');$out['imageId']=strtolower($item['imageId']);}$items[]=$out;}
        return ['enabled'=>$in['enabled'],'shippingFee'=>$in['shippingFee'],'items'=>$items];
    }
    public function save(array $in,string $actor):array
    {
        $data=$this->validate($in);$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            // The catalog lock serializes first save, future checkout and stock edits.
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');$previous=$this->config();if($previous['version']!==$in['version'])throw new HttpError(409,'Kho quà vừa thay đổi. Tải lại trước khi lưu.');(new Media($this->db,''))->validateIds(array_column($data['items'],'imageId'));$remaining=array_column($data['items'],'id');$removed=array_diff(array_column($previous['items'],'id'),$remaining);
            if($removed){$open=$this->query("SELECT data FROM jingo_gift_orders WHERE status IN ('PENDING','SHIPPING')");while($r=$open->fetch(PDO::FETCH_ASSOC)){foreach(json_decode($r['data'],true,512,JSON_THROW_ON_ERROR)['lines'] ?? [] as $line)if(in_array($line['id'],$removed,true))throw new HttpError(409,'Sản phẩm còn trong đơn đang xử lý. Hãy ẩn sản phẩm hoặc xử lý xong đơn trước khi xóa.');}}
            $version=$in['version']+1;$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if($previous['version']===0)$this->query("INSERT INTO jingo_settings(id,data,version) VALUES ('gifts',?,?)",[$json,$version]);else $this->query("UPDATE jingo_settings SET data=?,version=? WHERE id='gifts'",[$json,$version]);$this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,'gifts.save','gifts']);$this->db->commit();return $data+['version'=>$version];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
