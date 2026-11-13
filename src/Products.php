<?php
declare(strict_types=1);
require_once __DIR__.'/ServiceDetails.php';
require_once __DIR__.'/ProductLocation.php';

final class Products
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    public static function sourceHash(array $p):string
    {
        $variants=[];foreach($p['variants'] as $v){$conditions=[];foreach(Pricing::GROUPS as $g)if(array_key_exists($g,$v['prices']))$conditions[$g]=$v['conditions'][$g] ?? '';$variants[]=['id'=>$v['id'],'name'=>$v['name'],'conditions'=>(object)$conditions];}
        $source=['name'=>$p['name'],'destination'=>$p['destination'],'description'=>$p['description'],'detailHtml'=>$p['detailHtml'] ?? '','seoTitle'=>$p['seoTitle'] ?? '','seoDescription'=>$p['seoDescription'] ?? '','variants'=>$variants];return hash('sha256',json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
    }
    public static function translated(array $p):bool{return ($p['english']['status'] ?? '')==='PUBLISHED'&&($p['english']['sourceHash'] ?? '')===self::sourceHash($p);}
    public function list(bool $admin=false,string $lang='vi'):array
    {
        $items=[];foreach($this->query('SELECT data,version FROM jingo_products ORDER BY updated_at DESC,id')->fetchAll(PDO::FETCH_ASSOC) as $row){$p=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$p['version']=(int)$row['version'];if($admin)$items[]=$p;elseif(($p['status'] ?? '')==='PUBLISHED'&&($lang!=='en'||self::translated($p))){$v=$this->publicView($p,$lang);unset($v['detailHtml'],$v['gallery'],$v['saleClosures']);$items[]=$v;}}if(!$admin)usort($items,fn($a,$b)=>($a['displayOrder'] ?? 10001)<=>($b['displayOrder'] ?? 10001));return $items;
    }
    public function find(string $value,bool $slug=false,bool $admin=false,string $lang='vi'):array
    {
        $row=$this->query('SELECT data,version FROM jingo_products WHERE '.($slug?'slug':'id').'=?',[$value])->fetch(PDO::FETCH_ASSOC);
        if(!$row&&$slug)$row=$this->query("SELECT p.data,p.version FROM jingo_slug_aliases a JOIN jingo_products p ON p.id=a.entity_id WHERE a.kind='PRODUCT' AND a.slug=?",[$value])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new HttpError(404,'Không tìm thấy sản phẩm.');$p=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);$p['version']=(int)$row['version'];
        if(!$admin&&(($p['status'] ?? '')!=='PUBLISHED'||($lang==='en'&&!self::translated($p))))throw new HttpError(404,'Không tìm thấy sản phẩm.');return $p;
    }
    public function publicView(array $p,string $lang='vi'):array
    {
        $out=$p;$translated=self::translated($p);
        if($lang==='en'){
            if(!$translated)throw new HttpError(404,'Translation unavailable.');$en=$p['english'];foreach(['name','destination','description','detailHtml','seoTitle','seoDescription'] as $key)$out[$key]=$en[$key] ?? '';
            if($out['cover'])$out['cover']['alt']=$en['name'];foreach($out['gallery'] as &$image)$image['alt']=$en['name'];unset($image);
            foreach($out['variants'] as &$v){$v['conditions']=[];foreach($en['variants'] as $t)if($t['id']===$v['id']){$v['name']=$t['name'];$v['conditions']=$t['conditions'] ?? [];break;}}unset($v);
        }
        unset($out['supplierId'],$out['rules'],$out['closures'],$out['english'],$out['version']);$out['variants']=Pricing::publicVariants($out);$out['languages']=$translated?['vi','en']:['vi'];
        $out['saleClosures']=[];foreach($p['closures'] ?? [] as $c){unset($c['productId'],$c['reason']);$out['saleClosures'][]=$c;}
        $row=$this->query('SELECT data FROM jingo_product_types WHERE id=?',[$p['type']])->fetchColumn();$type=$row?json_decode($row,true,512,JSON_THROW_ON_ERROR):null;
        $out['typeName']=$lang==='en'?($type&&ProductTypes::translated($type)?$type['english']['name']:'Experience'):($type['name'] ?? 'Trải nghiệm');$out['bookingKind']=$type['bookingKind'] ?? 'TICKET';if($type&&($lang!=='en'||ProductTypes::translated($type)))$out['typeSlug']=$type['slug'];
        if(isset($p['destinationId'])){$raw=$this->query('SELECT data FROM jingo_destinations WHERE id=?',[$p['destinationId']])->fetchColumn();if($raw){$d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$out['destination']=$lang==='en'?($d['englishName']?:$d['name']):$d['name'];$out['destinationSlug']=$lang==='en'?($d['englishSlug']?:$d['slug']):$d['slug'];}}
        return $out;
    }
    public function archive(string $id,array $input,string $actor):array
    {
        if(array_diff(array_keys($input),['version','archived'])||count($input)!==2||!is_int($input['version'] ?? null)||$input['version']<1||!is_bool($input['archived'] ?? null))throw new HttpError(400,'Thao tác lưu trữ không hợp lệ.');
        $p=$this->find($id,false,true);if($p['version']!==$input['version'])throw new HttpError(409,'Sản phẩm đã thay đổi. Tải lại.');if($input['archived']? $p['status']==='ARCHIVED':$p['status']!=='ARCHIVED')throw new HttpError(409,'Trạng thái sản phẩm đã thay đổi.');
        $p['status']=$input['archived']?'ARCHIVED':'DRAFT';return $this->save($p,$actor,$input['archived']?'product.archive':'product.restore');
    }
    public function save(array $input,string $actor,?string $action=null):array
    {
        $p=ProductSchema::validate($input);$refs=array_merge($p['gallery'],$p['cover']?[$p['cover']]:[]);$ids=array_column($refs,'id');$ids=array_merge($ids,ContentHtml::imageIds($p['detailHtml']),ContentHtml::imageIds($p['english']['detailHtml'] ?? ''));(new Media($this->db,''))->validateIds($ids);$this->db->beginTransaction();
        try{
            if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new HttpError(503,'Chưa cài khóa danh mục.');
            $current=$this->query('SELECT slug,version FROM jingo_products WHERE id=? FOR UPDATE',[$p['id']])->fetch(PDO::FETCH_ASSOC);if(($current?(int)$current['version']:0)!==$p['version'])throw new HttpError(409,'Sản phẩm đã thay đổi. Tải lại trước khi lưu.');
            $raw=$this->query('SELECT data FROM jingo_product_types WHERE id=?',[$p['type']])->fetchColumn();$type=$raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):null;if(!$type||$type['status']!=='ACTIVE')throw new HttpError(400,'Chọn loại sản phẩm đang hoạt động.');
            if(isset($p['supplierId'])){$raw=$this->query('SELECT data FROM jingo_suppliers WHERE id=?',[$p['supplierId']])->fetchColumn();if(!$raw||json_decode($raw,true,512,JSON_THROW_ON_ERROR)['status']!=='ACTIVE')throw new HttpError(400,'Chọn nhà cung cấp đang hoạt động.');}
            if(isset($p['destinationId'])){$nodes=[];foreach((new Destinations($this->db))->list(true) as $d)$nodes[$d['id']]=$d;$cursor=$nodes[$p['destinationId']] ?? null;if(!$cursor)throw new HttpError(400,'Điểm đến không tồn tại.');$p['destination']=$cursor['name'];$seen=[];while($cursor){if($cursor['status']!=='ACTIVE'||isset($seen[$cursor['id']]))throw new HttpError(400,'Chọn điểm đến đang hoạt động.');$seen[$cursor['id']]=true;if(!$cursor['parentId'])break;$cursor=$nodes[$cursor['parentId']] ?? null;if(!$cursor)throw new HttpError(400,'Điểm đến thiếu cấp cha.');}}
            if(($p['english']['status'] ?? '')==='PUBLISHED'&&!self::translated($p))$p['english']['status']='NEEDS_REVIEW';
            $owner=$this->query("SELECT entity_id FROM jingo_slug_aliases WHERE kind='PRODUCT' AND slug=?",[$p['slug']])->fetchColumn();if($owner!==false&&$owner!==$p['id'])throw new HttpError(409,'Đường dẫn đang thuộc nội dung khác.');
            $this->query("DELETE FROM jingo_slug_aliases WHERE kind='PRODUCT' AND slug=? AND entity_id=?",[$p['slug'],$p['id']]);if($current&&$current['slug']!==$p['slug'])$this->query("INSERT INTO jingo_slug_aliases(kind,slug,entity_id) VALUES ('PRODUCT',?,?) ON DUPLICATE KEY UPDATE entity_id=VALUES(entity_id)",[$current['slug'],$p['id']]);
            $p['version']++;$json=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if($current)$this->query('UPDATE jingo_products SET slug=?,data=?,version=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$p['slug'],$json,$p['version'],$p['id']]);else $this->query('INSERT INTO jingo_products(id,slug,data,version) VALUES (?,?,?,?)',[$p['id'],$p['slug'],$json,$p['version']]);
            $this->query('INSERT INTO jingo_audit(id,admin_id,action,entity_id) VALUES (?,?,?,?)',[bin2hex(random_bytes(16)),$actor,$action ?? ($current?'product.update':'product.create'),$p['id']]);$this->db->commit();return $p;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1] ?? null)===1062)throw new HttpError(409,'Mã hoặc đường dẫn sản phẩm đã tồn tại.');throw $e;}
    }
}
