<?php
declare(strict_types=1);
require_once __DIR__.'/Pages.php';require_once __DIR__.'/ContentHtml.php';
final class PolicyPages
{
    public function __construct(private PDO $db){}
    private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public function seed():array
    {
        $defaults=json_decode(file_get_contents(dirname(__DIR__).'/resources/default-policies.json'),true,512,JSON_THROW_ON_ERROR);$created=0;$adopted=0;$this->db->beginTransaction();try{if(!$this->query("SELECT id FROM jingo_catalog_locks WHERE id='catalog' FOR UPDATE")->fetchColumn())throw new RuntimeException('Catalog lock missing.');$existing=$this->query('SELECT id,slug,data FROM jingo_pages')->fetchAll(PDO::FETCH_ASSOC);
            foreach($defaults as $i=>$policy){if($this->query('SELECT page_id FROM jingo_policy_pages WHERE policy_key=?',[$policy['key']])->fetchColumn())continue;$id='00000000-0000-4000-8000-'.str_pad((string)(101+$i),12,'0',STR_PAD_LEFT);$alias=$this->query("SELECT entity_id FROM jingo_slug_aliases WHERE kind='PAGE' AND slug=?",[$policy['slug']])->fetchColumn();$current=null;foreach($existing as $p)if($p['id']===$id||$p['slug']===$policy['slug']||$p['id']===$alias){$current=$p;break;}
                if($current){$this->query('INSERT INTO jingo_policy_pages(policy_key,page_id) VALUES (?,?)',[$policy['key'],$current['id']]);$adopted++;continue;}
                $enSlug=$policy['enSlug'];$suffix=2;while(true){$used=$this->query("SELECT entity_id FROM jingo_slug_aliases WHERE kind='PAGE_EN' AND slug=?",[$enSlug])->fetchColumn()!==false;foreach($existing as $p){$d=json_decode($p['data'],true,512,JSON_THROW_ON_ERROR);if(($d['english']['slug']??null)===$enSlug)$used=true;}if(!$used)break;$enSlug=$policy['enSlug'].'-'.$suffix++;}
                $title=$policy['title'];$enTitle=$policy['enTitle'];$page=Pages::validate(['id'=>$id,'slug'=>$policy['slug'],'kind'=>'PAGE','title'=>$title,'description'=>$title.' tại JinMemo.','contentHtml'=>$policy['vi'],'seoTitle'=>$title.' | JinMemo','seoDescription'=>$title.' khi đặt vé, xe và quà lưu niệm tại JinMemo.','menuLabel'=>$title,'status'=>!empty($policy['draft'])?'DRAFT':'PUBLISHED','version'=>0,'showInMenu'=>false,'showInFooter'=>true,'sortOrder'=>200+$i*10,'cover'=>null,'contact'=>null,'english'=>['slug'=>$enSlug,'title'=>$enTitle,'description'=>$enTitle.' at JinMemo.','contentHtml'=>$policy['en'],'seoTitle'=>$enTitle.' | JinMemo','seoDescription'=>$enTitle.' for tickets, transfers and souvenirs at JinMemo.','menuLabel'=>$enTitle,'published'=>empty($policy['draft'])]]);$raw=json_encode($page,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$this->query('INSERT INTO jingo_pages(id,slug,data) VALUES (?,?,?)',[$id,$page['slug'],$raw]);$this->query('INSERT INTO jingo_policy_pages(policy_key,page_id) VALUES (?,?)',[$policy['key'],$id]);$existing[]=['id'=>$id,'slug'=>$page['slug'],'data'=>$raw];$created++;
            }$this->db->commit();return ['created'=>$created,'adopted'=>$adopted];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function links(string $lang='vi'):array
    {
        $pages=array_column((new Pages($this->db))->list(),null,'id');$out=[];foreach($this->query('SELECT policy_key,page_id FROM jingo_policy_pages ORDER BY policy_key')->fetchAll(PDO::FETCH_ASSOC) as $r){$p=$pages[$r['page_id']]??null;if(!$p||$p['status']!=='PUBLISHED'||$lang==='en'&&empty($p['english']['published']))continue;$view=Pages::view($p,$lang);$out[$r['policy_key']]=['label'=>$view['title'],'href'=>$view['localePaths'][$lang==='en'?'en':'vi']];}return $out;
    }
}
