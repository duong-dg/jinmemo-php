# Đối soát công nợ CTV trong file export — đợt 134

`bin/check-partner-credit-transfer.php` kiểm phần công nợ **nguồn** trước khi viết/áp dụng importer đơn và ledger. Chỉ đọc file Node MySQL transfer v1 theo schema đóng băng tại commit `7e3578d2c27f166ba42a48ce4ee5b21e7ff33cbb`. Không kết nối database, thực thi SQL, sửa file, nhập tiền hoặc mở khóa CTV. Không cần migration mới; schema PHP vẫn 056.

```bash
php bin/check-partner-credit-transfer.php --file=/private/path/export.sql --as-of=2026-11-18T00:00:00.000000Z
```

Chọn mốc UTC rõ ràng để chạy lại cùng file có kết quả quá hạn ổn định; định dạng bắt buộc đủ sáu chữ số microsecond và `Z`. Giữ file export trong thư mục riêng ngoài webroot. Chưa chạy import thật theo yêu cầu nhập một lần sau khi chuyển đủ module.

## Phạm vi kiểm

Trước hết chạy bộ đọc file hiện có: preamble/footer, SQL whitelist, đầy đủ 61 bảng/365 cột, kiểu/NULL/ngày giờ, số dòng và SHA-256. File thiếu bảng, thay schema, có SQL lạ hoặc footer hỏng không được kết luận tài chính (`SKIPPED`).

Trong năm bảng `partners`, `orders`, `partner_orders`, `partner_ledger`, `payment_references`:

- Chặn trùng khóa hồ sơ/đơn/chủ đơn/bút toán/mã giao dịch; kiểm các tham chiếu CTV và đơn trong phạm vi này.
- Hạn mức phải là số nguyên JSON từ 0 đến 1.000.000.000.000. Hạn mức không được coi là số dư, không yêu cầu CTV hiện tại còn ACTIVE/creditEnabled để công nhận khoản nợ lịch sử.
- CHARGE phải không âm, có đơn và hạn trả; đúng CTV sở hữu, đúng mã đơn và `snapshot.total` nguyên, đơn CONFIRMED/CREDIT. Không có hai CHARGE cho cùng đơn; mọi đơn CREDIT phải có chủ CTV và bút toán tương ứng. Khoản ghi nợ 0 đồng được giữ theo hành vi nguồn.
- REPAYMENT phải âm, không gắn đơn/hạn trả, có mã giao dịch PARTNER_REPAYMENT đúng CTV và số tiền dương đối ứng; mỗi mã dùng một lần. Kiểm chiều ngược để bắt mã trả nợ không có bút toán.
- Chặn loại bút toán chưa hỗ trợ, số tiền hoặc tổng ghi nợ/tổng trả theo từng CTV vượt `Number.MAX_SAFE_INTEGER`, số dư âm. Không làm tròn hoặc chuyển qua float để ép nhập BIGINT lớn.
- Sắp xếp `created_at,id`, áp toàn bộ khoản trả theo FIFO như `partners/credit.ts`. ID bút toán phải là UUID chữ thường dạng chuẩn do ứng dụng sinh; ID lạ được báo để xem xét thay vì giả định collation text của PostgreSQL. Mốc quá hạn dùng `<` với độ chính xác millisecond của Node Date; giữ nguyên timestamp sáu chữ số trong dữ liệu/digest.

`partnerCredit.status` là PASSED/FAILED/INCOMPLETE/SKIPPED. Exit 0 chỉ khi đủ profile và kiểm công nợ đạt; exit 2 cần xem lại dữ liệu/giới hạn; exit 1 tham số/file/hạ tầng không hợp lệ. `importReady` **luôn false**, kể cả PASSED. Các mục `unchecked` của báo cáo file và module vẫn còn nguyên.

## Báo cáo và giới hạn

Báo số dòng, số CTV/CHARGE/REPAYMENT, số CTV còn nợ/quá hạn; lỗi chỉ chứa mã, bảng, thứ tự dòng trong bảng. Không in tên/email/điện thoại/ID/mã đơn/mã giao dịch/note/hash yêu cầu hoặc số dư từng CTV. Chỉ mục dùng hash tham chiếu; giữ ID bút toán nội bộ để sắp xếp, không đưa ra báo cáo.

Khi PASSED có `sourceSummarySha256`: SHA-256 của danh sách sắp theo hash ID CTV, mỗi mục gồm hash ID, số dư, quá hạn và các khoản ghi nợ FIFO dương (hash đơn, amount, outstanding, due_at). Tính cả CTV chưa có bút toán; bỏ khoản 0 khỏi danh sách FIFO giống Node. Digest dùng đối chiếu kết quả tính nguồn trong kiểm thử, chưa phải xác nhận database PHP hoặc token cho lệnh `--apply`. Khi lỗi/thiếu/giới hạn, không cấp digest.

Bộ đọc chung giới hạn file 512 MiB/dòng 32 MiB. Module giới hạn tổng 100.000 dòng và 32 MiB giá trị SQL ở năm bảng, tối đa 100 chi tiết lỗi nhưng vẫn đếm đầy đủ lỗi đã phát hiện. Vượt giới hạn báo INCOMPLETE, không bỏ qua rồi báo đạt.

## Đã kiểm và phần còn lại

Fixture đủ 61 bảng được tạo từ helper exporter Node thật; schema/exporter/credit.ts kiểm SHA-256 với baseline. Digest FIFO được tạo bằng chính `creditSummary` nguồn, ngày hiện tại được cố định. Có ca hai khoản ghi nợ cùng timestamp nhưng thứ tự ID khác, trả một phần, hạn cũ/tương lai, khoản 0 và CTV chưa có sổ.

```bash
node tests/build-partner-credit-transfer-fixture.mjs /path/to/node-repository
php tests/partner-credit-transfer.php
php tests/transfer-audit.php
php tests/transfer-relations.php
```

Kiểm mutation bao gồm sai chủ/tổng/trạng thái/mã đơn, mất/trùng khoản ghi nợ, mã trả nợ thiếu/sai/trùng/không có ledger, hạn mức/JSON/số tiền lớn, số dư âm, tổng vượt phạm vi, dữ liệu đảo thứ tự, biên quá hạn, file/schema không hợp lệ, giới hạn/lỗi bị chặn, bảo mật báo cáo và CLI. Không cần MySQL, không dùng dump thật/cPanel.

Chưa kiểm đầy đủ order_actions/request_hash, các khoản thanh toán/hoàn khác, tồn vé/file vật lý, collation/adapter và dữ liệu đích. Cần tiếp tục importer đơn/tồn/ledger, đối soát nguồn–đích, quy trình cấp mật khẩu/mở khóa sau chuyển hệ thống. Bộ kiểm này không tự tạo nợ đầu kỳ, không đổi `transfer_pending` hoặc gửi thông báo.
