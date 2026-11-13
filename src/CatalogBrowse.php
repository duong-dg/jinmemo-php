<?php
declare(strict_types=1);

final class CatalogBrowse
{
    public function __construct(private PDO $db){}
    public static function filters(array $input):array
    {
        $out=[];foreach(['q'=>200,'type'=>80,'destination'=>160] as $key=>$limit){$value=$input[$key] ?? '';if(!is_string($value))throw new HttpError(400,'Bộ lọc không hợp lệ.');$out[$key]=mb_substr(trim($value),0,$limit,'UTF-8');}return $out;
    }
    public static function fold(string $text):string
    {
        $text=mb_strtolower($text,'UTF-8');$groups=['a'=>'àáảãạăằắẳẵặâầấẩẫậ','e'=>'èéẻẽẹêềếểễệ','i'=>'ìíỉĩị','o'=>'òóỏõọôồốổỗộơờớởỡợ','u'=>'ùúủũụưừứửữự','y'=>'ỳýỷỹỵ','d'=>'đ'];foreach($groups as $base=>$letters)$text=str_replace(preg_split('//u',$letters,-1,PREG_SPLIT_NO_EMPTY),$base,$text);return preg_replace('/[\x{0300}-\x{036f}]/u','',$text);
    }
    public static function descendants(array $nodes,string $root):array
    {
        $ids=[$root=>true];do{$changed=false;foreach($nodes as $node)if($node['parentId']&&isset($ids[$node['parentId']])&&!isset($ids[$node['id']])){$ids[$node['id']]=true;$changed=true;}}while($changed);return $ids;
    }
    public function products(array $input,string $lang='vi'):array
    {
        $filters=self::filters($input);$nodes=(new Destinations($this->db))->list();$map=array_column($nodes,null,'id');$ids=$filters['destination']!==''?self::descendants($nodes,$filters['destination']):null;$needle=self::fold($filters['q']);$items=[];
        foreach((new Products($this->db))->list(false,$lang) as $product){if($filters['type']!==''&&$product['type']!==$filters['type']||$ids!==null&&!isset($ids[$product['destinationId'] ?? '']))continue;if($needle!==''){$names=[];$cursor=$map[$product['destinationId'] ?? ''] ?? null;$seen=[];while($cursor&&!isset($seen[$cursor['id']])){$seen[$cursor['id']]=true;$names[]=$cursor['name'];$names[]=$cursor['englishName'] ?? ''; $cursor=$map[$cursor['parentId']] ?? null;}if(!str_contains(self::fold($product['name'].' '.$product['destination'].' '.implode(' ',$names)),$needle))continue;}$items[]=$product;}return $items;
    }
    public function collection(string $kind,string $slug,string $lang,array $input):array
    {
        $en=$lang==='en';$destinations=new Destinations($this->db);$nodes=$destinations->list();$localized=array_map(fn($n)=>Destinations::publicView($n,$lang),$nodes);$filters=self::filters($input);$types=(new ProductTypes($this->db))->list(false,$lang);$node=null;$children=[];$breadcrumbs=[['name'=>$en?'Home':'Trang chủ','path'=>$en?'/en':'/']];
        if($kind==='destinations'){$title=$en?'Destinations':'Điểm đến';$description=$en?'Explore local destinations and experiences.':'Khám phá điểm đến và trải nghiệm địa phương.';$paths=['vi'=>'/diem-den','en'=>'/en/destinations'];$children=array_values(array_filter($localized,fn($n)=>!$n['parentId']));$products=[];}
        elseif($kind==='destination'){$node=$destinations->find($slug,$lang);$title=$node['name'];$description=$node['description'];$paths=$node['localePaths'];$filters['destination']=$node['id'];$children=array_values(array_filter($localized,fn($n)=>$n['parentId']===$node['id']));$breadcrumbs[]=['name'=>$en?'Destinations':'Điểm đến','path'=>$en?'/en/destinations':'/diem-den'];$map=array_column($localized,null,'id');$ancestors=[];$parent=$node['parentId'];$seen=[$node['id']=>true];while($parent&&isset($map[$parent])&&!isset($seen[$parent])){$seen[$parent]=true;$ancestor=$map[$parent];array_unshift($ancestors,['name'=>$ancestor['name'],'path'=>$ancestor['localePaths'][$lang]]);$parent=$ancestor['parentId'];}$breadcrumbs=array_merge($breadcrumbs,$ancestors);$products=$this->products($filters,$lang);}
        elseif($kind==='type'){$node=(new ProductTypes($this->db))->find($slug,$lang);$title=$node['name'];$description=$node['description'];$paths=['vi'=>'/loai/'.$node['slug']];if(in_array('en',$node['languages'],true))$paths['en']='/en/loai/'.$node['slug'];$filters['type']=$node['id'];$products=$this->products($filters,$lang);}
        else throw new HttpError(404,'Không tìm thấy danh mục.');
        $page=$input['page'] ?? '1';if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page))throw new HttpError(400,'Trang danh mục không hợp lệ.');$total=count($products);$pages=max(1,(int)ceil($total/24));$page=min((int)$page,$pages);return ['kind'=>$kind,'node'=>$node,'title'=>$title,'description'=>$description,'paths'=>$paths,'image'=>$kind==='destination'?($node['cover'] ?? null):null,'children'=>$children,'breadcrumbs'=>$breadcrumbs,'products'=>array_slice($products,($page-1)*24,24),'total'=>$total,'page'=>$page,'pages'=>$pages,'filters'=>$filters,'types'=>$types,'destinations'=>$localized];
    }
}
