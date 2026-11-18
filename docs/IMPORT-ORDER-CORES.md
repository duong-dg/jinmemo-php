# Nhập lõi đơn vé vào kho chuyển đổi — đợt 137

Sau `git pull`, chạy **database/059-order-transfer-holding.sql** qua phpMyAdmin hoặc installer. Migration chỉ tạo `jingo_transfer_orders`, không chép dữ liệu hoặc thay đổi đơn đang bán. Cần giữ các migration trước, gồm schema 058 cho thời gian đơn.

`bin/import-order-cores.php` kiểm và chép dữ liệu gốc từ `jm_transfer_orders` vào **kho chờ riêng** trong database PHP. Không ghi `jingo_orders`. Chưa có cơ chế đưa kho chờ này ra vận hành: phải chuyển đủ quan hệ kênh bán, hành động, thu tiền, tồn/lô, vé/file, voucher, giỏ và công nợ rồi đối soát toàn bộ trước khi xuất bản nguyên khối.

Đây là bước chuẩn bị lõi đơn, **không phải importer đơn tổng thể**. Đơn trong kho chờ không hiển thị ở admin bán hàng/tra cứu khách, không gửi email/thông báo, không giữ chỗ hoặc tính doanh thu. Không dùng bảng này thay cho đơn cha đang vận hành trong importer voucher_uses. Chưa chạy import dữ liệu thật theo yêu cầu đợi chuyển đủ module rồi nhập một lần.

## Trường được giữ nguyên

Kho chờ có đúng 13 cột lõi nguồn: id, code, request_id, request_hash, access_hash, snapshot, contact, booking_status, payment_status, instructions, version, created_at. Giữ ID/mã JM, thông tin khách/invoice/phương thức thanh toán và các phần JSON mở rộng, giá/điều kiện/tên tại lúc đặt, hash yêu cầu/truy cập và thời gian UTC sáu chữ số. Không gọi Orders.create, không tăng version hoặc cấp lại khóa truy cập.

Đối chiếu schema staging theo TRANSFER-EXPORT-SCHEMA.json và tên cột kho chờ/đơn vận hành. Kiểm InnoDB/base table, sức chứa/NULL/UTF-8, thời gian DATETIME(6), khóa chính ID và unique code/request_id. Bảng staging có cột lạ/thiếu bị chặn. Không đọc/thực thi nội dung file SQL; staging phải được chuẩn bị từ export đã kiểm trước đó.

Kiểm UUID, mã JM/JG + 10 ký tự hex, hash SHA-256 dạng hex, phiên bản nguyên, trạng thái nguồn và độ dài instructions. Chỉ nhận REQUESTED/CONFIRMED/CANCELLED và UNPAID/PAID/CREDIT; trạng thái PAID/CREDIT phải là CONFIRMED. Đơn CREDIT được giữ như dữ liệu gốc, không suy ra ledger đã có hoặc được phép giao vé.

Snapshot/contact phải là JSON object. Kiểm tên khách/email/phone là chuỗi; productId/variantId/date/locale, tên sản phẩm/gói, demo/policy và các dòng khách. Nhận snapshot legacy thiếu variantId mà không tự gán gói mới. Không tra danh mục hôm nay để thay tên/gói/giá lịch sử. Các phần JSON khác được giữ, chưa được chứng nhận toàn bộ quan hệ/ý nghĩa bởi bước này.

Kiểm tiền bằng số nguyên an toàn, không chuyển float hoặc làm tròn BIGINT: mỗi dòng có nhóm khách hợp lệ/không trùng, số khách 1–50, tổng tối đa 150, unitPrice × quantity = subtotal; kiểm tràn tích/tổng. Đơn miễn phí được giữ. Kiểm originalUnitPrice/unitDiscount và onlineDiscount khớp tổng dòng gốc/giảm; kiểm voucher subtotal/amount và total = tổng dòng – voucher. Không cộng dồn giảm online dương với voucher. Đây là đối chiếu phép tính **đã lưu**, không tính lại theo cấu hình bán hiện tại.

Chặn trùng ID (kể cả khác hoa/thường), code hoặc request_id trong nguồn; chặn collision với ID khác trong kho chờ hoặc đơn đang chạy. Nếu cùng ID có metadata khác thì chặn, không ghi đè. Đơn vận hành đã khớp chỉ được đếm matchingLiveRows; vẫn không sửa hoặc khóa trạng thái nghiệp vụ của nó.

## Lệnh chuẩn bị khi đủ điều kiện

Giữ backup và file export riêng ngoài webroot; nguồn staging cố định, khác DB PHP cùng MySQL. Tới cửa sổ chuyển đổi sau này cần dừng request/worker ghi và kiểm các module tổng thể trước khi áp dụng. Không chép SQL từ kho chờ sang jingo_orders bằng tay để vượt qua phần chưa làm.

```bash
php bin/import-order-cores.php --source-db=TAIKHOAN_staging
```

Mặc định read-only dry-run. Báo `scope=order-core-holding-only`, số dòng mới/khớp và fingerprint; `ready=true` chỉ xác nhận lõi trong phạm vi này, **operationalReady luôn false**. Không in tên/email/điện thoại, mã/ID đơn, snapshot/contact, hash truy cập hoặc ghi chú.

Sau khi xem kết quả, chỉ khi tới đợt chuyển dữ liệu:

```bash
php bin/import-order-cores.php --source-db=TAIKHOAN_staging --apply --fingerprint=HASH_DRY_RUN_LOI_DON
```

Apply cần hash riêng theo scope và nội dung lõi nguồn (JSON so sánh theo nội dung, thời gian chuẩn hóa UTC microsecond). Khóa catalog, nguồn chia sẻ và các hàng kho chờ/đơn hiện có, kiểm lại collision/metadata rồi thêm kho chờ + audit orders.hold-import trong một transaction. Lỗi rollback toàn bộ; replay khớp không thêm đơn/audit. Lệnh không cập nhật, xóa hoặc mở khóa đơn.

Giới hạn 50.000 dòng vật lý mỗi bảng/mỗi bên, tổng metadata đọc 32 MiB; snapshot 1 MiB, contact 64 KiB, instructions tối đa sức chứa TEXT. Query đọc không buffer. Tối đa 100 chi tiết lỗi nhưng đếm đầy đủ lỗi đã phát hiện. Exit 0 lõi đạt, 2 dữ liệu cần xem lại, 1 tham số/hạ tầng/giới hạn không hoàn tất. File ảnh/PDF/vé, phiên đăng nhập và khóa gửi email không nằm trong bước này.

## Đã kiểm và phần còn lại

```bash
php tests/order-core-transfer.php
php tests/orders.php
php tests/voucher-use-transfer.php
```

Fixture lõi dùng SQL sinh từ helper exporter Node thật của đợt 136; test thêm Unicode/emoji và các snapshot legacy/online/miễn phí/CREDIT. Kiểm nguyên vẹn 13 trường, tiền/date/hash/NULL/shape, collision nguồn/kho/live, fingerprint, schema/capacity/unique, rollback/audit/replay/CLI và hai worker. Kiểm tra cứu khách trả 404 cho đơn chỉ trong kho chờ, các bảng đơn/thu/tồn/vé/mail/thông báo vận hành vẫn rỗng. Migration replay giữ nguyên kho chờ. Regression đơn và importer lượt voucher chạy với schema mới.

Các test dùng MySQL thử, tự tạo/xóa DB ngẫu nhiên; chưa dùng dump thật/cPanel. Còn chuyển và đối soát toàn bộ quan hệ, kiểm file vật lý, rồi cơ chế xuất bản đơn nguyên khối và mở khóa. Kho chờ mới không tự đáp ứng các phần đó.
