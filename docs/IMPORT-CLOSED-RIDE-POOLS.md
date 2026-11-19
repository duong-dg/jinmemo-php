# Import lịch sử nhóm xe đã kết thúc, chưa thu tiền

Đợt 150 thêm `--with-closed-pools` vào import xe: nhập nguyên tử kế hoạch, đơn xe thuộc phạm vi hỗ trợ và các nhóm ghép **COMPLETED/CANCELLED chưa thu tiền** vào `jingo_ride_pools`/`jingo_ride_bookings` vận hành. Có khách web, khách thêm thủ công và nhóm hủy rỗng; không gọi lại tạo nhóm, syncPool, chia tiền hoặc giữ chỗ.

## Cập nhật hosting

Backup rồi chạy trong thư mục PHP:

```bash
git pull origin main
php bin/install.php
```

Installer tự chạy schema 065, đổi thời gian tạo nhóm sang DATETIME(6) dưới UTC, giữ nguyên thời điểm và microseconds. Không cần chọn từng SQL qua phpMyAdmin. Schema đơn/receipt/refund các đợt trước tiếp tục được dùng.

## Chạy thử khi chuẩn bị chuyển thật

File full-v2 đã kiểm manifest/checksum/quan hệ, nằm ngoài webroot; copy ảnh và chuẩn bị kế hoạch theo [import xe riêng](IMPORT-PRIVATE-RIDES.md).

```bash
php bin/import-rides.php --file=/home/USER/private/export.sql --with-closed-pools
```

Nếu cùng file có đơn xe riêng đã thu SePay hoặc lịch sử hoàn, thêm các flag tương ứng `--with-paid-qr --with-refunds`. Các flag tài chính chỉ mở phạm vi xe riêng đã có adapter; không mở nhóm ghép đã thu tiền.

Khi đến lần chuyển thật mới thêm `--apply --fingerprint=GIÁ_TRỊ_DRY_RUN`, giữ nguyên file và tất cả flag của dry-run. Hiện chưa yêu cầu apply trên hosting; toàn bộ chuyển đổi còn đang phát triển. Không import SQL nguồn trực tiếp vào database PHP.

## Điều kiện nhóm và thành viên

- Nhóm có UUID, ngày/giờ hợp lệ, target 2–60, total dương trong giới hạn PHP, version còn có thể cập nhật và JSON metadata `{}` đúng luồng Node. Giữ ID, tuyến/xe/giờ, tổng tiền, target, status, data, version và created_at gốc.
- Nhận nhóm COMPLETED khi các thành viên chưa hủy đều COMPLETED, tổng khách bằng target, cùng thông tin điều phối và từng giá chia khớp splitShare theo thứ tự created_at/ID. Nhóm CANCELLED phải không còn thành viên chưa hủy; có thể rỗng. Thành viên đã hủy được giữ giá lịch sử trong khoảng chia hợp lệ, không dùng giá hiện tại để thay thế.
- Chỉ nhận WEB/SHARED/autoPool, PAY_LATER chưa thu, không receipt/event/refund/mã tổng, không POS. Khách web và khách thêm thủ công được kiểm theo cấu trúc riêng, giữ contact/email rỗng của khách thủ công đúng Node. Tuyến/xe/ngày/shareSize phải khớp nhóm; giờ đặt phải trong flexMinutes so với giờ nhóm.
- Phạm vi này yêu cầu from/to của thành viên đúng hai đầu tuyến lưu ở nhóm. Các tuyến con hoặc snapshot không chứng minh được sẽ dừng cả batch, không tự ánh xạ sang tuyến hiện tại.
- Nhóm OPEN/LOCKED/PICKUP/PICKED_UP/RUNNING, thành viên còn hoạt động, nhóm có thu/hoàn, legacy assigned trips hoặc phụ thuộc chưa hỗ trợ đều chặn. Manifest thiếu bảng pools không được xem là rỗng; không bỏ dòng để nhập phần còn lại.

## Request cũ và giá chia

Khách thủ công dùng fingerprint Node gắn pool ID và payload đã chuẩn hóa. Với khách web, snapshot có thể đã đổi giá chia sau khi thành viên khác hủy; giá lúc đặt được kiểm trong tập hữu hạn các kết quả splitShare theo total/target lịch sử của nhóm chưa thu. Thử các giá/cờ optional hợp lệ và chỉ chấp nhận khi hash khớp **fingerprint nguồn nguyên gốc**. Không ghi lại hash, bịa request hoặc gọi bảng giá hiện tại.

Giá snapshot gốc được lưu nguyên; phép chia chỉ dùng để đối chiếu. Gửi lại request web/thủ công sau import trả mã cũ kể cả ngày/nhóm đã kết thúc, không thêm khách hay cập nhật giá/chỗ/version. Tra cứu và danh sách PHP đọc được lịch sử, không xuất request_key/requestHash.

## Transaction và chạy lại

Khóa catalog chung với các thao tác xe, kiểm schema InnoDB/keys/precision, nguồn và trạng thái đích. Fingerprint gắn thêm flag cùng các nhóm đích. Nhóm/đơn đích khác nguồn, nhóm ngoài file hoặc phát sinh giao dịch sẽ chặn; không ghi đè/xóa/ghép lại. Lỗi giữa ghi nhóm, đơn và audit rollback toàn bộ graph. Chạy lại đúng nguồn không nhân đôi nhóm hoặc đơn.

Giữ giới hạn metadata nguồn 50.000 dòng/32 MiB; kiểm tối đa 50.000 nhóm đích/64 MiB JSON bằng cursor. Không tăng tồn/chỗ, tạo QR, gọi provider, ghi ca, gửi mail hoặc phát notification. Chưa import production; `operationalReady=false` vẫn giữ. Import nhóm đang hoạt động/đã thu tiền, POS/ca quầy/tiền mặt và mã tổng ghép nhiều phân hệ còn cần chuyển tiếp.
