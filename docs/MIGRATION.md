# Chuyển dữ liệu bản Node/PostgreSQL sang PHP/MySQL

Có thể chuyển dữ liệu, nhưng không import trực tiếp PostgreSQL SQL dump vào MySQL. Hiện chưa có công cụ chuyển dữ liệu tự động trong repo này.

## Cách chuyển dự kiến

1. Sao lưu PostgreSQL và toàn bộ file upload. Giữ bản gốc để phục hồi.
2. Xuất dữ liệu theo bảng sang JSON hoặc CSV UTF-8, dùng công cụ chuyển riêng để nạp vào schema MySQL đã chạy `php bin/install.php`.
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
