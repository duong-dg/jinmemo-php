<?php
declare(strict_types=1);
foreach(['vi','en'] as $lang)foreach([false,true] as $lookup){$en=$lang==='en';$_GET=$lookup?['view'=>'lookup']:[];ob_start();require dirname(__DIR__).'/views/carts.php';$html=ob_get_clean();$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);$x=new DOMXPath($doc);if($x->query('//*[@id="cart-lookup" and @hidden]')->length!==($lookup?0:1)||$x->query('//*[@id="cart-editor" and @hidden]')->length!==($lookup?1:0))throw new RuntimeException('Mixed basket/lookup visibility');if(!$lookup&&str_contains($html,'href="/gio-ve?view=lookup"'))throw new RuntimeException('Default basket must not promote old orders');}
echo "PASS: server-rendered basket and booked-cart lookup have separate initial visibility in VI/EN.\n";
