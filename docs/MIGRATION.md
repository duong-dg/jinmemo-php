# Chuyển dữ liệu bản Node/PostgreSQL sang PHP/MySQL

Có thể chuyển dữ liệu. Bản Node hiện có **Xuất dữ liệu MySQL (.sql)** tạo các bảng staging `jm_transfer_*`, giữ tên cột nguồn. File này nhập được vào database chuyển dữ liệu riêng, nhưng chưa phải schema vận hành của PHP. Repo PHP chưa có bộ nhập toàn bộ staging vào bảng `jingo_*`.

## Cách chuyển dự kiến

1. Sao lưu PostgreSQL và toàn bộ file upload. Giữ bản gốc để phục hồi.
2. Xuất file MySQL transfer v1 từ bản Node, nhập vào database staging riêng. Công cụ ánh xạ sau này đọc các bảng đó và nạp vào schema PHP đã chạy `php bin/install.php`; không đổi tiền tố bằng tìm/thay rồi import thẳng vào database đang chạy.
3. Chạy thử trên database mới, kiểm tra số bản ghi, ID tham chiếu, mã đơn, số tiền, trạng thái thanh toán, tồn vé và lượt voucher. Đơn cũ phải giữ nguyên snapshot giá, không tính lại theo bảng giá mới.
4. Chuyển file ảnh và file vé/PDF gốc cùng dữ liệu tham chiếu. PDF chỉ cần được đính kèm gửi khách, không cần tách mã hay dựng lại PDF.
5. Khi chuyển chính thức, tạm dừng phát sinh đơn/thanh toán ở bản cũ, xuất lần cuối và đối chiếu trước khi đổi website. Không để hai bản cùng nhận đơn trên hai database độc lập.

## Các điểm cần xử lý

- Giữ ID sản phẩm, gói vé, nhà cung cấp, voucher và đơn; chuyển kiểu JSON, boolean và thời gian UTC đúng nghĩa.
- Giữ mã đơn, snapshot, liên hệ, lịch sử, thanh toán, mã giao dịch duy nhất và phân bổ tồn/voucher. Chỉ nhập những module mà PHP đã hỗ trợ đầy đủ; bảng chưa hỗ trợ phải được giữ trong bản sao lưu để chuyển sau.
- Bảng `jingo_orders` ở PHP hiện hỗ trợ tạo/tra cứu đơn WEB; chưa đủ để nhận toàn bộ dữ liệu POS, CTV, thanh toán và giao vé của bản cũ.
- Mật khẩu admin bản Node dùng scrypt, bản PHP hiện dùng bcrypt: cần đặt lại mật khẩu hoặc bổ sung cơ chế xác minh/chuyển hash có kiểm thử. Phiên đăng nhập cũ không mang sang.
- Cấu hình SMTP, khóa mã hóa, domain và callback thanh toán phải được đối chiếu khi chuyển các module tương ứng. Không đưa bí mật vào file xuất công khai hoặc Git.
- Chuyển giữa hai phiên bản PHP/MySQL sau này: giữ database và chạy migration bổ sung theo hướng dẫn từng phiên bản; không xóa database để cài lại.

Không sửa dump bằng tìm/thay cú pháp hàng loạt rồi nhập vào database thật. Công cụ chuyển cần chế độ chạy thử và báo cáo đối chiếu trước khi sử dụng.

## Đối chiếu trường với export bên Node

Tham chiếu exporter tại commit `585cd8a36c106dd3552fb397281f7f2e9c299f50`. [TRANSFER-CONTRACT.json](TRANSFER-CONTRACT.json) lưu danh sách trường vé/file và các khác biệt đã biết. Bộ test có SQL fixture sinh bằng chính `mysqlCreate/mysqlInsert` của exporter, không phải dump dữ liệu khách hàng.

- `jm_transfer_order_tickets` → `jingo_order_tickets`: giữ đủ id/order_id/code/code_hash/kind/covers/note/created_at.
- `jm_transfer_ticket_files` → `jingo_ticket_files`: giữ đủ id/order_id/ticket_id/name/mime/bytes/hash/created_at/revoked_at/revoke_reason/file_kind/deleted_at/partner_shared/sharing_version. Thời gian mới dùng DATETIME(6). Không tự đặt lại file_kind, sharing_version hoặc trạng thái thu hồi/xóa khi chuyển.
- File vật lý: giữ nguyên nội dung và ID, chép vào `MEDIA_DIR/.tickets/{id}.bin` (mặc định `storage/media/.tickets`). Đối chiếu bytes/SHA-256 với metadata. SQL không chứa file ảnh/PDF.
- Cấu trúc đã làm ở các đợt trước có chỗ cần adapter: `order_actions.note` ở PHP nằm trong JSON `data.note`; `order_payments.reference` nằm trong bảng `jingo_payment_references` nối qua reference_hash; `payment_methods.method` nằm trong `jingo_order_payments.method`. Phải kiểm cả bản ghi mã giao dịch dùng chung khi nhập tiền cũ.
- Mã đơn vé `JM…` đã được nhận ở tra cứu PHP cùng mã `JG…`; giữ mã gốc khi nhập. Các mã giỏ/xe/quà/phòng cần module tương ứng, không đưa vào bảng đơn vé một cách tự động.
- Trước khi viết importer cần kiểm giới hạn ID/chuỗi, độ chính xác thời gian ở các bảng PHP cũ, hash yêu cầu/idempotency và những cột nguồn bổ sung. Chưa có bảo đảm toàn bộ bảng export đã tương thích. Dữ liệu chưa hỗ trợ tiếp tục giữ trong staging và bản backup, không bỏ cột để ép nhập.
