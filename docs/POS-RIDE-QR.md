# Tra cứu và tạo QR thu tiền xe tại POS — đợt 128

Chuyển luồng `pos/rides/status` và `pos/rides/payment` của nguồn Node. Trong **Quầy → Thu tiền xe**, nhân viên có quyền COLLECT tra mã JM-X, xem trạng thái/tổng/tuyến, rồi chủ động bấm tạo QR nếu đủ điều kiện. Không tạo QR khi chỉ tra cứu hoặc tự ghi nhận đã thanh toán từ việc mở QR.

## API và quyền

- `POST /api/v1/pos/rides/status`, body `{code}`: trạng thái thu/chuyến, tổng tiền và tên tuyến. Biên nhận tiền mặt chỉ trả khi nhân viên có quầy tương ứng, như luồng cũ; thêm `qrAvailable` để điều khiển nút QR. Không trả contact, ghi chú, request key hoặc dữ liệu vận hành khác.
- `POST /api/v1/pos/rides/payment`, body `{code}`: tạo/tái sử dụng QR SePay LIVE theo hợp đồng JMR hiện có. Cần phiên POS hợp lệ, COLLECT và Origin/header mutation; không nhận query bổ sung. Không cần khóa tra cứu của khách cho nhân viên được cấp quyền.

Kiểm lại tài khoản ACTIVE và COLLECT trong transaction có khóa catalog trước khi tạo hoặc trả lại QR đang chờ. Mất quyền bị chặn dù service nhận actor từ trước đó. Audit tạo QR ghi `pos:STAFF_ID`, không giả thành thao tác của khách. Quyền SELL riêng không đủ thu/tạo QR. Tạo booking POS vẫn yêu cầu PAY_LATER; thu tiền là bước riêng sau xác nhận/chốt nhóm.

## Điều kiện và ghi thu

SePay phải sẵn sàng LIVE, bật QR trong cấu hình xe, URL public HTTPS. Chỉ nhận đơn WEB/POS chưa trả tiền, tổng hợp lệ và đã xác nhận/chốt nhóm; chặn đơn hủy, còn chờ nhóm/chờ xác nhận hoặc thuộc JB. Giữ cấu hình ngân hàng/tổng tiền/thời hạn và tái sử dụng attempt đang hợp lệ, không sinh QR mới khi gửi lặp.

Đơn POS vẫn bị chặn ở endpoint thanh toán công khai dù có request key. Chỉ đường dẫn xác thực nhân viên/admin mới được tạo QR thu đơn POS. Webhook đã xác thực tiếp tục kiểm memo, ngân hàng, số tiền, thời hạn và trạng thái; xử lý trùng một lần, lưu REVIEW khi không phù hợp. Thanh toán không tự chuyển trạng thái điều phối/chốt chuyến.

Ghi thu QR vào payment reference/ledger của cổng, không tạo cash movement, không tăng tiền mặt ca hoặc tạo đơn vé giả. Đối soát thủ công vẫn có quy tắc BANK hiện có, khác với giao dịch cổng tự động GATEWAY. Sau khi UI nhận QR, khóa phần nhập thu tiền mặt của kết quả hiện tại; backend luôn chặn tiền mặt khi còn QR hiệu lực, kể cả cửa sổ/tab khác. Nhân viên tra lại để xem kết quả sau chuyển khoản; không thu tiền khách lần nữa khi mất phản hồi.

## Cập nhật và kiểm thử

`git pull origin main`, không SQL migration mới; schema vẫn 053. Không tự bật LIVE, đổi khóa SePay hoặc gửi giao dịch thật. Các endpoint `/rides/cash/*` và in biên nhận hiện có vẫn hoạt động.

Đã kiểm MySQL/HTTP và Node DOM: COLLECT/SELL, Origin/session/method/query, live revocation, confirmed/pending, public POS proof bị chặn, QR replay, webhook trùng một lần, sổ GATEWAY không đếm đôi và ca tiền mặt không đổi. UI tra cứu không tự tạo QR, chỉ bấm mới gửi, khóa thu cash sau tạo QR và bỏ view QR khi đổi mã. Regression đặt xe POS, thu tiền mặt và QR WEB/admin đã qua. Chưa thử tài khoản SePay/ngân hàng thật, cPanel hoặc layout trình duyệt thực tế.

```bash
php tests/ride-payments.php
php tests/pos-rides.php
node tests/ride-cash-ui.cjs
node tests/ride-payments-ui.cjs
```

PHP test dùng JINGO_TEST_DSN trỏ MySQL thử riêng, tự tạo/xóa DB ngẫu nhiên; provider/webhook dùng fixture mô phỏng, không chuyển tiền thật.
