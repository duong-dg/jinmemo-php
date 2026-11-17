<?php declare(strict_types=1);$cartLookupView=($_GET['view']??'')==='lookup'; ?>
<section id="ticket-cart" data-lang="<?=$lang?>" data-view="<?=$cartLookupView?'lookup':'cart'?>">
<h1 id="cart-page-title"><?=$cartLookupView?($en?'Your booked tickets':'Giỏ vé đã đặt'):($en?'Your basket':'Giỏ vé của bạn')?></h1>
<p id="cart-intro"><?=$cartLookupView?($en?'View the status and tickets of your booked cart.':'Xem trạng thái và nhận vé của giỏ đã đặt.'):($en?'Review your selected tickets before placing your booking.':'Kiểm tra các vé đang chọn trước khi đặt.')?></p>
<p id="cart-message" role="status"></p>
<div id="cart-editor"<?=$cartLookupView?' hidden':''?>><div id="cart-lines"></div><div id="cart-checkout"></div><div id="cart-result"></div></div>
<section id="cart-lookup"<?=$cartLookupView?'':' hidden'?>></section>
<?php if($cartLookupView):?><p><a href="<?=$en?'/en/carts':'/gio-ve'?>"><?=$en?'← Back to basket':'← Về giỏ vé đang chọn'?></a></p><?php endif;?>
<noscript><?=$en?'Enable JavaScript to use the basket.':'Bật JavaScript để dùng giỏ vé.'?></noscript>
</section>
