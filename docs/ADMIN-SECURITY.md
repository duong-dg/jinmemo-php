# Bảo mật quản trị và OTP

Đợt 110, schema 052. Chuyển luồng từ auth.service.ts, security.service.ts, login-geo.ts và phần gửi OTP của mail.module.ts trong bản nguồn.

## Cập nhật

```bash
git pull --ff-only origin main
php bin/install.php
```

Không có terminal: import `database/052-admin-otp.sql` vào database PHP bằng phpMyAdmin. Sau đó Ctrl+F5. Phải cập nhật schema trước khi thử OTP.

Admin → Cấu hình website → Bảo mật & đăng nhập. Cấu hình và kiểm tra SMTP trước; mọi thay đổi chính sách đều cần một mã OTP gửi đến email của tài khoản đang đăng nhập. Kiểm tra các lựa chọn, nhận OTP, nhập mã rồi xác nhận. Khi lưu thành công, các phiên admin cũ bị thu hồi; trình duyệt vừa xác minh nhận cookie mới. Giữ lại đường dẫn riêng trước khi bật. Đường dẫn được sinh khi mở cấu hình, theo dạng `/jinmemo-<24 hex>`; đường dẫn `/jingo-<24 hex>` cũ vẫn được chấp nhận nếu khớp policy đang lưu.

Không tự bật OTP hoặc tra cứu IP khi cài migration. Không tự sửa chính sách hiện tại để bỏ xác minh. Nếu SMTP không hoạt động, OTP không tạo phiên và báo lỗi chung; cần khôi phục cấu hình SMTP/khóa mã hóa bằng bản sao lưu hợp lệ. Sao lưu file `.smtp-key` trong MEDIA_DIR ngoài public cùng database. Hash/mã OTP hoặc khóa mã hóa từ Node chưa được import; mật khẩu Node scrypt vẫn cần đặt lại sang hash PHP theo quy trình chuyển dữ liệu.

## Luồng đã chuyển

- Đúng mật khẩu và đúng đường dẫn riêng (nếu bật) mới tạo challenge LOGIN. Cookie `jingo_admin_challenge` HttpOnly, SameSite Strict, Secure trên HTTPS, path `/api/v1/admin`, hạn 5 phút. JSON chỉ có challengeId, expiresAt/expiresIn, emailHint và otpRequired; không có OTP hoặc token phiên.
- OTP 6 chữ số ngẫu nhiên, HMAC SHA-256 với khóa riêng dẫn xuất từ SecretCipher; database chỉ có code_hash và binding_hash, không lưu mã rõ hoặc cookie rõ. Mã hết hạn sau 5 phút, dùng một lần, tối đa 5 lần sai. Mã mới vô hiệu mã cũ cùng tài khoản/mục đích. Chỉ READY mới được xác minh; gửi mail lỗi chuyển USED.
- Throttle mật khẩu giữ nguyên 5/tài khoản và 100/IP trong 15 phút. Xác minh OTP tối đa 100/IP/15 phút. Gửi mã tối đa 5/tài khoản/mục đích/15 phút, cách nhau ít nhất 60 giây. API không có lối bỏ qua OTP.
- Lưu chính sách cần OTP SETUP gắn với phiên đang đăng nhập và cùng admin, kiểm version, cập nhật policy/audit/thu hồi phiên trong transaction. Vô hiệu challenge LOGIN chưa dùng và các phiên POS vai trò ADMIN, giữ phiên STAFF/CTV độc lập. Hai lần consume đồng thời chỉ một lần thành công; lỗi audit rollback cả dùng mã lẫn tạo phiên.
- API `POST admin/login/otp`, `GET/POST admin/security`, `POST admin/security/challenge`, `GET admin/security/logs?page=...`. Dùng Origin/header mutation hiện có, session và no-store. Nhật ký 25 dòng/trang, thứ tự created_at/id, không có mã OTP/password/token.
- Metadata thiết bị có giới hạn name/timezone/language; ghi IP kết nối trực tiếp và user agent đã lọc control. Chưa tin header forwarded của proxy. Khi bật geo, chỉ IP công khai được gửi đến DB-IP sau đăng nhập thành công; lỗi không chặn đăng nhập. Cache 30 ngày, tối đa 100 lượt/ngày UTC, timeout 2 giây, không redirect, response tối đa 16 KB, xác minh TLS và IP trong phản hồi.

Các bảng mới giữ tên/thứ tự cột của nguồn: admin_otp, admin_geo_cache, admin_geo_usage, với prefix jingo_. Không nhập phiên/challenge đang hoạt động từ Node. Schema 052 không sửa dữ liệu đơn/tồn/sổ thu.

## Kiểm thử

`php tests/admin-security.php` với JINGO_TEST_DSN của server MySQL thử riêng: OTP, binding/expiry/attempts, giới hạn gửi, mail failure, version/audit rollback, đổi phiên, vô hiệu challenge, hai process consume cùng mã, HTTP auth/CSRF/cookie/entry và geo opt-in/cache/quota. `node tests/admin-security-ui.cjs` kiểm bước nhận mã chưa lưu, đóng băng lựa chọn, sửa mã nhập sai và chỉ mở workspace sau OTP thành công. Đã chạy lại admin-auth.php và HTTP hiện có. Mail được mô phỏng trong bài kiểm OTP; chưa gửi email thật hoặc tra IP thật.

## Menu và hai ngôn ngữ

Bỏ hai liên kết Giỏ vé/Thanh toán chung khỏi menu chính. Giỏ vẫn truy cập bằng biểu tượng Giỏ; luồng gom mã JB vẫn mở từ đơn/giỏ khi cần. Khôi phục bộ chọn có cờ VI/EN, tên ngôn ngữ, dấu chọn và trạng thái Chưa có bản dịch. Chỉ trỏ tới localePaths đã xuất bản, giữ query/hash khi đổi ngôn ngữ và xóa bộ lọc chuyên mục ở trang danh sách blog như nguồn. URL PHP hiện có vẫn được giữ để không làm hỏng liên kết đơn đã gửi.

Đã kiểm picker thật trên fixture PHP desktop/mobile, đóng bằng Escape/nhấp ngoài và mục thiếu bản dịch; các kiểm thử language-picker.php/language-picker-ui.cjs kiểm markup và hành vi. Đây là sửa menu/bộ chọn, không phải tuyên bố toàn bộ trang khách đã giống thiết kế bản gốc hoặc mọi nội dung đã được dịch.
