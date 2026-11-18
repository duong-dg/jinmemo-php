# Rà soát form và thao tác nhập liệu

Đợt 128: màn Thu tiền xe tại POS có tra cứu theo COLLECT và nút chủ động tạo QR đủ điều kiện; giữ thu tiền mặt, khóa phần nhập cash khi đã tạo QR và bỏ phản hồi/view cũ khi đổi mã. Đã kiểm Node DOM và quyền HTTP, chưa kiểm trực quan layout/cPanel thật. Không SQL mới; xem POS-RIDE-QR.md.

Đợt 127: admin đơn xe có ô tìm có nhãn hỗ trợ, giới hạn 100 ký tự và nút xuất CSV theo ngày/trạng thái/từ khóa. Chặn tải lặp khi đang gửi và khi xem điều xe hôm nay; lỗi giữ bộ lọc. Đã kiểm Node DOM/HTTP/MySQL; chưa kiểm layout trình duyệt hoặc Excel thật. Xem RIDE-EXPORT.md; không SQL mới.

Đợt 126 bổ sung bộ chọn tỉnh/xã VI/EN ở form giao quà và chốt đơn chung; admin xem trước/thêm khu vực hành chính, giữ chỉnh sửa cũ và lưu cấu hình xe theo mục. Đã kiểm Node DOM/HTTP/MySQL, chưa kiểm trực quan layout hoặc cPanel thật cho đợt này. Cần schema 053; xem AREA-SOURCE.md. Các đợt phía dưới là lịch sử, không phải xác nhận đã rà hết mọi form.

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

## Đợt 110 — bảo mật và bộ chọn ngôn ngữ

Thêm form OTP đăng nhập và form chính sách có bước nhận mã/xác nhận, khóa thao tác khi gửi, giữ lựa chọn đã kiểm tra và cho nhập lại OTP sai. Bộ chọn ngôn ngữ dùng cờ/tên/ngôn ngữ hiện tại và mục thiếu bản dịch; Escape/nhấp ngoài đóng danh sách. Menu chính bỏ Giỏ vé/Thanh toán chung, chuyển giỏ sang biểu tượng riêng. Đã xem picker trên fixture PHP desktop/mobile; không thay dữ liệu vận hành. Kiểm thử admin-security-ui.cjs, language-picker.php và language-picker-ui.cjs. Schema 052; xem ADMIN-SECURITY.md. Chưa xem toàn bộ form bảo mật trong phiên hosting thật.

## Đợt 111 — menu và thông báo lỗi

Header desktop nhóm Thông tin và giữ Liên hệ riêng; mobile dùng nút Menu có tra cứu đơn hàng, cùng nhãn Việt/Anh và cùng điều kiện feature. Kiểm panel bằng Escape/nhấp ngoài/focus/breakpoint. Form khách dùng thông điệp lỗi English theo errorText nguồn (40 fixture đối chiếu), giữ status và cơ chế retry. Đã kiểm PHP/Node/HTTP/feature flags và xem fixture header desktop/mobile, không tràn ngang theo DOM ở kích thước đã kiểm. Không thay schema 052. Chưa chuyển hoàn chỉnh cart drawer/thiết kế toàn site và chưa kiểm hosting thật.

## Đợt 112 — sao lưu admin

Có review trước xuất SQL, khóa nút khi đang tải và bỏ phản hồi khi rời mục. Thẻ archive hiển thị ngày GMT+7, kích thước, số lượng, SHA-256 và marker phục hồi; nhãn dài tự xuống dòng. Đã kiểm Node UI và xem fixture desktop/mobile (không tải file vận hành); không tràn ngang theo DOM ở kích thước đã kiểm. Xem BACKUP-RESTORE-PHP.md cho giới hạn chức năng và quy trình. Không thay schema 052.

## Đợt 114 — giỏ vé trượt

Header có badge số vé, mở panel vé đang chọn từ mọi trang khách. Thêm gói từ báo giá mở panel; panel chỉ xem/bỏ gói/đi đến form đặt, giữ phần tra cứu ở chế độ riêng. Đã kiểm modal/focus/scroll và dữ liệu mô phỏng desktop/mobile, cùng test count/concurrent removal/pending/malformed state. Chưa có giỏ nháp xe/quà chung hoặc đồng bộ giỏ giữa hai ngôn ngữ. Không thay schema 052.

## Đợt 115 — giỏ quà và form đặt quà

Số lượng quà được lưu chung giữa VI/EN; nút Thêm vào giỏ mở panel có giá/tên mới. Form khách có review riêng và session payload cố định khi chờ, hỗ trợ reload/retry; 4xx xác định quay lại danh mục mới và giữ thông tin liên hệ. Phone hỗ trợ +84, pickup counter required khi có quầy, tổng kiểm safe integer. Đã kiểm các test gift-cart/gifts/gift-drawer UI, backend GiftOrders và full HTTP; đã xem thêm quà, chuyển EN và panel mobile với API mô phỏng. Chưa hoàn tất giỏ xe hoặc checkout hợp nhất từ draft. Không thay schema 052.

## Đợt 118 — form chốt đơn chung

Form liên hệ dùng chung vé/xe/quà, địa chỉ đón/trả và nhận quà có required/giới hạn, phí giao cập nhật riêng, summary từng phần và bước review trước POST tạo đơn. Có focus vùng xác nhận, hai cột desktop và một cột mobile, khung tổng không che bước xác nhận. Tra cứu JB/gom mã cũ tách khỏi form đặt mới. Đã xem VI/EN và review bằng fixture, không bấm tạo đơn thật; xem DRAFT-CHECKOUT.md về journal/retry và phần chưa hoàn tất. Schema giữ 052.

## Đợt 119 — chọn cổng và trạng thái thanh toán chung

Form chốt và gom mã có lựa chọn thủ công/online theo LIVE; bắt buộc chọn rõ, khử trùng VNPAY/QR, lỗi tải lại không giữ lựa chọn cũ làm hợp lệ. Sau đặt JB hiển thị cổng đã chọn, kiểm trạng thái bằng nút riêng, chặn mở cổng khi số tiền không khớp và không hiện tạo thanh toán cho đơn đã trả. Đã xem danh sách VI và kết quả EN bằng fixture, không tạo thanh toán thật. Schema vẫn 052.

## Đợt 120 — thông tin vận chuyển từng gói

Form riêng theo ID dòng ở giỏ vé và chốt đơn chung, có sức chứa xe riêng, giờ/số chỗ xe ghép, chuyến bay và khứ hồi. Review hiển thị tuyến, ngày/giờ, số khách trước nút xác nhận; khóa ô chuyến khi review, giữ giá trị lúc làm mới giá, retry body trip cố định. Không dùng chung input giữa các dòng. Đã xem VI desktop/EN mobile với fixture không tạo đơn; test frontend và backend transport/carts/full HTTP qua. Schema giữ 052.
