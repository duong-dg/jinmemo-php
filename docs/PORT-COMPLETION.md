# Phần chuyển đổi còn cần hoàn tất

Cập nhật sau đợt 112 (schema 052). Đây là danh sách phần thiếu đã xác nhận, không phải chứng nhận đã đối chiếu hết mọi endpoint của nguồn.

| Phần | Trạng thái và điều kiện hoàn tất |
| --- | --- |
| Thời tiết | Đã chuyển cấu hình, API, cache/hạn mức, gợi ý và hiển thị; xem WEATHER.md. Còn kiểm tích hợp bằng tài khoản thật trên hosting |
| OTP và chính sách bảo mật admin | Đã chuyển OTP đăng nhập/đổi chính sách, đường dẫn riêng, nhật ký và geo opt-in; xem ADMIN-SECURITY.md. Còn kiểm SMTP thật và cấu hình hosting; không import phiên/challenge Node |
| Sao lưu/khôi phục trong ứng dụng | Đã có danh sách/tải archive với SHA-256, trạng thái lịch và xuất SQL database PHP; xem BACKUP-RESTORE-PHP.md. Còn bộ tạo archive GPG/cron/retention và phục hồi toàn stack trên cPanel; chưa tự động cấu hình lịch |
| Chuyển dữ liệu cũ | Đã có importer ảnh/bài viết riêng. Chưa có importer toàn bộ danh mục, đơn, tồn, ledger và quan hệ; xem MIGRATION.md và TRANSFER-CONTRACT.json |
| Thanh toán | Đã có nhiều luồng vận hành, nhưng vẫn cần đối chiếu chi tiết nguồn và kiểm provider thật; xem PAYMENT-PORT-STATUS.md. Sổ hoàn không đồng nghĩa đã chuyển tiền hoàn qua ngân hàng/provider |
| Kiểm trước vận hành | Cần chạy bộ chức năng với dữ liệu staging thực tế, hosting cPanel, SMTP/provider và các quyền vai trò. Không dùng hai database độc lập cùng trừ tồn hoặc ghi thu |

Bản nguồn không được thay đổi trong quá trình port. Các tính năng đã có ở PHP không tự chứng minh importer hoặc tính tương đương toàn bộ nguồn đã hoàn tất. PDF tiếp tục chỉ đính kèm/giao file gốc theo phạm vi đã chốt.
