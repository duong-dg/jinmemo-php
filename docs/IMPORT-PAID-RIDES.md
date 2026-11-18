# Import đơn xe riêng đã thu qua SePay

Đợt 147 mở rộng `bin/import-rides.php --with-paid-qr`: nhập kế hoạch, đơn WEB/PRIVATE độc lập chưa thu theo phạm vi cũ và đơn đã thu đủ qua SePay LIVE. Giữ nguyên snapshot, requestHash, giá, version, thời gian và ba phần của khoản thu: `jingo_payment_references`, `jingo_gateway_events`, `jingo_ride_payment_events`. Không gọi lại settlement, gửi mail, tạo QR hay thu thêm tiền.

## Cập nhật hosting

Backup rồi chạy trong thư mục PHP:

```bash
git pull origin main
php bin/install.php
```

Installer tự chạy schema 064, chuyển `jingo_gateway_events.created_at` từ TIMESTAMP(6) sang DATETIME(6) dưới múi giờ UTC. Giữ thời điểm gốc và microseconds, kể cả các sự kiện không thuộc xe; không xóa lịch sử. Không cần nhập từng SQL qua phpMyAdmin.

## Chạy thử khi chuẩn bị chuyển dữ liệu

SQL full-v2 nằm ngoài webroot, đã kiểm checksum/manifest/quan hệ. Copy ảnh và chuẩn bị các phụ thuộc theo [import xe riêng](IMPORT-PRIVATE-RIDES.md), rồi chạy dry-run:

```bash
php bin/import-rides.php --file=/home/USER/private/export.sql --with-paid-qr
```

Báo cáo có `paidRows`, `insertedReceipts`, `ready`, fingerprint và mã lỗi, không xuất khóa tra cứu, token hay liên hệ khách. Khi đến lần chuyển thật mới dùng cùng file, cùng flag, thêm `--apply --fingerprint=GIÁ_TRỊ_DRY_RUN`. Hiện chưa yêu cầu chạy apply trên hosting; việc chuyển toàn bộ hệ thống còn đang phát triển. Không import SQL nguồn trực tiếp vào database PHP.

## Điều kiện cho khoản thu

- Đơn WEB/PRIVATE không thuộc mã tổng, `paymentStatus=PAID`, `paidAmount=total` dương trong giới hạn PHP. `payment` có thể là QR hoặc PAY_LATER nếu sau đó khách đã trả qua SePay. Nguyên fingerprint booking Node phải kiểm được bằng snapshot lịch sử.
- Chính xác một tham chiếu `RIDE_PAYMENT`, một liên kết sự kiện của đơn và sự kiện `SEPAY/LIVE/PAID`. Cùng reference, amount và ID đơn; reference đúng dạng `SEPAY:LIVE:<số giao dịch>`, fingerprint gốc đủ 64 ký tự hex. Thời điểm trả hợp lệ; không thay bằng thời điểm import.
- Sự kiện xe gốc do Node tạo có `attempt_id=NULL`, `order_id=NULL`; giữ nguyên hai giá trị này. File export không có QR attempts, nên không tự dựng attempt giả hoặc gán sang đơn vé. Thiếu bằng chứng, sự kiện REVIEW, đã xử lý thủ công hoặc dùng chung cho nhiều xe thì dừng cả batch.
- Manifest phải khai báo xuất thêm `gateway_events` và `gateway_resolutions`. Bảng thiếu không được xem là rỗng.
- Không nhận đơn đã có lịch sử hoàn tiền, tiền mặt/cashReceipt, POS, xe ghép, QR chưa trả, mã tổng hoặc graph chưa hỗ trợ. Các nhóm đó chờ adapter tiếp theo; không bỏ qua dòng để nhập riêng phần còn lại.

## Đích và giao dịch nguyên tử

Đơn mới phải chưa có cả khoản thu, sự kiện và liên kết ở đích. Khi chạy lại, cả ba và đơn phải khớp nguyên nguồn; chỉ có một phần, đổi fingerprint/số tiền/thời gian hoặc có thêm tham chiếu sẽ chặn. Bất kỳ QR attempt ở đích và hoàn tiền theo ID/mã cũng tiếp tục chặn, như phạm vi xe chưa thu.

Dry-run và apply kiểm lại đích trong transaction, dùng khóa catalog chung với đặt xe/thu tiền. Fingerprint bao gồm phạm vi flag và trạng thái bằng chứng đích. Lỗi tại bất kỳ bước ghi đơn, ledger, event, link hoặc audit đều rollback cả kế hoạch và graph. Chạy lại đúng dữ liệu không nhân đôi khoản thu. Giới hạn metadata nguồn vẫn 50.000 dòng/32 MiB; bằng chứng đích tích lũy tối đa 64 MiB.

Luồng tra cứu/hoàn PHP đọc được khoản thu gốc; gửi lại request booking Node chỉ trả về mã cũ. Không gọi provider để kiểm tra ngân hàng trong import. Cần dừng ghi nguồn, đối soát QR treo và xác nhận vận hành thật trước khi chuyển hệ thống; `operationalReady=false` vẫn giữ. Chưa nhập dữ liệu production.
