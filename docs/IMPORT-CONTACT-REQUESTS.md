# Nhập lịch sử yêu cầu liên hệ

Đợt gộp 153 thêm `bin/import-contact-requests.php`, đọc full-v2 và nhập native `jingo_contact_requests`. Giữ ID, JSON Việt/Anh, trạng thái NEW/DONE/TRASH và created_at UTC microseconds. Dùng cùng validator với form PHP để không nhập dữ liệu mà màn quản trị không đọc/xử lý được; không gọi form submit, mail hoặc quota khi import.

Sau backup/cập nhật code, chạy `php bin/install.php` để có schema 066. Dry-run:

```bash
php bin/import-contact-requests.php --file=/home/USER/private/export.sql
```

Đến lần chuyển thật mới dùng cùng file với `--apply --fingerprint=GIÁ_TRỊ_DRY_RUN`. Hiện chưa import production. SQL giữ ngoài webroot; không nhập trực tiếp SQL nguồn vào database PHP.

Kiểm file/checksum/manifest/PK/FK trước khi đọc; bảng thiếu không coi là rỗng. Giới hạn 50.000 yêu cầu/32 MiB JSON nguồn, 50.000 dòng/64 MiB JSON đích. Native bảng/audit/khóa phải InnoDB, cột/primary key/precision phù hợp. Dữ liệu đích khác nguồn hoặc có dòng ngoài nguồn chặn; không ghi đè trạng thái sau khi nhân viên đã xử lý.

Apply kiểm fingerprint và nguồn/đích lại trong transaction dưới khóa catalog. Lỗi/audit rollback toàn bộ; hai tiến trình cùng fingerprint chỉ một apply. Chạy lại không tạo thêm dòng/audit. Khách gửi lại yêu cầu Node cũ giữ trạng thái đã xử lý, không phát lại; request cùng ID khác nội dung bị từ chối. API quản trị trả milliseconds đúng Node; SQL giữ đủ sáu chữ số phần giây.
