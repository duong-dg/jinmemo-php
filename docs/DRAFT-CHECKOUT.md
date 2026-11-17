# Chốt đơn từ giỏ đã chọn — đợt 118

Schema giữ 052. Không cần import SQL. Tải lại các tab cũ sau khi cập nhật mã.

`/chot-don` và `/en/checkout` mặc định mở giỏ vé/xe/quà đang chọn. Khách nhập liên hệ một lần, địa chỉ đón/trả nếu có xe, quầy hoặc địa chỉ nhận quà, chọn phương thức đang bật và kiểm tra tổng tiền. Phí giao chỉ tính khi chọn giao quà. Bước review kiểm lại báo giá vé/xe; server từng module vẫn kiểm giá/tồn khi tạo đơn. Không tự đặt, tạo QR hoặc thu tiền khi mở trang.

Khách xác nhận mới ghi journal `jinmemo-draft-checkout:v1` vào sessionStorage rồi lần lượt tạo JC, JM-X, JM-G và JB bằng API hiện có. Body, locale và khóa idempotency được cố định trước khi gửi; kết quả mỗi phần được lưu trước khi chuyển bước. Reload/đổi VI-EN chỉ hiện nút tiếp tục, không tự POST. Chỉ gửi lại phần chưa có kết quả và dùng nguyên body. Thông tin khách và khóa riêng không được đưa vào localStorage hoặc sự kiện giỏ.

Các helper khóa sửa giỏ trong cùng tab khi journal chưa hoàn tất. Journal không phải khóa chéo mọi tab; thay đổi độc lập ở tab khác vẫn được bảo toàn khi dọn giỏ. Sau khi có JB, chỉ dọn đúng snapshot đã đặt; vé/quà sửa ở tab khác vẫn giữ, xe có revision để không xóa lần chọn mới cùng tuyến. Journal có cờ đã dọn giỏ: nếu đóng trang giữa lúc ghi kết quả và dọn giỏ, tải lại sẽ dọn tiếp trước khi mở lần đặt mới.

Đây là nhiều giao dịch thành phần, không phải giao dịch database chung. Nếu một phần lỗi sau khi các phần khác đã đặt:

- Mất kết nối, 401/403/429 hoặc response không xác minh được: giữ nguyên yêu cầu, không cho bỏ để tạo lại; gửi lại bằng nút tiếp tục.
- 400/409/422: có thể tiếp tục nguyên yêu cầu hoặc chủ động dừng phần chưa tạo. Những đơn đã có mã vẫn tồn tại và chưa được thanh toán; chỉ những lựa chọn đã thành công được dọn khỏi giỏ.
- Khi dừng, liên kết riêng mở phần gom các mã đã tạo, kèm proof trong URL fragment. Giỏ của phần chưa tạo vẫn còn để sửa. Phải lưu liên kết/mã trước khi bắt đầu lần đặt mới. Không tự hủy hoặc hoàn các đơn đã tạo.

`?view=combine` giữ luồng gom đơn có sẵn; `?view=lookup` và liên kết `#JB…:key` chỉ mở tra cứu. Các liên kết cũ vẫn hoạt động. Panel giỏ trỏ vào chốt đơn chung và không chứa form tra cứu mã cũ.

Đợt 119 đã bổ sung chọn provider online LIVE trên form chung. Sau khi có JB, tra trạng thái server và đưa cổng đã chọn lên đầu; chỉ tạo QR/liên kết khi khách bấm, kiểm số tiền khớp tổng JB trước khi cho mở. Đơn đã trả tiền không hiện bước tạo thanh toán. Giới hạn còn lại: các trường vận chuyển riêng từng gói vé chưa được đối chiếu hết với nguồn. Chưa kiểm giao dịch thật, SMTP hoặc hosting cPanel. Chưa có importer toàn bộ database cũ. PDF chỉ giao file gốc.

Kiểm tra: checkout-journal-ui, draft-checkout-ui, checkout-ui, các test giỏ/quà/xe và tra cứu; checkout-bundles.php và full http.php. Đã xem form VI/EN, phí giao và bước review trên desktop/mobile bằng fixture không tạo đơn. Worker kiểm thử đồng thời dùng UTC giống bootstrap ứng dụng để báo cáo thu không phụ thuộc múi giờ MySQL máy chạy test.
