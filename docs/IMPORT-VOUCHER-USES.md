# Import lịch sử lượt dùng voucher — đợt 136

Chạy **database/058-order-time-precision.sql** sau `git pull`. Migration nâng `jingo_orders.created_at` lên DATETIME(6) UTC, giữ nguyên dữ liệu hiện có và cho phép lưu microsecond của nguồn. Không thể khôi phục các chữ số từng bị mất do nhập bằng công cụ khác; importer chặn nếu timestamp đơn đích khác nguồn.

`bin/import-voucher-uses.php` nhập riêng `voucher_uses(order_id,voucher_id,released)`. Giữ cả lượt đang dùng và lượt đã trả; không gọi allocate/release, không tăng version hoặc thay đổi snapshot của đơn. Không nhập đơn, voucher metadata, tồn, thanh toán/công nợ, không xóa transfer_pending và không gửi email.

## Điều kiện trước khi chạy thật

Theo yêu cầu nhập một lần sau khi chuyển đủ module, **chưa chạy import thật ở giai đoạn này**. Importer đơn tổng thể, tồn/ledger và bước đối soát/mở khóa vẫn còn thiếu. Bộ này sẽ dùng sau khi các đơn cha đã được chuyển và kiểm đầy đủ; không dùng SQL chép đơn trực tiếp để vượt qua điều kiện.

Tại cửa sổ chuyển đổi sau này: có backup đã kiểm, dừng request/worker ghi, nguồn export/staging cố định, DB PHP schema 058; chuẩn bị metadata theo [import voucher](IMPORT-VOUCHERS.md), rồi hoàn tất chuyển đơn bằng công cụ tương ứng khi có. Giữ staging khác database PHP, cùng máy MySQL và tài khoản có quyền đọc staging/ghi runtime.

Nguồn phải có đúng tên cột của năm bảng `jm_transfer_orders`, `jm_transfer_vouchers`, `jm_transfer_voucher_uses`, `jm_transfer_partner_orders`, `jm_transfer_pos_orders` theo TRANSFER-EXPORT-SCHEMA.json. Hai bảng kênh bán có thể rỗng nhưng không được thiếu, vì cần xác định đây là đơn web bán lẻ. Các bảng phải là InnoDB, không phải view. Runtime cần đủ bảng cùng cột, riêng vouchers có cờ transfer_pending của schema 057; uses cần khóa chính order_id, cột tham chiếu đủ UUID và released TINYINT không null. Timestamp đơn đích phải giữ sáu chữ số.

## Những gì được đối chiếu

- Metadata tất cả voucher nguồn phải có ở đích, giữ ID/code/JSON/version. Validator voucher vẫn kiểm cấu trúc, nhưng không tính lại lịch sử theo enabled/giá/khung ngày/phạm vi hiện tại.
- Mỗi lượt phải có ID UUID và released đúng 0/1, tham chiếu chính xác đơn/voucher nguồn. Không có hai lượt cùng order_id. Chặn đơn thuộc CTV/POS trong nguồn hoặc đích.
- Snapshot đơn có discount.code đúng mã voucher, amount/subtotal/total là số nguyên không âm trong phạm vi số an toàn, subtotal lớn hơn 0, amount không vượt subtotal và total=subtotal-amount. Không tính lại discount từ giá hôm nay.
- Lượt released phải thuộc đơn CANCELLED/UNPAID; lượt còn hiệu lực không thuộc đơn CANCELLED. Luồng voucher lịch sử chỉ nhận trạng thái thanh toán UNPAID/PAID, không chuyển đơn công nợ CTV vào voucher web.
- Đơn cha đích phải khớp **toàn bộ cột nguồn**: ID/mã/request_id/request_hash/access_hash, snapshot/contact/instructions, trạng thái, version và created_at UTC microsecond. JSON được so sánh theo nội dung, không theo thứ tự key. Chưa có đơn hoặc khác bất kỳ trường nào thì chặn toàn bộ, không tự tạo/sửa đơn.
- Kiểm chiều ngược: đơn nguồn có discount phải có lượt; lịch sử/đơn giảm giá đích thuộc voucher đang chuyển không được có thêm quan hệ không xuất hiện trong nguồn. Không tự gộp giao dịch PHP với ảnh chụp dữ liệu cũ.
- Đếm lượt chưa released, chặn nếu vượt maxUses. Lượt cũ đã trả không chiếm hạn mức.

Thêm **lượt mới** chỉ được thực hiện khi voucher đích đang transfer_pending. Với hàng đã có, chỉ chấp nhận khớp hoàn toàn voucher_id/released, không ghi đè hay “sửa” lượt. Replay nguyên vẹn trên voucher PHP đang hoạt động không khóa lại hoặc thay đổi lượt của nó.

## Dry-run và apply

```bash
php bin/import-voucher-uses.php --source-db=TAIKHOAN_staging
```

Mặc định transaction read-only. Báo cáo `scope=voucher-use-history-only`, số lượt nguồn/mới/khớp/active/released, mã lỗi và fingerprint. Không in ID khách/đơn/voucher, mã voucher, email/contact, snapshot, bí mật truy cập, hash yêu cầu hoặc ghi chú. Chi tiết lỗi chỉ có bảng, thứ tự dòng đọc và mã; không phải số dòng trong file SQL.

`ready=true` chỉ nói lịch sử lượt và các điều kiện cha trong phạm vi này khớp; không chứng nhận tồn/thu tiền/ledger hoặc cho phép mở voucher. `notImported` và `voucherAccessPolicy=PRESERVE_PENDING_NO_ACTIVATION` nhắc rõ phần chưa thực hiện.

Khi các module tổng thể đã sẵn sàng và kết quả được xem tại cửa sổ chuyển dữ liệu:

```bash
php bin/import-voucher-uses.php --source-db=TAIKHOAN_staging --apply --fingerprint=HASH_DRY_RUN_LICH_SU_LUOT
```

Hash riêng theo scope, nội dung năm bảng nguồn và metadata cha đích liên quan. Không nhận hash từ importer metadata voucher. Apply giữ khóa catalog, khóa chia sẻ staging và khóa hàng runtime theo thứ tự đơn trước voucher; kiểm lại mọi điều kiện rồi thêm toàn nhóm và audit voucher-uses.import trong một transaction. Lỗi rollback toàn bộ. Trong môi trường còn ghi đồng thời có thể gặp lock/deadlock: không chạy chuyển thật trước khi dừng ghi; nếu transaction bị hủy thì kiểm lại/dry-run, không bỏ khóa để ép nhập.

Lượt runtime không nằm trong fingerprint để replay nguyên vẹn dùng lại được hash cũ, nhưng mỗi lần đều được khóa và đối chiếu; hàng khác, hàng dư hoặc thêm vào voucher chưa pending vẫn bị chặn. Cờ runtime cũng không nằm trong hash và **không bao giờ bị lệnh này sửa**. Replay không thêm audit khi không có lượt mới.

Giới hạn 50.000 dòng vật lý/bảng/mỗi bên, tổng metadata đọc 32 MiB cho cả nguồn/đích; đọc query không buffer. Tối đa 100 chi tiết lỗi nhưng đếm đủ số lỗi đã phát hiện; vượt giới hạn/hạ tầng rollback và exit 1. Exit 0 phạm vi đạt; 2 dữ liệu cần xem lại; 1 tham số/hạ tầng/giới hạn không hoàn tất. Không thực thi nội dung file SQL hoặc chép file vật lý.

## Đã kiểm

```bash
node tests/build-voucher-use-transfer-fixture.mjs /path/to/node-repository
php tests/voucher-use-transfer.php
php tests/voucher-transfer.php
php tests/vouchers.php
php tests/orders.php
php tests/partner-orders.php
php tests/carts.php
```

Fixture cha được tạo bằng helper exporter Node thật đã kiểm SHA-256; lượt nguồn dùng fixture exporter voucher đợt 135. Bao gồm đơn web xác nhận và đơn hủy đã trả lượt, giá lịch sử khác giá hiện tại, timestamp .123456, contact/hash nguyên bản. Test tự chép đơn cha vào DB thử để **mô phỏng điều kiện trước bước này**, không phải importer đơn sản xuất.

Kiểm thiếu cha, source/reverse link, trùng/ID/cờ sai, CTV/POS, tổng/trạng thái/mã voucher, metadata/version/microsecond khác, schema và PK, quota, source đổi fingerprint, không tính lại giá lịch sử, runtime dư/khác, pending, rollback/audit/replay, CLI mặc định dry-run và hai worker không trùng. Migration replay giữ đơn/cờ; không tạo tồn/ledger, không mở voucher. Regression đặt/hủy đơn/voucher, đơn CTV và giỏ chạy với schema mới. Chưa dùng dữ liệu thật/cPanel và chưa chứng nhận importer toàn hệ thống.
