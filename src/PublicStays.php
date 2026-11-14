<?php
declare(strict_types=1);
require_once __DIR__.'/FeatureSettings.php';
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
    public function catalog(string $lang='vi'):array{return $this->snapshot(function()use($lang){$enabled=(new FeatureSettings($this->db))->read(true)['staysEnabled'];return ['enabled'=>$enabled,'items'=>$enabled?array_map(fn($p)=>$this->view($p,$lang),$this->properties()):[]];});}
    public function detail(string $slug,string $lang='vi'):array
    {
        if(strlen($slug)>150||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)||in_array($slug,['tra-cuu','booking'],true))throw new HttpError(404,'Không tìm thấy cơ sở lưu trú.');return $this->snapshot(function()use($slug,$lang){if(!(new FeatureSettings($this->db))->read(true)['staysEnabled'])throw new HttpError(404,'Lưu trú đang tắt.');$all=$this->properties();foreach($all as $p)if(($lang==='en'?(($p['slugEn'] ?? '')?:$p['slug']):$p['slug'])===$slug)return $this->view($p,$lang);$id=$this->query('SELECT hotel_id FROM jingo_stay_aliases WHERE lang=? AND slug=?',[$lang,$slug])->fetchColumn();foreach($all as $p)if($p['id']===$id)return $this->view($p,$lang);throw new HttpError(404,'Không tìm thấy cơ sở lưu trú.');});
    }
}
