# Tìm và xuất đơn xe — đợt 127

Admin **Đơn xe & nhóm ghép** có ô tìm theo mã booking, tên khách, điện thoại hoặc email. Tìm chuỗi con không phân biệt hoa/thường; `%`, `_` và ký tự SQL được xử lý như văn bản thường, không phải wildcard. Giữ lọc ngày/trạng thái và phân trang hiện có; tối đa 100 UTF-16 units cho từ khóa. Chế độ điều xe hôm nay vẫn dùng nhóm all/upcoming/driver/return riêng và bỏ qua bộ lọc thông thường như nguồn.

Nút **Xuất CSV đơn xe** gọi `POST /api/v1/admin/rides/export` với `{date,status,q}`. Cần phiên admin, Origin đúng và header mutation hiện có; không nhận filter qua query string. API trả `{csv,count}` theo hợp đồng Node, private/no-store; trình duyệt chỉ tải sau khi người dùng bấm. Ghi audit `ride.export` với số bản ghi, không ghi nội dung khách vào audit.

Xuất tất cả bản ghi khớp bộ lọc, không chỉ 50 đơn của trang hiện tại. Thứ tự ổn định: ngày đi, thời điểm tạo, ID. Giới hạn 10.000 đơn; vượt giới hạn báo lỗi để thu hẹp bộ lọc, không âm thầm xuất thiếu và không ghi audit hoàn tất. Nút xuất bị tắt ở chế độ điều xe hôm nay để không nhầm phạm vi. Lỗi giữ bộ lọc và cho thử lại; phản hồi khi đã rời màn hình không tạo file tải.

Giữ đúng 25 cột từ `transport/rides-csv.ts`: booking, ngày/giờ/trạng thái, loại xe/khách/tuyến/địa chỉ, liên hệ, tổng đơn/giá vốn, tình trạng thu và số tiền ghi thu, khứ hồi, tài xế/biển số và ghi chú khách/nội bộ. Giá vốn bằng 0 giữ số 0, chưa có giá vốn để trống. Số tiền ghi thu chưa trừ hoàn được ghi rõ ở tên cột, không suy ra lợi nhuận sau hoàn.

CSV UTF-8 BOM/CRLF, mọi ô được quote/escape, bỏ NUL, vô hiệu hóa công thức bảng tính kể cả sau khoảng trắng và giữ số điện thoại có số 0 đầu bằng tiền tố apostrophe, giống helper nguồn. Không xuất requestHash, request key, access proof hoặc cấu hình thanh toán. Export có thông tin liên hệ/ghi chú nội bộ theo quyền admin; không có endpoint public/POS tương ứng.

Không thêm SQL migration: schema vẫn **053**. Nếu chưa cập nhật đợt 126, thực hiện migration 053 theo AREA-SOURCE.md. Không nhập dữ liệu cũ, ghi thu, gửi mail hay thay đổi trạng thái chuyến khi xuất.

Kiểm thử:

```bash
php tests/ride-export.php
php tests/rides.php
node tests/rides-admin-ui.cjs
node tests/build-ride-export-fixture.mjs /path/to/local/node-repository
```

Fixture CSV sinh bằng chính helper Node nguồn, so toàn bộ byte gồm Unicode, dấu quote/comma/xuống dòng, formula, số 0 và trường thiếu. PHP integration test dùng JINGO_TEST_DSN/DB thử riêng: lọc literal/case, ordering, 10.000/10.001 và audit khi lỗi. HTTP kiểm session/Origin/method/query/headers; Node DOM kiểm payload và tải blob. Regression đặt xe/giữ chỗ/idempotency/điều phối đã qua. Chưa kiểm trình duyệt trên hosting hoặc mở file bằng Excel thực tế.
