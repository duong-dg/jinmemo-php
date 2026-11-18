# Phối hợp Node export và PHP import

## Hợp đồng đã ghim

PHP tự nhận v1 hoặc **full-v2** qua header. V1 giữ hợp đồng cũ; full-v2 ghim exporter ở Node commit `9fb617071c212df641ecfbaced08961b8f7eed72`, 68 bảng nguồn và bảng manifest (69 nhóm, 404 cột). Xem TRANSFER-V2-SCHEMA.json và TRANSFER-V2-RELATIONS.json.

Node chịu trách nhiệm xuất snapshot nhất quán và phân loại bảng. PHP chịu trách nhiệm kiểm và ánh xạ dữ liệu. Không tự đổi tên cột hoặc sửa SQL bằng tay để vượt lỗi. Manifest ghi bảng đã xuất, bảng thiếu ở nguồn, bảng chủ động loại, danh sách cột, số dòng và SHA-256 các dòng INSERT từng bảng. PHP kiểm lại các thông tin này; manifest không phải chữ ký xác thực nguồn.

Full-v2 thêm settings nghiệp vụ (`gifts`, `transport-plan`, `features`, `site-seo`, `manual-payments`), danh tính admin/POS và lịch sử audit/mail/thông báo. Mật khẩu, khóa SMTP/provider, session và các trường credential vẫn bị loại, kể cả khóa trong JSON. Mail cũ chỉ lưu kho chờ, không phát lại. PHP không tạo giả access_hash để khôi phục liên kết cũ.

## Khi hai bên cùng phát triển

1. Bên Node báo phiên bản, commit và thay đổi bảng/cột/kiểu/điều kiện lọc. Gửi mẫu tổng hợp không chứa dữ liệu khách hoặc bí mật, cùng tài liệu định dạng.
2. Bên PHP cập nhật profile và fixture từ chính helper export, chạy kiểm manifest, quan hệ, đối soát, nhập thử và hồi quy v1. Không đổi hợp đồng v1 khi thêm v2. Đổi định dạng phá tương thích cần phiên bản mới và parser tương ứng.
3. Chỉ dùng file xuất thật sau khi hai bên đã xác nhận cùng hợp đồng. Nếu checker báo schema khác, dừng và cập nhật mapper; không bỏ kiểm tra checksum.

Tái tạo hợp đồng/fixture bằng Node repo local (chỉ đọc nguồn):

```bash
node tests/build-transfer-v2-profile.mjs /path/to/node-repository --check
node tests/build-full-v2-fixture.mjs /path/to/node-repository --check
```

## Lúc chuyển dữ liệu thật — làm một lần sau khi hoàn thiện

1. Backup database và uploads của cả hai hệ thống. Chốt thời điểm dừng ghi ở Node; không để hai bên đồng thời bán/trừ tồn. Xuất **MySQL đầy đủ v2** (`POST /api/v1/admin/backups/mysql-export/full`). Tải SQL nguyên bản, giữ checksum toàn file. Không mở rồi lưu lại bằng trình soạn thảo vì đổi xuống dòng cũng có thể làm sai checksum INSERT.
2. Nén riêng thư mục ảnh và file vé gốc, copy sang hosting và giải nén vào thư mục riêng ngoài webroot. Giữ UUID/tên file/cấu trúc. PDF chỉ đính kèm và gửi nguyên file, không xử lý lại nội dung.
3. Cập nhật PHP rồi cài schema. Không cần tự chọn từng file SQL trong phpMyAdmin:

```bash
cd ~/jinmemo.cloud
git pull origin main
php bin/install.php
```

Lệnh install dùng cấu hình DB PHP hiện tại; backup trước khi chạy. Đợt hỗ trợ v2 này không thêm migration mới ngoài schema 061.

4. Đặt SQL ngoài webroot. Chạy kiểm đọc trước, thay đường dẫn theo nơi đã copy:

```bash
php bin/check-transfer.php --file=/home/USER/private/export.sql --relations
php bin/check-transfer-files.php --file=/home/USER/private/export.sql --media-dir=/home/USER/private/media --ticket-dir=/home/USER/private/tickets
php bin/import-transfer.php --file=/home/USER/private/export.sql --media-dir=/home/USER/private/media --ticket-dir=/home/USER/private/tickets
```

Lệnh cuối mặc định dry-run. Không chạy trực tiếp SQL export vào database PHP. `ready=true` ở đây chỉ có nghĩa đủ điều kiện lưu kho chờ. File có MISSING_SOURCE được nhận diện nhưng vẫn chặn khả năng vận hành; phải giải quyết nguyên nhân thiếu trước khi chuyển thật.

5. Khi đã duyệt báo cáo và đúng kế hoạch, dùng lại chính file/thư mục và thêm `--apply --fingerprint=GIÁ_TRỊ_TỪ_DRY_RUN`. Đây là **lưu kho chờ**, chưa xuất bản vào bảng vận hành. Không trộn export v1 và v2 trong cùng kho đã có dữ liệu; công cụ chặn xung đột, không tự ghi đè/xóa kho cũ.
6. Xem đối chiếu tại **Cấu hình website → Dữ liệu chuyển đổi**. Hoàn thiện ánh xạ/xuất bản, kế hoạch cấp lại liên kết/đăng nhập và cấu hình mail/provider mới, rồi kiểm staging/cPanel trước khi mở bán.

**Hiện chưa hoàn tất bộ xuất bản toàn bộ dữ liệu vào schema vận hành. `operationalReady=false` vẫn được giữ.** Các bước thực tế ở trên là quy trình chuẩn bị và kiểm; không tự động thực hiện import thật trong quá trình phát triển. Xem FULL-TRANSFER.md về giới hạn và những phần còn thiếu.

Adapter runtime bổ sung: [IMPORT-BUSINESS-SETTINGS.md](IMPORT-BUSINESS-SETTINGS.md) nhập hai cấu hình `manual-payments` và `gifts` trực tiếp từ full-v2 vào jingo_settings. Đây là phạm vi riêng; chưa thay thế bước hoàn thiện toàn bộ dữ liệu vận hành.

Đợt 142 có thêm nhập đơn quà độc lập cùng tồn qua `--with-gift-orders`: xem [IMPORT-GIFT-ORDERS.md](IMPORT-GIFT-ORDERS.md). Đơn phụ thuộc mã tổng/thanh toán/hoàn tiền vẫn phải chờ adapter tương ứng, không chia nhỏ scope để bỏ liên kết.
