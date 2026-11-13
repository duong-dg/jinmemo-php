<?php
declare(strict_types=1);

final class Translations
{
    public function __construct(private PDO $db){}
    public static function source(string $kind,array $item):array
    {
        if($kind==='TYPE')return ['name'=>$item['name'],'description'=>$item['description'] ?? ''];
        if($kind==='POST')return ['title'=>$item['title'],'excerpt'=>$item['excerpt'],'contentHtml'=>$item['contentHtml'],'category'=>$item['category'],'seoTitle'=>$item['seoTitle'] ?? '','seoDescription'=>$item['seoDescription'] ?? ''];
        $variants=[];foreach($item['variants'] as $v){$conditions=[];foreach(Pricing::GROUPS as $g)if(array_key_exists($g,$v['prices']))$conditions[$g]=$v['conditions'][$g] ?? '';$variants[]=['id'=>$v['id'],'name'=>$v['name'],'conditions'=>(object)$conditions];}
        return ['name'=>$item['name'],'destination'=>$item['destination'],'description'=>$item['description'],'detailHtml'=>$item['detailHtml'] ?? '','seoTitle'=>$item['seoTitle'] ?? '','seoDescription'=>$item['seoDescription'] ?? '','variants'=>$variants];
    }
    private function service(string $kind):Products|Posts|ProductTypes{return match($kind){'PRODUCT'=>new Products($this->db),'POST'=>new Posts($this->db),'TYPE'=>new ProductTypes($this->db),default=>throw new HttpError(400,'Loại bản dịch không hợp lệ.')};}
    private function shared(string $kind,array $item):array
    {
        if($kind==='POST'){foreach((new PostCategories($this->db))->list() as $category)if($category['name']===$item['category'])return ['sharedCategory'=>['name'=>$category['name'],'englishName'=>$category['englishName'] ?: $category['name']]];}
        if($kind==='PRODUCT'&&isset($item['destinationId']))foreach((new Destinations($this->db))->list(true) as $node)if($node['id']===$item['destinationId'])return ['sharedDestination'=>['id'=>$node['id'],'name'=>$node['name'],'englishName'=>$node['englishName'] ?: $node['name']]];
        return [];
    }
    public function list():array
    {
        $rows=[];foreach(['PRODUCT','POST','TYPE'] as $kind)foreach($this->service($kind)->list(true) as $item){$source=self::source($kind,$item);$shared=$this->shared($kind,$item);if($kind==='POST')unset($source['category']);if(isset($shared['sharedDestination']))unset($source['destination']);$status=$item['english']['status'] ?? 'MISSING';if($kind==='POST'&&$status==='NEEDS_REVIEW')$status='PUBLISHED';if($kind!=='POST'&&$status==='PUBLISHED'&&!($kind==='PRODUCT'?Products::translated($item):ProductTypes::translated($item)))$status='NEEDS_REVIEW';$rows[]=['id'=>$item['id'],'kind'=>$kind,'version'=>$item['version'],'name'=>$item['title'] ?? $item['name'],'slug'=>$item['slug'],'source'=>$source,'english'=>$item['english'] ?? null,'status'=>$status,'visible'=>($item['status']==='ACTIVE'||$item['status']==='PUBLISHED'&&(!($item['publishedAt'] ?? null)||new DateTimeImmutable($item['publishedAt'])<=new DateTimeImmutable()))]+$shared;}
        return $rows;
    }
    public function save(array $input,string $actor):array
    {
        if(array_diff(array_keys($input),['kind','id','version','publish','english'])||!is_string($input['kind'] ?? null)||!is_string($input['id'] ?? null)||(strlen($input['id'])<1||strlen($input['id'])>80)||!is_int($input['version'] ?? null)||$input['version']<1||!is_bool($input['publish'] ?? null)||!is_array($input['english'] ?? null))throw new HttpError(400,'Dữ liệu bản dịch không hợp lệ.');
        $kind=$input['kind'];$service=$this->service($kind);$item=null;foreach($service->list(true) as $candidate)if($candidate['id']===$input['id']){$item=$candidate;break;}if(!$item)throw new HttpError(404,'Không tìm thấy nội dung.');if($item['version']!==$input['version'])throw new HttpError(409,'Nội dung đã thay đổi. Tải lại trước khi dịch.');
        $source=self::source($kind,$item);$english=$input['english'];$shared=$this->shared($kind,$item);if(isset($shared['sharedCategory']))$english['category']=$shared['sharedCategory']['englishName'];if(isset($shared['sharedDestination']))$english['destination']=$shared['sharedDestination']['englishName'];$english['status']=$input['publish']?'PUBLISHED':'DRAFT';$english['sourceHash']=hash('sha256',json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
        if($kind==='POST'&&($english['slug'] ?? '')===''){$title=is_string($english['title'] ?? null)?$english['title']:'';$ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',str_replace(['đ','Đ'],['d','D'],$title));$generated=rtrim(substr(trim(preg_replace('/[^a-z0-9]+/','-',strtolower($ascii===false?'':$ascii)),'-'),0,150),'-');$english['slug']=($item['english']['slug'] ?? '') ?: ($generated ?: $item['slug']);}
        $payload=$item;unset($payload['updatedAt'],$payload['views']);$payload['english']=$english;
        // Validate and sanitize before checking completeness, so script-only HTML cannot publish.
        $payload=match($kind){'PRODUCT'=>ProductSchema::validate($payload),'POST'=>Posts::validate($payload),'TYPE'=>ProductTypes::validate($payload)};$en=$payload['english'];
        if($input['publish']){
            $required=match($kind){'PRODUCT'=>['name','destination','description'],'POST'=>['title','excerpt','contentHtml'],'TYPE'=>['name']};if($kind==='PRODUCT'&&trim(strip_tags($source['detailHtml']))!=='')$required[]='detailHtml';foreach($required as $key)if(trim(strip_tags($en[$key] ?? ''))==='')throw new HttpError(400,'Điền đầy đủ nội dung tiếng Anh trước khi xuất bản.');
            if($kind==='PRODUCT'){$ids=array_column($en['variants'],'id');if(count($ids)!==count($source['variants'])||count(array_unique($ids))!==count($ids))throw new HttpError(400,'Cần dịch đủ các gói vé.');foreach($source['variants'] as $variant){$translation=null;foreach($en['variants'] as $v)if($v['id']===$variant['id'])$translation=$v;$conditions=(array)$variant['conditions'];if(!$translation||trim($translation['name'])===''||array_diff(array_keys($translation['conditions'] ?? []),array_keys($conditions)))throw new HttpError(400,'Dịch đủ tên gói và điều kiện nhóm khách.');foreach($conditions as $g=>$text)if($text!==''&&trim($translation['conditions'][$g] ?? '')==='')throw new HttpError(400,'Dịch đủ điều kiện nhóm khách.');}}
        }
        return $kind==='POST'?$service->save($payload,$actor,'TRANSLATE'):$service->save($payload,$actor);
    }
}
