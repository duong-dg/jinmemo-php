# Tiến độ chuyển phần thanh toán

Cập nhật 17/11/2026, sau đợt 100. Schema cần có: **049** (mở rộng độ dài method, giữ tên/thứ tự trường).

| Luồng | Trạng thái PHP |
| --- | --- |
| Vé đơn, giỏ WEB/CTV/POS, thu thủ công và công nợ CTV | Đã có API, giao diện và kiểm thử |
| Lựa chọn phương thức thủ công, lưu hướng dẫn tại lúc đặt | Đã có cho vé đơn/giỏ web; dùng chung cấu hình thủ công |
| JB gom giỏ vé WEB, xe WEB và quà | Đã có tạo/tra cứu, kiểm proof, chống gom trùng và snapshot từng giá |
| Admin thu JB CASH/BANK | Đã có giao dịch nguyên khối, replay, chặn thu con; không tự gắn ca quầy |
| Hoàn JB và báo cáo thu–hoàn | Đã có cap theo thực thu, chặn hoàn con và tránh đếm lại phân bổ vé |
| VNPAY / QR / thẻ quốc tế VNPAY | Đã có cấu hình TEST/LIVE, checkout và IPN cho vé đơn WEB, JC WEB, JB và phòng JH; chưa thử giao dịch nhà cung cấp thật |
| SePay chuyển khoản API Key / HMAC / OAuth | Đã có cấu hình, checkout, webhook và journal cho vé WEB, JC, JB, JH; chưa thử ngân hàng thật |
| QR SePay cho xe WEB JM-X | Đã có JMR, tra cứu/admin, webhook, báo cáo/refund và đối soát REVIEW; chưa thử ngân hàng thật |
| SePay OAuth token | Đã có Client Credentials Basic/form, JSON tương thích, Bearer có hạn và cấu hình mã hóa; chưa thử tài khoản SePay thật |
| Thẻ SePay | Đã có Merchant/khóa mã hóa, form TEST/LIVE, IPN X-Secret-Key và ghi thu vé WEB/JC/JB/JH; chưa thử tài khoản thật |
| PayPal, online POS/CTV | Còn thiếu |
| Đặt phòng online JH | Đã có giữ phòng 30 phút, IPN nguyên khối, email PAID và REVIEW sau hết hạn |
| Nhật ký VNPAY/SePay, chống ghi thu trùng | Đã có và lưu lịch sử đối soát |
| Đối soát SePay LIVE REVIEW | Đã có preview/xác nhận cho vé WEB, JC, JB, JH và xe WEB JM-X; ghi BANK theo quản trị viên, giữ lý do/đơn ban đầu |
| Đối soát POS/CTV, hoàn qua ngân hàng | Chưa mở; không chia hoặc bù khoản thu |
| Import SQL/dữ liệu cũ | Chưa thực hiện; giữ tên/thứ tự trường theo TRANSFER-CONTRACT.json |

Phần thanh toán nguồn chưa được chuyển đủ toàn bộ. Các luồng online còn cần đối chiếu `payments.service.ts`, `protocol.ts`, `settings.ts`, `ride-payments.ts`, `hotel-payments.ts`, `paypal.ts`, `reconciliation.ts` và bộ kiểm thử tương ứng tại sourceCommit ghi trong hợp đồng chuyển dữ liệu.

Đã kiểm PHP/MySQL, HTTP cục bộ và Node DOM. Các kết quả này chưa thay cho kiểm giao diện trên trình duyệt, hosting thật hoặc giao dịch sandbox/live của nhà cung cấp.
