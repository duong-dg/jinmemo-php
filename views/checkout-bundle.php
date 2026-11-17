<section id="draft-checkout" data-lang="<?=$lang?>" class="page-content draft-checkout">
<h1><?=$en?'Review your order':'Kiểm tra đơn hàng'?></h1>
<p id="draft-checkout-intro"><?=$en?'Book your selected tickets, transfer and souvenirs together. Review the details before confirming.':'Đặt vé, xe và quà đã chọn trong cùng một lần chốt đơn. Kiểm tra thông tin trước khi xác nhận.'?></p>
<div id="draft-checkout-message" role="status"></div><div id="draft-checkout-content"></div>
<p><a href="<?=$en?'/en/checkout':'/chot-don'?>?view=lookup"><?=$en?'Look up a combined order':'Tra cứu đơn thanh toán chung'?></a> · <a href="<?=$en?'/en/checkout':'/chot-don'?>?view=combine"><?=$en?'Combine bookings you already made':'Gom các đơn đã đặt trước đó'?></a></p>
</section>
<section id="combined-checkout" hidden data-lang="<?=$lang?>" class="page-content">
<h1><?=$en?'Combined checkout':'Thanh toán chung'?></h1>
<p><?=$en?'Combine existing unpaid website ticket carts, rides and gifts. This creates a payment reference; it does not collect money or create new bookings.':'Gom giỏ vé website, đơn xe và quà chưa thanh toán đã đặt trước đó. Thao tác này tạo mã tham chiếu, không thu tiền hoặc tạo thêm booking.'?></p>
<div id="checkout-message" role="status"></div><div id="checkout-create"></div><div id="checkout-result"></div><div id="checkout-lookup"></div>
</section>
