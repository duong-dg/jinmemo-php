# Phần chuyển đổi còn cần hoàn tất

Cập nhật sau đợt 109 (schema 051). Đây là danh sách phần thiếu đã xác nhận, không phải chứng nhận đã đối chiếu hết mọi endpoint của nguồn.

| Phần | Trạng thái và điều kiện hoàn tất |
| --- | --- |
| Thời tiết | Đã chuyển cấu hình, API, cache/hạn mức, gợi ý và hiển thị; xem WEATHER.md. Còn kiểm tích hợp bằng tài khoản thật trên hosting |
| OTP và chính sách bảo mật admin | AdminAuth.php vẫn chủ động trả 503 khi bật otpEnabled. Cần chuyển auth.service.ts/security.service.ts, SMTP OTP, giới hạn/challenge, UI và kiểm thử; không tự tắt OTP để cho đăng nhập |
| Sao lưu/khôi phục trong ứng dụng | Chưa chuyển đầy đủ backups.module.ts và giao diện quản trị tương ứng. Không coi việc sao lưu qua cPanel là đã chuyển chức năng nguồn |
| Chuyển dữ liệu cũ | Đã có importer ảnh/bài viết riêng. Chưa có importer toàn bộ danh mục, đơn, tồn, ledger và quan hệ; xem MIGRATION.md và TRANSFER-CONTRACT.json |
| Thanh toán | Đã có nhiều luồng vận hành, nhưng vẫn cần đối chiếu chi tiết nguồn và kiểm provider thật; xem PAYMENT-PORT-STATUS.md. Sổ hoàn không đồng nghĩa đã chuyển tiền hoàn qua ngân hàng/provider |
| Kiểm trước vận hành | Cần chạy bộ chức năng với dữ liệu staging thực tế, hosting cPanel, SMTP/provider và các quyền vai trò. Không dùng hai database độc lập cùng trừ tồn hoặc ghi thu |

Bản nguồn không được thay đổi trong quá trình port. Các tính năng đã có ở PHP không tự chứng minh importer hoặc tính tương đương toàn bộ nguồn đã hoàn tất. PDF tiếp tục chỉ đính kèm/giao file gốc theo phạm vi đã chốt.
