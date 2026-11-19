# Phối hợp Node export và PHP import

Đợt 155: [lịch sử đơn gốc](IMPORT-ORDER-HISTORY.md) đọc trực tiếp full-v2 với đơn native đã khớp; schema 068 giữ note/actor/time/hash gốc, không cần Node bổ sung order_version giả. Bộ [nhập tồn vé](IMPORT-INVENTORY.md) cũng cần cha native khớp. Hai bộ này không tự xuất bản đơn vé từ kho chờ và chưa mở lệnh apply toàn hệ thống.

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

Sổ hoàn được nhập sau các cha và khoản thu bằng [IMPORT-REFUNDS.md](IMPORT-REFUNDS.md). Bộ nhập đối chiếu ID/mã, từng khoản thu, tổng thực thu và liên kết cha; không gọi lại chuyển tiền hoặc bù dữ liệu cha bị thiếu.

Đợt 144 có luồng quà + mã tổng chỉ gồm quà: [IMPORT-GIFT-BUNDLES.md](IMPORT-GIFT-BUNDLES.md). Dùng installer để nâng schema, nhập graph có cờ chờ, đối soát sổ hoàn, rồi quản trị chủ động cấp liên kết thay thế. Liên kết nguồn cũ không được tái tạo từ SQL thiếu khóa. Mã ghép thêm vé/xe còn chặn để chờ adapter tương ứng.

Đợt 145 có adapter kế hoạch + xe riêng chưa thu: [IMPORT-PRIVATE-RIDES.md](IMPORT-PRIVATE-RIDES.md). Dùng schema installer trước; không tính lại giá đơn cũ hoặc bỏ qua các dòng chưa có adapter. Mã ghép và tài chính xe vẫn cần làm tiếp trước lần import thật.

Đợt 147: PHP nhận thêm đơn WEB/private đã thu đủ SePay bằng --with-paid-qr; Node cần xuất nguyên payment_references + gateway_events + ride_payment_events và khai báo gateway_resolutions. Không cần dựng QR attempts bị loại khỏi export; chỉ nhận sự kiện xe PAID có attempt_id/order_id NULL đúng luồng Node. REVIEW/đối soát thủ công, hoàn/tiền mặt và các nhóm chưa hỗ trợ sẽ chặn cả batch. Xem IMPORT-PAID-RIDES.md; chưa chạy import thật.

Đợt 148: khi nguồn xe SePay có refunds, PHP có thể nhập nguyên tử bằng --with-paid-qr --with-refunds. Node cần giữ nguyên refunds gồm root ID/mã/kind, amount/ngày, reference_key, JSON actor/fingerprint và created_at; bảng không xuất không được coi là rỗng. Không xuất riêng bản đã bỏ lịch sử hoàn để vượt kiểm tra. Xem IMPORT-REFUNDED-RIDES.md; chưa chạy import thật.

Đợt 150: PHP nhận thêm terminal unpaid ride_pools + ride_bookings web/manual bằng --with-closed-pools. Cần xuất đầy đủ mọi thành viên kể cả đã hủy, pool total/target/status/version/time, requestHash và thứ tự created_at/ID; không bỏ các dòng lịch sử để vượt kiểm tra. Nhóm active/paid và tuyến con chưa chứng minh được vẫn chặn. Xem IMPORT-CLOSED-RIDE-POOLS.md; chưa chạy import thật.

Đợt 151: nhận graph nhóm đang hoạt động chưa thu qua --with-active-pools. Node cần giữ toàn bộ thành viên kể cả đã hủy, status, target, total, dispatch, version và created_at/ID để PHP kiểm chỗ/giá; không xuất riêng nhóm mà thiếu thành viên. Chốt quyền ghi nguồn trước lần apply thật; khi PHP đã có join/cancel/dispatch mới thì file cũ bị chặn. Xem IMPORT-ACTIVE-RIDE-POOLS.md; chưa chạy import thật.

Đợt gộp 153: xem CONVERSION-STATUS.md để theo dõi toàn bộ phần còn thiếu. --with-paid-pools --with-refunds nhận graph xe riêng/shared SePay và hoàn đồng thời; import-contact-requests.php giữ lịch sử liên hệ NEW/DONE/TRASH. check-transfer-coverage.php kiểm file/manifest/relations và báo mọi bảng/phạm vi còn thiếu, không kết nối DB hoặc apply. Admin transfer hiển thị capability map theo số dòng kho chờ. Các nhóm native orders/inventory/POS/stays/mixed bundles vẫn chưa hoàn tất; không bỏ bảng/receipt/proof để vượt kiểm tra hoặc dùng kho chờ thay cho runtime.
