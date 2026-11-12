<?php
declare(strict_types=1);

final class ContentHtml
{
    public static function clean(string $html): string
    {
        $doc=new DOMDocument('1.0','UTF-8'); $previous=libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body>'.$html.'</body></html>',LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $body=$doc->getElementsByTagName('body')->item(0); if(!$body) return '';
        $allowed=['p','br','h2','h3','h4','strong','em','u','s','ul','ol','li','blockquote','pre','code','a','img','hr'];
        $walk=function(DOMNode $parent) use(&$walk,$allowed) {
            foreach(iterator_to_array($parent->childNodes) as $node) {
                if($node instanceof DOMComment || $node instanceof DOMProcessingInstruction) { $parent->removeChild($node); continue; }
                if(!$node instanceof DOMElement) continue;
                $tag=strtolower($node->tagName);
                if(in_array($tag,['script','style','iframe','object','embed','svg','math','template','noscript','textarea'],true)) { $parent->removeChild($node); continue; }
                $walk($node);
                if(!in_array($tag,$allowed,true)) { while($node->firstChild) $parent->insertBefore($node->firstChild,$node); $parent->removeChild($node); continue; }
                $attrs=[];
                if($tag==='a') {
                    $href=$node->getAttribute('href'); $check=preg_replace('/[\x00-\x20\x7f]+/','',$href);
                    if($href!=='' && !str_starts_with($check,'//') && (!preg_match('/^([a-z][a-z0-9+.-]*):/i',$check,$scheme) || in_array(strtolower($scheme[1]),['http','https','mailto','tel'],true))) $attrs['href']=$href;
                    if($node->hasAttribute('title')) $attrs['title']=$node->getAttribute('title');
                }
                if($tag==='img') {
                    $src=$node->getAttribute('src');
                    if(!preg_match('#^/api/v1/media/[0-9a-f-]{36}$#D',$src)) { $parent->removeChild($node); continue; }
                    $attrs=['src'=>$src,'alt'=>$node->getAttribute('alt'),'loading'=>'lazy'];
                }
                foreach(iterator_to_array($node->attributes) as $attribute) $node->removeAttribute($attribute->name);
                foreach($attrs as $key=>$value) $node->setAttribute($key,$value);
            }
        };
        $walk($body); $result=''; foreach($body->childNodes as $node) $result.=$doc->saveHTML($node); return $result;
    }
    public static function imageIds(string $html): array { preg_match_all('#src="/api/v1/media/([0-9a-f-]{36})"#',$html,$matches); return array_unique($matches[1]); }
}
