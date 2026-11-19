# Import kế hoạch xe và đơn xe riêng chưa thu tiền

Đợt 145 thêm `bin/import-rides.php`: đọc full-v2, nhập nguyên tử cấu hình `transport-plan` và các đơn WEB/PRIVATE/PAY_LATER chưa thu tiền, không thuộc mã tổng vào **`jingo_settings` và `jingo_ride_bookings` đang vận hành**.

Đây là phạm vi riêng, chưa phải toàn bộ module xe. Có xe ghép/nhóm ghép, POS, QR, đã thu/hoàn, liên kết mã tổng hoặc cấu trúc chưa chứng minh được thì **dừng cả batch**; không bỏ các dòng đó để nhập một phần và không gọi lại ghép nhóm.

Đợt 147 có phạm vi mở rộng tùy chọn `--with-paid-qr` cho đơn xe riêng đã thu đủ qua SePay kèm sự kiện/ledger gốc; xem [IMPORT-PAID-RIDES.md](IMPORT-PAID-RIDES.md). Các điều kiện trong tài liệu này áp dụng cho lệnh mặc định không có flag; QR chưa trả, tiền mặt, hoàn tiền, POS và xe ghép vẫn chưa được mở.

Đợt 148 mở thêm lịch sử hoàn cho xe SePay khi có cả `--with-paid-qr --with-refunds`; xem [IMPORT-REFUNDED-RIDES.md](IMPORT-REFUNDED-RIDES.md). Phạm vi mặc định vẫn giữ nguyên.

## Khi cập nhật code

Backup rồi chạy trong thư mục PHP:

```bash
git pull origin main
php bin/install.php
```

Installer tự chạy schema 063. Thời gian tạo đơn xe chuyển từ TIMESTAMP sang DATETIME(6) theo UTC để giữ nguyên thời điểm và sáu chữ số phần giây; chạy lại không xóa dữ liệu. Không cần chọn từng SQL trong phpMyAdmin.

## Khi đến lần import thật

Chốt dừng ghi nguồn, giữ SQL ngoài webroot, copy/import ảnh xe trước. Chạy thử:

```bash
php bin/import-rides.php --file=/home/USER/private/export.sql
```

Duyệt báo cáo rồi chạy lại cùng file với `--apply --fingerprint=GIÁ_TRỊ_TỪ_DRY_RUN`. Không chạy apply trên hosting trong giai đoạn còn phát triển. Không nhập SQL nguồn trực tiếp vào database PHP.

## Được giữ và kiểm

- Kế hoạch: nguyên JSON/version nguồn, xe/khu vực/tuyến, giá hiện tại, note Việt/Anh, chuyến và yêu cầu thủ công trong kế hoạch. Dùng validator TransportPlan hiện tại để kiểm cấu trúc/tham chiếu/ghế, không gọi save để tăng version hoặc viết lại dữ liệu. Ảnh tham chiếu phải có trong thư viện PHP.
- Đơn: ID, mã JM-X, request_key, visit_date, trạng thái, version, created_at chính xác, nguyên snapshot giá/tuyến/liên hệ/khứ hồi/chi phí đại lý/điều phối và requestHash. Giá đơn lịch sử không bị thay bằng giá kế hoạch hiện tại. Không chạy tạo booking, thông báo, giữ chỗ, tính lại giá hoặc thanh toán.
- Nhận PENDING, MATCHED, WAITING_PICKUP, PICKED_UP, RUNNING, COMPLETED, CANCELLED cho xe riêng hợp lệ. Đơn đang hoạt động cần xe/khu vực còn trong kế hoạch, đủ ghế và thông tin tài xế theo trạng thái. Đơn đã kết thúc được giữ snapshot cũ dù danh mục đã đổi.
- Kiểm fingerprint nguồn với payload booking có thể khôi phục từ snapshot, theo đúng thứ tự trường Zod và JSON của Node. Xử lý việc PostgreSQL JSONB đổi thứ tự khóa, tiếng Việt, dấu `/`, U+2028/U+2029, và cờ khứ hồi bị giá tuyến khứ hồi ghi đè. Không thay hash gốc hoặc tự đoán giá. Không chứng minh được thì báo `RIDE_REQUEST_SNAPSHOT_MISMATCH` để bổ sung adapter, không sửa payload cho khớp.
- PHP nhận replay request Node tương ứng và vẫn nhận hash PHP cũ; chỉ trả lại mã đã có. Không tạo mới booking, thông báo hoặc khoản thu. Request khác nội dung vẫn bị từ chối.
- Xe riêng MATCHED cũ có thể tiếp tục sang WAITING_PICKUP hoặc hủy qua luồng điều phối PHP; giữ kiểm tài xế/điện thoại/biển số và version. Không mở thêm bước chuyển trạng thái cho nhóm ghép.

## Điều kiện chặn

Manifest phải xác nhận xuất settings, ride_bookings, ride_pools, ride_payment_events, payment_references, refunds, checkout_members và checkout_bundles. Thiếu bảng không được xem là rỗng. Kiểm liên kết mã tổng ở cả membership và JSON refs, ở cả nguồn lẫn đích; có liên kết thì cần adapter mã tổng tương ứng.

Cấu hình hoặc đơn đích khác nguồn, có đơn/nhóm đích ngoài scope, hoặc phát sinh khoản thu/liên kết sau dry-run sẽ chặn. Không ghi đè, xóa, gán lại chuyến hoặc đặt lại dispatch/giá sau khi vận hành. Replay cùng dữ liệu cần dry-run mới; không thêm đơn/audit lần nữa.

Đợt 146 bổ sung chặn khi đơn đích có bất kỳ `jingo_ride_payment_attempts` nào, kể cả QR hết hạn, thất bại hoặc đã trả: báo `RUNTIME_RIDE_PAYMENT_RECONCILIATION_REQUIRED`. Không tự xóa hoặc đổi trạng thái yêu cầu QR để nhập lại. Kiểm lại dưới transaction khi apply, nên một QR phát sinh sau dry-run cũng chặn fingerprint đã duyệt. Lịch sử hoàn tiền được đối chiếu cả ID và mã đơn; mã khớp nhưng ID lệch vẫn dừng để đối soát. Báo cáo không xuất token QR hoặc nội dung giao dịch.

Apply dùng khóa catalog, các khóa bản ghi và transaction InnoDB; lỗi ở audit/ảnh/schema/dữ liệu phụ thuộc rollback toàn bộ kế hoạch và đơn. Giới hạn nguồn được chọn 50.000 dòng/32 MiB metadata; đích tối đa 50.000 đơn/64 MiB JSON, các chỉ mục tham chiếu có giới hạn riêng. Báo cáo không chứa PII, request_key, requestHash hoặc snapshot.

QR, giao dịch đã thu, ca POS, xe ghép và mã tổng ghép nhiều phân hệ vẫn chờ các adapter tiếp theo. Gateway attempts không có trong export; giao dịch treo cần được đối soát trước khi chuyển thật, không mở hai hệ thống đồng thời ghi thu/giữ chỗ.

Đã kiểm local bằng request được chuẩn hóa từ schema booking Node thực và SQL qua helper export đã ghim, gồm snapshot JSONB có thứ tự khóa khác. Chưa import production hoặc xác nhận hoàn tất toàn bộ xe; `operationalReady=false` toàn hệ thống vẫn giữ.

Xem [phối hợp export/import](EXPORT-IMPORT-COORDINATION.md), [sổ hoàn](IMPORT-REFUNDS.md) và [mã tổng quà](IMPORT-GIFT-BUNDLES.md).
