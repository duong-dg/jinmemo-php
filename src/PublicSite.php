<?php
declare(strict_types=1);

final class PublicSite
{
    public function __construct(private PDO $db,private array $config) {}
    public static function error(int $status): never
    {
        $errorStatus=$status;http_response_code($status);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');require dirname(__DIR__).'/views/error.php';exit;
    }
    public function render(string $path): never
    {
        $lang=str_starts_with($path,'/en')?'en':'vi';$en=$lang==='en';$pages=new Pages($this->db);$settings=new SiteSettings($this->db,$this->config);$branding=$settings->get('brand',true);$seo=$settings->get('site-seo',true);
        $base=rtrim($this->config['PUBLIC_BASE_URL'] ?? '','/');$parts=parse_url($base);
        if(!$parts || !isset($parts['scheme'],$parts['host']) || !in_array($parts['scheme'],['http','https'],true) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']))throw new HttpError(503,'Chưa cấu hình URL website.');
        $base=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        $home=in_array($path,['/','/en','/en/'],true);$isProduct=(bool)preg_match('#^/(?:en/)?ve/[^/]+/?$#D',$path);$page=null;$product=null;$published=$pages->published($lang);$navigation=$pages->navigation($lang);$catalog=new Products($this->db);$publicProducts=$home?$catalog->list(false,$lang):[];
        $isLookup=in_array($path,['/tra-cuu','/tra-cuu/','/en/orders','/en/orders/'],true);
        $isBlog=in_array($path,['/cam-nang','/cam-nang/','/en/blog','/en/blog/'],true);$isPost=(bool)preg_match('#^/(?:cam-nang|en/blog)/[^/]+/?$#D',$path);$post=null;$blogPosts=[];
        if($isBlog){$canonicalPath=$en?'/en/blog':'/cam-nang';$title=$en?'Travel journal':'Cẩm nang du lịch';$description=$en?'Local stories and ideas for your next journey.':'Câu chuyện địa phương và kinh nghiệm cho chuyến đi của bạn.';$image=null;$languagePaths=['vi'=>'/cam-nang','en'=>'/en/blog'];$blogPosts=(new Posts($this->db))->list(false,$lang);}
        elseif($isPost){$post=(new Posts($this->db))->find(rawurldecode(basename(rtrim($path,'/'))),$lang);$canonicalPath=$post['localePaths'][$lang];$languagePaths=$post['localePaths'];$title=$post['seoTitle'] ?: $post['title'];$description=$post['seoDescription'] ?: $post['excerpt'];$image=$post['cover'];}
        elseif($isLookup){$canonicalPath=$en?'/en/orders':'/tra-cuu';$title=$en?'Find your booking':'Tra cứu đơn hàng';$description=$en?'View your booking with your booking code and email.':'Xem đơn hàng bằng mã đơn và email đã đặt.';$image=null;$languagePaths=['vi'=>'/tra-cuu','en'=>'/en/orders'];}
        elseif($home){$canonicalPath=$en?'/en':'/';$title=$seo[$lang]['title'];$description=$seo[$lang]['description'];$image=$seo['image'];$languagePaths=['vi'=>'/','en'=>'/en'];}
        elseif($isProduct){$raw=$catalog->find(rawurldecode(basename(rtrim($path,'/'))),true,false,$lang);$product=$catalog->publicView($raw,$lang);$canonicalPath=($en?'/en':'').'/ve/'.$product['slug'];$languagePaths=['vi'=>'/ve/'.$product['slug']];if(Products::translated($raw))$languagePaths['en']='/en/ve/'.$product['slug'];$title=$product['seoTitle'] ?: $product['name'];$description=$product['seoDescription'] ?: $product['description'];$image=$product['cover'];}
        else {
            $contact=in_array($path,['/lien-he','/lien-he/','/en/contact','/en/contact/'],true);
            $slug=$contact?'':rawurldecode(basename(rtrim($path,'/')));$page=$pages->find($slug,$lang,$contact);$canonicalPath=$page['localePaths'][$lang];$languagePaths=$page['localePaths'];
            $title=$page['seoTitle'] ?: $page['title'];$description=$page['seoDescription'] ?: $page['description'];$image=$page['cover'];
        }
        if($path!==$canonicalPath){header('Location: '.$canonicalPath,true,308);exit;}
        $canonical=$base.$canonicalPath;$indexing=!$isLookup&&($this->config['SEO_INDEXING'] ?? 'false')==='true';
        header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        require dirname(__DIR__).'/views/public.php';exit;
    }
}
