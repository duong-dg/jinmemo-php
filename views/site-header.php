<?php declare(strict_types=1);
$mainLinks=[['href'=>$homePath.'#experiences','label'=>$en?'Tickets & experiences':'Vé & trải nghiệm']];
if($ridesEnabled)$mainLinks[]=['href'=>$en?'/en/rides':'/dat-xe','label'=>$en?'Transfers':'Đặt xe'];
$mainLinks[]=['href'=>$en?'/en/blog':'/cam-nang','label'=>$en?'Journal':'Cẩm nang'];
if($features['staysEnabled'])$mainLinks[]=['href'=>$en?'/en/stays':'/dat-phong','label'=>$en?'Stays':'Đặt phòng'];
if($features['giftsEnabled'])$mainLinks[]=['href'=>$en?'/en/gifts':'/qua-luu-niem','label'=>$en?'Souvenirs':'Quà lưu niệm'];
$pageLinks=array_values(array_filter($navigation,static fn($item)=>$item['menu']));$contactLink=null;$infoLinks=[];
foreach($pageLinks as $item){if($item['href']===($en?'/en/contact':'/lien-he'))$contactLink=$item;else $infoLinks[]=$item;}
$mobileLinks=array_merge($mainLinks,[['href'=>$en?'/en/orders':'/tra-cuu','label'=>$en?'Find booking ↗':'Tra cứu đơn hàng ↗']],$pageLinks);
?>
<header class="site-header"><div class="site-header-inner">
<details class="public-mobile-menu"><summary aria-label="<?=$en?'Main navigation':'Điều hướng chính'?>"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Menu</span></summary><nav aria-label="<?=$en?'Mobile navigation':'Điều hướng trên điện thoại'?>"><?php foreach($mobileLinks as $item):?><a href="<?=$e($item['href'])?>"<?=$item['href']===$canonicalPath?' aria-current="page"':''?>><?=$e($item['label'])?></a><?php endforeach;?></nav></details>
<a class="brand" href="<?=$homePath?>"><?php if($logo):?><img src="<?=$e($logo)?>" alt=""><?php endif;?><?=$e($branding['name'])?><span aria-hidden="true">✳</span></a>
<nav class="public-desktop-nav" aria-label="<?=$en?'Main navigation':'Điều hướng chính'?>"><?php foreach($mainLinks as $item):?><a href="<?=$e($item['href'])?>"<?=$item['href']===$canonicalPath?' aria-current="page"':''?>><?=$e($item['label'])?></a><?php endforeach;?>
<?php if($infoLinks):?><details class="public-page-menu"><summary><?=$en?'Information':'Thông tin'?><span aria-hidden="true">⌄</span></summary><div><?php foreach($infoLinks as $item):?><a href="<?=$e($item['href'])?>"<?=$item['href']===$canonicalPath?' aria-current="page"':''?>><?=$e($item['label'])?></a><?php endforeach;?></div></details><?php endif;?>
<?php if($contactLink):?><a href="<?=$e($contactLink['href'])?>"<?=$contactLink['href']===$canonicalPath?' aria-current="page"':''?>><?=$e($contactLink['label'])?></a><?php endif;?></nav>
<?php require __DIR__.'/language-picker.php';?><a class="cart-header-link" href="<?=$en?'/en/carts':'/gio-ve'?>" aria-label="<?=$en?'Shopping cart':'Giỏ hàng'?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2 12h12l2-9H6M9 20h.01M18 20h.01"/></svg><span><?=$en?'Basket':'Giỏ'?></span><strong class="ticket-cart-count" data-ticket-cart-count aria-hidden="true">0</strong></a>
</div></header>
