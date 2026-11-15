<?php
declare(strict_types=1);
require_once __DIR__.'/FeatureSettings.php';
require_once __DIR__.'/StayPricing.php';
final class PublicStays
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function snapshot(callable $fn):array{$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{$out=$fn();$this->db->commit();return $out;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}
    private function properties():array{$out=[];foreach($this->query('SELECT id,slug,data FROM jingo_stays ORDER BY updated_at DESC,id')->fetchAll(PDO::FETCH_ASSOC) as $r){$p=array_replace(json_decode($r['data'],true,512,JSON_THROW_ON_ERROR),['id'=>$r['id'],'slug'=>$r['slug']]);if($p['status']==='PUBLISHED'&&array_filter($p['rooms'],fn($room)=>$room['active']))$out[]=$p;}usort($out,fn($a,$b)=>$a['sortOrder']<=>$b['sortOrder']);return $out;}
    private function view(array $p,string $lang):array
    {
        $en=$lang==='en';$out=['id'=>$p['id'],'slug'=>$en?(($p['slugEn'] ?? '')?:$p['slug']):$p['slug']];foreach(['name','description','destination','address','amenities','policy'] as $key)$out[$key]=$en&&($p[$key.'En'] ?? '')!==''?$p[$key.'En']:$p[$key];foreach(['mapUrl','coverId','galleryIds','checkInTime','checkOutTime','minNights','maxNights'] as $key)if(array_key_exists($key,$p))$out[$key]=$p[$key];if(!str_starts_with($out['mapUrl'] ?? '','https://'))$out['mapUrl']='';$out['languages']=['vi','en'];$out['localePaths']=['vi'=>'/dat-phong/'.$p['slug'],'en'=>'/en/stays/'.(($p['slugEn'] ?? '')?:$p['slug'])];$out['rooms']=[];$min=PHP_INT_MAX;foreach($p['rooms'] as $r)if($r['active']){$room=['id'=>$r['id']];foreach(['name','description','beds'] as $key)$room[$key]=$en&&($r[$key.'En'] ?? '')!==''?$r[$key.'En']:$r[$key];foreach(['imageId','maxGuests','maxAdults','maxChildren','price','saleFrom','saleTo','discount'] as $key)if(array_key_exists($key,$r))$room[$key]=$r[$key];$out['rooms'][]=$room;$min=min($min,$r['price']);}$out['fromPrice']=$min;return $out;
    }
    public function search(array $input):array
    {
        if(array_diff(array_keys($input),['checkIn','checkOut','rooms','adults','children','locale','query'])||isset($input['query'])&&!is_string($input['query'])||array_key_exists('query',$input)&&$input['query']===null)throw new HttpError(400,'Thông tin tìm phòng không hợp lệ.');
        $query=preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$input['query'] ?? '');if($query===null||str_contains($query,"\0")||strlen(mb_convert_encoding($query,'UTF-16LE','UTF-8'))/2>120)throw new HttpError(400,'Từ khóa tối đa 120 ký tự.');unset($input['query']);$dummy='00000000-0000-4000-8000-000000000001';$in=StayPricing::selection(['hotelId'=>$dummy,'roomId'=>$dummy]+$input);StayPricing::nights($in['checkIn'],$in['checkOut']);
        $fold=static function(string $v):string{$v=mb_strtolower($v,'UTF-8');foreach(['a'=>'àáảãạăằắẳẵặâầấẩẫậ','e'=>'èéẻẽẹêềếểễệ','i'=>'ìíỉĩị','o'=>'òóỏõọôồốổỗộơờớởỡợ','u'=>'ùúủũụưừứửữự','y'=>'ỳýỷỹỵ','d'=>'đ'] as $base=>$letters)$v=str_replace(preg_split('//u',$letters,-1,PREG_SPLIT_NO_EMPTY),$base,$v);return preg_replace('/[\x{0300}-\x{036f}]/u','',$v);};$needle=$fold($query);
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');if(!(new FeatureSettings($this->db))->read(true)['staysEnabled'])throw new HttpError(409,'Lưu trú đang tắt.');
            $this->query("UPDATE jingo_stay_bookings SET status='EXPIRED',version=version+1 WHERE status='PENDING' AND expires_at<=UTC_TIMESTAMP(6) AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.paymentStatus'))='UNPAID'");
            $held=$this->query("SELECT hotel_id,room_id,check_in,check_out,rooms FROM jingo_stay_bookings WHERE check_in<? AND check_out>? AND status NOT IN ('CANCELLED','EXPIRED')",[$in['checkOut'],$in['checkIn']])->fetchAll(PDO::FETCH_ASSOC);$items=[];
            foreach($this->properties() as $p){if(!str_contains($fold(implode(' ',array_map(fn($k)=>$p[$k] ?? '',['name','nameEn','destination','destinationEn','address','addressEn']))),$needle))continue;$best=null;$bestRoom=null;foreach($p['rooms'] as $room){if(!$room['active'])continue;try{$q=StayPricing::calculate($p,array_replace($in,['hotelId'=>$p['id'],'roomId'=>$room['id']]));}catch(HttpError){continue;}$available=true;foreach($q['nights'] as $n){$used=0;foreach($held as $b)if($b['hotel_id']===$p['id']&&$b['room_id']===$room['id']&&$b['check_in']<=$n['date']&&$b['check_out']>$n['date'])$used+=(int)$b['rooms'];if($n['stock']-$used<$in['rooms']){$available=false;break;}}if($available&&($best===null||$q['total']<$best)){$best=$q['total'];$bestRoom=$room['id'];}}if($best!==null)$items[]=$this->view($p,$in['locale'])+['searchTotal'=>$best,'searchRoomId'=>$bestRoom];}
            $this->db->commit();return ['items'=>$items];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function catalog(string $lang='vi'):array{return $this->snapshot(function()use($lang){$enabled=(new FeatureSettings($this->db))->read(true)['staysEnabled'];return ['enabled'=>$enabled,'items'=>$enabled?array_map(fn($p)=>$this->view($p,$lang),$this->properties()):[]];});}
    public function detail(string $slug,string $lang='vi'):array
    {
        if(strlen($slug)>150||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)||in_array($slug,['tra-cuu','booking'],true))throw new HttpError(404,'Không tìm thấy cơ sở lưu trú.');return $this->snapshot(function()use($slug,$lang){if(!(new FeatureSettings($this->db))->read(true)['staysEnabled'])throw new HttpError(404,'Lưu trú đang tắt.');$all=$this->properties();foreach($all as $p)if(($lang==='en'?(($p['slugEn'] ?? '')?:$p['slug']):$p['slug'])===$slug)return $this->view($p,$lang);$id=$this->query('SELECT hotel_id FROM jingo_stay_aliases WHERE lang=? AND slug=?',[$lang,$slug])->fetchColumn();foreach($all as $p)if($p['id']===$id)return $this->view($p,$lang);throw new HttpError(404,'Không tìm thấy cơ sở lưu trú.');});
    }
}
