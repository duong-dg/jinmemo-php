# Import cấu hình nghiệp vụ vào PHP

Đợt 141 thêm `bin/import-business-settings.php`, đọc trực tiếp export **full-v2** và nhập vào **bảng vận hành `jingo_settings`**. Đây là adapter cho hai cấu hình, khác với `import-transfer.php` lưu toàn bộ dữ liệu vào kho chờ. Không cần import SQL nguồn vào database staging hay chạy một migration mới.

Phạm vi hiện có:

- `manual-payments`: mã phương thức thủ công, bật/tắt, tên và hướng dẫn Việt/Anh. Không bật hoặc nhập khóa cổng VNPAY/SePay/PayPal.
- `gifts`: danh mục quà, giá, tồn hiện tại, phí giao hàng, nội dung Việt/Anh và ảnh tham chiếu. Giữ version và JSON nguồn, không gọi thao tác save tăng phiên bản/trừ tồn.

Các cấu hình khác (`transport-plan`, `features`, `site-seo`) chưa được adapter này xuất bản. `operationalReady=false` chỉ tình trạng toàn bộ chuyển dữ liệu, không phủ nhận việc hai cấu hình đã ghi vào bảng runtime sau apply.

## Cách chạy khi đến đợt import thật

Backup DB và giữ nguyên file export ngoài webroot. Chạy dry-run:

```bash
php bin/import-business-settings.php --file=/home/USER/private/export.sql
```

Chọn riêng một phần nếu cần:

```bash
php bin/import-business-settings.php --file=/home/USER/private/export.sql --scope=manual-payments
php bin/import-business-settings.php --file=/home/USER/private/export.sql --scope=gifts
```

Mặc định scope `all`: cả hai cấu hình phải có và hợp lệ; lỗi một phần chặn cả lần nhập. Sau khi duyệt dry-run, chạy lại cùng file/scope với `--apply --fingerprint=GIÁ_TRỊ_TỪ_DRY_RUN`. Apply ghi ngay vào cấu hình đang dùng, vì vậy chỉ chạy trong đợt chuyển đã chốt, khi đã dừng ghi hệ thống nguồn.

## Điều kiện và hành vi

- Chỉ nhận full-v2 khớp manifest/cột/số dòng/checksum và quan hệ đã khai báo. Không thực thi SQL nguồn. Kiểm lại checksum trước commit.
- Dùng chung validator của form lưu cấu hình; từ chối dữ liệu cần sửa, cắt, ép kiểu hoặc thêm giá trị mặc định mới. Không tự chuẩn hóa làm mất nội dung nguồn.
- Giữ version nguồn 1..2147483646, không ghi đè dữ liệu runtime khác. Cấu hình trùng dữ liệu/version được nhận diện. Dry-run mới sau lần apply cho fingerprint mới; chạy lại không tạo audit/setting trùng.
- Khóa catalog và giao dịch InnoDB bảo vệ apply. Fingerprint gồm file, scope, dữ liệu nguồn và trạng thái cấu hình đích. Đích thay đổi thì phải dry-run lại. Lỗi ghi/audit rollback toàn bộ.
- Quà yêu cầu manifest xác nhận đã xuất bảng gift_orders và bảng đó không có đơn. Có đơn quà lịch sử thì mặc định báo `GIFT_ORDER_IMPORT_REQUIRED_BEFORE_STOCK`. Đợt 142 thêm cờ `--with-gift-orders` nhập đơn độc lập cùng tồn trong một giao dịch; đơn phụ thuộc mã tổng/thanh toán vẫn chặn. Xem [IMPORT-GIFT-ORDERS.md](IMPORT-GIFT-ORDERS.md). Nếu runtime có đơn quà, không nhập mới tồn đè lên hoạt động đang có.
- Ảnh quà phải đã có trong thư viện PHP và chưa bị xóa. Copy/import ảnh gốc trước. Nếu runtime có cờ `features.giftsEnabled`, cờ đó phải khớp `gifts.enabled`; công cụ không tự sửa cờ phân hệ.
- Không nhập tài khoản, phát mail, gọi provider, chạy lại thao tác tạo đơn, tính lại doanh thu/tồn hoặc thay đổi ảnh/PDF. Đợt này chỉ kiểm local tổng hợp, chưa nhập dữ liệu thật.

Trình tự tổng thể và hợp đồng hai bên: [EXPORT-IMPORT-COORDINATION.md](EXPORT-IMPORT-COORDINATION.md).
