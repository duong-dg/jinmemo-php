# Phần chuyển đổi còn cần hoàn tất

Cập nhật sau đợt 124 (schema 052). Đây là danh sách phần thiếu đã xác nhận, không phải chứng nhận đã đối chiếu hết mọi endpoint của nguồn.

| Phần | Trạng thái và điều kiện hoàn tất |
| --- | --- |
| Thời tiết | Đã chuyển cấu hình, API, cache/hạn mức, gợi ý và hiển thị; xem WEATHER.md. Còn kiểm tích hợp bằng tài khoản thật trên hosting |
| OTP và chính sách bảo mật admin | Đã chuyển OTP đăng nhập/đổi chính sách, đường dẫn riêng, nhật ký và geo opt-in; xem ADMIN-SECURITY.md. Còn kiểm SMTP thật và cấu hình hosting; không import phiên/challenge Node |
| Sao lưu/khôi phục trong ứng dụng | Đã có danh sách/tải archive với SHA-256, trạng thái lịch và xuất SQL database PHP; xem BACKUP-RESTORE-PHP.md. Đã thêm CLI archive database PHP mã hóa GPG, kiểm giải mã và retention riêng. Đã thêm archive ứng dụng PHP gồm DB/media/file vé/khóa/cấu hình/source Git, có gate drain request/worker và kiểm phục hồi riêng. Đã có CLI kiểm tra integrity/cấu trúc archive độc lập, không chạy SQL/ghi đè hoặc giả marker phục hồi. Còn tự cài/xác minh cron, thử trên cPanel thật và cấu hình phục hồi tài khoản/SSL/cron/offsite; bản database vẫn được phân biệt riêng |
| Giỏ hàng công khai | Đã có panel vé/quà/xe, draft vé/quà chung VI/EN, draft xe theo tab và khôi phục yêu cầu. Đã nối chốt đơn chung từ lựa chọn, journal khôi phục từng phần và dọn giỏ theo snapshot; xem DRAFT-CHECKOUT.md. Đã chọn provider LIVE trên form và xem thanh toán JB sau đặt; đã có form vận chuyển từng gói ở cả giỏ và chốt đơn. Còn kiểm đầy đủ dữ liệu staging/hosting và đối chiếu toàn bộ nguồn; không đưa tra cứu đơn cũ vào panel |
| Chuyển dữ liệu cũ | Đã có importer ảnh/bài viết riêng và CLI kiểm file transfer Node không cần DB (format/counts/schema/giá trị, chưa kiểm quan hệ; importReady luôn false). Chưa có importer toàn bộ danh mục, đơn, tồn, ledger và quan hệ; xem MIGRATION.md, TRANSFER-FILE-AUDIT.md và TRANSFER-CONTRACT.json |
| Thanh toán | Đã có nhiều luồng vận hành, nhưng vẫn cần đối chiếu chi tiết nguồn và kiểm provider thật; xem PAYMENT-PORT-STATUS.md. Sổ hoàn không đồng nghĩa đã chuyển tiền hoàn qua ngân hàng/provider |
| Kiểm trước vận hành | Cần chạy bộ chức năng với dữ liệu staging thực tế, hosting cPanel, SMTP/provider và các quyền vai trò. Không dùng hai database độc lập cùng trừ tồn hoặc ghi thu |

Bản nguồn không được thay đổi trong quá trình port. Các tính năng đã có ở PHP không tự chứng minh importer hoặc tính tương đương toàn bộ nguồn đã hoàn tất. PDF tiếp tục chỉ đính kèm/giao file gốc theo phạm vi đã chốt.
