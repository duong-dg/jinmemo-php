# Thời tiết và gợi ý điểm đến

Đợt 109, 17/11/2026. Chuyển từ `apps/api/src/weather/weather.module.ts` và `weather-rules.ts` trong bản nguồn tại sourceCommit của TRANSFER-CONTRACT.json.

## Cập nhật trên cPanel

Tại thư mục chứa mã PHP:

```bash
git pull --ff-only origin main
php bin/install.php
```

Không có terminal: import riêng `database/051-weather.sql` bằng phpMyAdmin vào database PHP hiện tại. Migration chỉ thêm hai bảng cache/usage, không sửa đơn, tồn hoặc sổ thu. Sau đó Ctrl+F5.

Mở admin → Cấu hình website → Thời tiết & gợi ý. Nhập khóa WeatherAPI của bạn, bật thời tiết, kiểm tra rồi xác nhận lưu. Để trống ô khóa khi chỉnh cấu hình sẽ giữ khóa cũ. Khóa không được trả về trình duyệt, không có trong audit và được mã hóa bằng SecretCipher như SMTP; sao lưu cả `.smtp-key` trong MEDIA_DIR cùng dữ liệu cấu hình. Không chép nguyên ciphertext từ Node vì cơ chế khóa khác.

Điểm đến cần tọa độ hợp lệ ở Điểm đến; điểm con không có tọa độ dùng điểm cha đang hoạt động. Trang điểm đến và sản phẩm gắn destinationId tự tải dự báo; tắt cấu hình hoặc không có dữ liệu thì ẩn khối này. Chỉ tên điểm có tọa độ được dùng làm địa danh dự báo, tránh gán nhầm độ chính xác cho điểm con.

PHP cần cURL, HTTPS outbound đến `api.weatherapi.com` và chứng chỉ CA hợp lệ. Không tắt xác minh TLS. Không cần cron. Chưa nhập khóa thật hoặc gọi nhà cung cấp trong các kiểm thử.

## Hành vi đã chuyển

- `GET /api/v1/weather/:id?lang=vi|en` trả `{data: ...}` hoặc `{data:null}`, no-store. `GET/POST /api/v1/admin/weather` dùng phiên admin; POST kiểm tra Origin và header quản trị hiện có, phiên bản và trường đầu vào.
- Khóa lưu mã hóa, không lộ trong public/admin projection. Lưu cấu hình và audit cùng transaction; phiên bản cũ bị từ chối.
- Cache dùng chung theo tọa độ làm tròn 4 chữ số. Lease nguyên tử 15 giây để chia sẻ refresh giữa các request/process. Thành công hoặc lỗi đều nghỉ 1 giờ trước lần thử tiếp theo. HTTP provider tối đa 5 giây, không theo redirect và giới hạn response 1 MB.
- Dữ liệu dự báo từ hiện tại đến 3 ngày, thông tin buổi tối 18–22h và ngày tính theo Asia/Ho_Chi_Minh. Chỉ dùng dữ liệu cập nhật trong 4 giờ; fallback từ cache tối đa 4 giờ, từ 1 giờ có nhãn bản gần nhất và thời gian GMT+7.
- Giới hạn 90.000 lượt/tháng UTC và dừng toàn bộ hiển thị/gọi khi đạt giới hạn hoặc nhận mã lỗi 2007 từ provider; tháng mới có hạn mức mới. Hạn mức đếm lần thử, kể cả lỗi.
- Gợi ý Việt/Anh lấy đúng thông điệp và thứ tự ưu tiên của nguồn, tối đa 3/ngày. Hiển thị bằng textContent, không nhúng HTML từ provider. Không thay thế cảnh báo chính thức hoặc quyết định vận hành tour.
- Schema 051 giữ tên/thứ tự cột nguồn của cache `id,data,fetched_at,next_attempt` và usage `month,calls,blocked`. Đây là dữ liệu runtime, chưa bổ sung bộ import staging toàn hệ thống; không sửa TRANSFER-CONTRACT để tự suy diễn bảng export không có trong nguồn.

## Kiểm tra

```bash
php tests/weather-rules.php
node tests/weather-ui.cjs
# JINGO_TEST_DSN trỏ server MySQL thử riêng, không có dbname:
php tests/weather.php
```

80 fixture gợi ý sinh từ hàm Node gốc được đối chiếu VI/EN. Kiểm thử MySQL/HTTP gồm mã hóa, version, rollback audit, tọa độ cha, cache chia sẻ/lease, backoff, expiry, quota và đổi tháng, public projection và quyền admin/Origin/method/query. Kiểm thử DOM gồm review/frozen retry, xóa khóa khỏi form sau lưu, bỏ phản hồi admin cũ và text-only public rendering. Full HTTP hiện có đã chạy lại.

Đã xem widget thật với dữ liệu mô phỏng tại desktop 1280px và mobile 390px, ba cột/một cột và không tràn ngang theo DOM; đã xem form admin mobile. Chưa kiểm tài khoản WeatherAPI thật, hóa đơn/hạn mức nhà cung cấp hoặc hosting thật.
