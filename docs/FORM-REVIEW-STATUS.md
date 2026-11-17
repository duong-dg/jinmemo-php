# Rà soát form và thao tác nhập liệu

Cập nhật 17/11/2026, sau đợt 107. Không đổi tên trường dữ liệu hoặc schema 050.

| Khu vực | Đã chỉnh và kiểm tra |
| --- | --- |
| Form dùng chung | Ô nhập, select, textarea, checkbox/radio, focus, disabled/readonly; responsive qua forms.css |
| Admin thanh toán | Nhóm cấu hình riêng từng cổng; form lọc có nhãn, trạng thái và phân trang giữ đúng bộ lọc |
| Đặt vé ngoài web | Nhóm thông tin liên hệ; email/điện thoại cùng hàng khi rộng; điều kiện và nút xác nhận đủ ngang |
| Giỏ WEB | Tên/email/điện thoại có autocomplete phù hợp; kiểm tên/điện thoại sau trim; ghi chú giữ nhiều dòng; hướng dẫn email/khóa tra cứu |
| Đặt phòng | Ghi chú đổi sang textarea 2.000 ký tự; trường liên hệ có tên/autocomplete; kiểm thông tin trước bước xác nhận |
| Tra cứu phòng | Nhận mã viết thường và chuẩn hóa mã sang hoa; khóa riêng không đổi chữ hoa/thường, tắt autocapitalize/spellcheck |
| Mã tổng JB | Các ô khóa riêng tắt autocomplete/autocapitalize/spellcheck; giữ nguyên cách xác minh từng loại proof |
| Báo giá vé/phòng | Bàn phím numeric cho số khách/số phòng; giữ min/max/step đã có |
| POS/CTV | Style chung; không tự điền thông tin nhân viên vào trường khách/ngân hàng, dùng autocomplete=off; giữ cơ chế tài khoản/quầy và retry |

Đã kiểm trực quan renderer đặt phòng thật trên fixture cục bộ ở desktop 1280px và mobile 390px: ghi chú 112px, chữ nhập mobile 16px, không tràn ngang; trình duyệt nhận đúng pattern điện thoại. Chưa kiểm từng màn hình admin với dữ liệu vận hành hoặc hosting thật.

Kiểm thử chức năng gồm tiếng Việt/Anh, dữ liệu không hợp lệ trước xác nhận, không tạo đơn ở bước xem lại, ghi chú có xuống dòng và gửi lại nguyên payload khi mất phản hồi. Những thuộc tính hỗ trợ trình duyệt không thay thế kiểm tra dữ liệu tại server. Chưa import dữ liệu cũ.

- Tra cứu JC/JB khóa nút và ô nhập trong lúc tải để tránh gửi lặp. JB chụp mã/khóa theo từng yêu cầu, bỏ phản hồi cũ nếu mã vừa tạo đã được tra cứu mới. Kiểm thử `node tests/public-lookup-ui.cjs` mô phỏng phản hồi đảo thứ tự, tạo mã trong lúc tra cứu, lỗi tải và mở lại nút.

## Đợt 108 — editor admin dùng chung

Khóa ô và nút trong lúc lưu sau khi đã lấy FormData; trạng thái lưu có thông báo hỗ trợ trình đọc màn hình. Lỗi giữ bản nháp và trả lại trạng thái disabled cũ; gửi lặp không tạo thêm yêu cầu. Hàng nút Hủy/Lưu bám dưới form dài, cột nhập co được và tiêu đề xuống dòng. Kiểm thử hành vi tại tests/admin-editor-ui.cjs; chưa kiểm trực quan CSS đợt 108 hoặc hosting thật. Các form chuyên biệt có luồng lưu riêng cần tiếp tục rà riêng. Không SQL mới.

## Đợt 109 — thời tiết

Form admin thời tiết có nhóm cấu hình, mật khẩu API Key, kiểm tra/xác nhận và khóa input khi đang xác nhận/gửi; giữ payload khi thử lại lỗi. Public có dự báo ba ngày, gợi ý, timestamp GMT+7, responsive ba cột/một cột. Đã xem renderer thật với dữ liệu mô phỏng trên desktop/mobile và chạy weather-ui.cjs; chưa dùng provider hoặc hosting thật. Schema mới 051; xem WEATHER.md.
