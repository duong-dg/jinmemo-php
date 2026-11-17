# Tiến độ chuyển phần thanh toán

Cập nhật 17/11/2026, sau đợt 106. Schema cần có: **050** (đợt này không thêm SQL).

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
| PayPal WEB/JC/JB/JH | Đã nối cấu hình, snapshot USD/tỷ giá, mapping, checkout, capture/webhook, trang quay về và sổ thu/báo cáo/refund; kiểm thử provider mô phỏng, chưa thử tài khoản thật |
| Online CTV cho giỏ JC | Đã có VNPAY/QR/thẻ VNPAY, SePay/thẻ SePay và PayPal trong tài khoản CTV; giữ giá net, chủ giỏ, không tạo/trả công nợ; chưa thử nhà cung cấp thật |
| Online POS cho giỏ JC | Đã có sáu lựa chọn, quyền COLLECT/quầy, giao diện quầy/admin, callback gắn ca đang mở và thống kê không tiền mặt; chưa thử nhà cung cấp thật |
| Đặt phòng online JH | Đã có giữ phòng 30 phút, IPN nguyên khối, email PAID và REVIEW sau hết hạn |
| Nhật ký VNPAY/SePay/thẻ SePay/PayPal | Đã có lọc cổng/môi trường/trạng thái, tìm mã, phân trang ổn định và lịch sử đối soát |
| Đối soát SePay LIVE REVIEW | Đã có preview/xác nhận cho vé WEB, JC, JB, JH và xe WEB JM-X; ghi BANK theo quản trị viên, giữ lý do/đơn ban đầu |
| Đối soát SePay LIVE cho JC POS/CTV | Đã có xem trước kênh/quầy/CTV, admin xác nhận toàn bộ giỏ, POS gắn ca hiện tại và CTV không thay đổi công nợ |
| Hoàn qua ngân hàng/nhà cung cấp | Chưa có lệnh chuyển tiền tự động |
| Import SQL/dữ liệu cũ | Chưa thực hiện; giữ tên/thứ tự trường theo TRANSFER-CONTRACT.json |

Phần thanh toán nguồn chưa được chuyển đủ toàn bộ. Các luồng online còn cần đối chiếu `payments.service.ts`, `protocol.ts`, `settings.ts`, `ride-payments.ts`, `hotel-payments.ts`, `paypal.ts`, `reconciliation.ts` và bộ kiểm thử tương ứng tại sourceCommit ghi trong hợp đồng chuyển dữ liệu.

Đã kiểm PHP/MySQL, HTTP cục bộ và Node DOM. Các kết quả này chưa thay cho kiểm giao diện trên trình duyệt, hosting thật hoặc giao dịch sandbox/live của nhà cung cấp.

Đợt 118 đã nối giỏ lựa chọn vào JC/JM-X/JM-G rồi JB qua journal session, có review và khôi phục từng phần. Chọn phương thức thủ công tại form chung; provider online trên form chung chưa đối chiếu đủ nguồn, trang tra cứu JB giữ luồng online hiện có. Không tự thu tiền/tạo QR; xem [luồng chốt đơn](DRAFT-CHECKOUT.md).

Đợt 119: form chung và gom mã cũ dùng danh sách manual + provider LIVE từ payments/options; JB chấp nhận sáu provider online đã sẵn sàng, giữ nguyên fingerprint/replay khi cổng tắt sau đó. WebPaymentMethods dùng chung quy tắc readiness với API options. Chọn online không gửi ID provider vào paymentPreference thủ công của vé. Bước sau đặt tra trạng thái JB, xác minh tổng tiền, đưa cổng đã chọn lên đầu; khách bấm mới tạo QR/link, không suy ra PAID từ URL. Đã kiểm provider mô phỏng và HTTP, chưa thử giao dịch thật.
