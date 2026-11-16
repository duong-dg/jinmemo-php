# Tiến độ chuyển phần thanh toán

Cập nhật 16/11/2026, sau đợt 93. Schema cần có: **043**.

| Luồng | Trạng thái PHP |
| --- | --- |
| Vé đơn, giỏ WEB/CTV/POS, thu thủ công và công nợ CTV | Đã có API, giao diện và kiểm thử |
| Lựa chọn phương thức thủ công, lưu hướng dẫn tại lúc đặt | Đã có cho vé đơn/giỏ web; dùng chung cấu hình thủ công |
| JB gom giỏ vé WEB, xe WEB và quà | Đã có tạo/tra cứu, kiểm proof, chống gom trùng và snapshot từng giá |
| Admin thu JB CASH/BANK | Đã có giao dịch nguyên khối, replay, chặn thu con; không tự gắn ca quầy |
| Hoàn JB và báo cáo thu–hoàn | Đã có cap theo thực thu, chặn hoàn con và tránh đếm lại phân bổ vé |
| VNPAY, QR, SePay, thanh toán thẻ, PayPal | Chưa chuyển đầy đủ cấu hình, checkout và callback sang PHP |
| Journal giao dịch cổng, IPN/webhook, REVIEW, đối soát tự động | Cần chuyển cùng các cổng; không đánh dấu đã hoạt động |
| Import SQL/dữ liệu cũ | Chưa thực hiện; giữ tên/thứ tự trường theo TRANSFER-CONTRACT.json |

Phần thanh toán nguồn chưa được chuyển đủ toàn bộ. Các luồng online còn cần đối chiếu `payments.service.ts`, `protocol.ts`, `settings.ts`, `ride-payments.ts`, `hotel-payments.ts`, `paypal.ts`, `reconciliation.ts` và bộ kiểm thử tương ứng tại sourceCommit ghi trong hợp đồng chuyển dữ liệu.

Đã kiểm PHP/MySQL, HTTP cục bộ và Node DOM. Các kết quả này chưa thay cho kiểm giao diện trên trình duyệt, hosting thật hoặc giao dịch sandbox/live của nhà cung cấp.
