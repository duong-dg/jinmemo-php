# Chỗ nhập dữ liệu từ Node

Trên hosting hiện tại, import chạy bằng **cPanel → Terminal**, trong thư mục code PHP. Admin chưa có chức năng tải SQL rồi tự nhập toàn bộ. Sau cập nhật Git, vào **Admin → Cấu hình website → Dữ liệu chuyển đổi**, hoặc `/admin#transfer`, để xem hướng dẫn và sao chép lệnh.

Trang này có hai phần: hướng dẫn chuẩn bị/kiểm tra ở trên và dữ liệu kho chờ ở dưới. Kho chờ không phải dữ liệu bán hàng đang vận hành. Khi chưa đưa export vào kho chờ, danh sách trống là bình thường; chạy kiểm tra hoặc dry-run không làm xuất hiện dòng mới.

1. Xuất MySQL full-v2 và ZIP ảnh/file vé từ Node. Giữ nguyên PDF để sao chép/đính kèm, không cần xử lý nội dung.
2. Dùng File Manager của cPanel đặt SQL vào thư mục riêng ngoài webroot, ví dụ `/home/USER/private/export.sql`. Đổi USER/tên file theo hosting. Không nhập SQL nguồn trực tiếp bằng phpMyAdmin.
3. Trong Terminal, cập nhật ở đúng thư mục code:

```bash
cd /home/USER/jinmemo.cloud
git pull
php bin/install.php
```

4. Kiểm tra file, chưa cần database và chưa ghi SQL:

```bash
php bin/check-transfer.php --file=/home/USER/private/export.sql --relations
```

5. Có thể xem trước khả năng đưa vào kho chờ, vẫn chưa ghi dữ liệu:

```bash
php bin/import-transfer.php --file=/home/USER/private/export.sql
```

Đang chuyển tiếp các phần còn thiếu nên chưa thực hiện lần import production. Chưa có một lệnh nhập toàn bộ hệ thống và sẵn sàng bán hàng. Các adapter có phạm vi riêng, dry-run/fingerprint riêng; xem EXPORT-IMPORT-COORDINATION.md và PORT-COMPLETION.md. Chạy import-transfer chỉ đưa vào kho chờ khi được apply, không thay cho các adapter dữ liệu vận hành.

Đợt gộp 153 bổ sung lệnh rà tổng thể: php bin/check-transfer-coverage.php --file=/home/USER/private/export.sql . Lệnh chỉ kiểm, không ghi và trả exit 2 khi chưa đủ điều kiện xuất bản toàn hệ thống. Xem CONVERSION-STATUS.md; admin transfer cũng hiển thị các bảng có adapter giới hạn/chỉ kho chờ/cần reset, không đồng nghĩa đã nhập native.
