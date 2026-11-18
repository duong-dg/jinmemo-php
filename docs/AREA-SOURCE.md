# Danh mục địa chỉ và lưu từng mục cấu hình xe — đợt 126

## Cập nhật hosting

Sau `git pull origin main`, import **database/053-area-source.sql** bằng phpMyAdmin hoặc chạy installer theo quy trình cập nhật hiện có. Migration chỉ tạo cache danh mục và khóa phối hợp, không nhập dữ liệu cũ, đổi giá tuyến hoặc sửa đơn khách. Giữ `.env` và `storage`. PHP cần curl/mbstring/PDO MySQL, outbound HTTPS và CA hợp lệ.

## Danh mục hành chính

Chuyển từ `apps/api/src/transport/area-source.module.ts` tại revision `7e3578d2c27f166ba42a48ce4ee5b21e7ff33cbb`:

- `GET /api/v1/admin/ride-areas?version=v2`: danh sách tỉnh/thành, cần phiên admin.
- Thêm `province=CODE`: v2 trả khu vực tỉnh và xã; v1 trả danh sách huyện. Thêm `district=CODE` trong v1 trả cây tỉnh/huyện/xã cũ.
- `GET /api/v1/gifts/addresses` và `?province=CODE`: tỉnh hoặc xã v2, không cần đăng nhập; giới hạn 60 request/phút/IP theo REMOTE_ADDR.

Giữ ID `vn-v2-p-*`, `vn-v2-w-*`, `vn-v1-p-*`, `vn-v1-d-*`, `vn-v1-w-*` và hậu tố tên “(cũ)” của nguồn. Chỉ gọi host cố định `https://provinces.open-api.vn/api/`, không nhận URL khách cung cấp. Chặn tham số mảng/đường dẫn lạ, huyện không thuộc tỉnh, mã phản hồi không khớp và danh sách hỏng/trùng; không theo redirect. Response public chỉ có code/name, không trả metadata ngoài hợp đồng.

Cache dùng chung giữa PHP web request và giữa API quà/admin: 24 giờ, tối đa 128 mục, mỗi JSON tối đa 1.000.000 byte, tối đa 500 đơn vị/danh sách. Lock cache chỉ giữ trong transaction ngắn, không giữ lúc chờ mạng. Timeout upstream 12 giây, connect timeout 5 giây, backoff 30 giây khi cold/failed/in-flight. Dịch vụ ngoài lỗi trả 503; không tự sửa cấu hình xe, không chặn địa chỉ khách nhập tay hoặc những khu vực đã lưu.

## Thao tác trên giao diện

Trong admin **cấu hình xe → Khu vực → Thêm từ tỉnh/huyện/xã**, chọn danh mục/tỉnh/huyện, xem trước danh sách rồi xác nhận. Chỉ thêm ID còn thiếu; giữ nguyên tên, English, phụ thu và các mục đang tồn tại. Không tự thêm giá tuyến hoặc bật mở bán. Vượt 1.000 khu vực sẽ bị chặn, không cắt bớt danh sách. Lưu bằng token phiên bản riêng của mục khu vực, chống ghi đè thay đổi đồng thời.

Form quà và chốt đơn chung có bộ chọn tỉnh/xã khi giao hàng, nhãn VI/EN. Chỉ tải khi mở bộ chọn. Khách phải bấm thêm khu vực vào địa chỉ, vẫn nhập số nhà/tên đường trong ô hiện có. Không thêm trường mới vào payload đơn quà hoặc tự đổi địa chỉ cũ. Chặn phản hồi tỉnh cũ ghi đè lựa chọn mới, thêm trùng hậu tố và vượt giới hạn 500 ký tự. Khi lỗi danh mục, giữ địa chỉ và cho nhập tay. Phí giao vẫn theo cấu hình quà, không tính lại theo tỉnh/xã.

## Lưu cấu hình theo từng mục

Chuyển thêm luồng `POST /api/v1/admin/transport-plan/sections/:section` từ `planning.module.ts`. Nhận `vehicles`, `areas`, `fares`, `settings`; GET cấu hình trả `sectionVersions` và `bookedSeats` đang hoạt động. Với ba danh sách, gửi `{version: HASH, rows: [...]}`; thiết lập gửi `{version: HASH, settings: {...}}`.

Server giữ khóa catalog, đọc bản mới nhất rồi so token của đúng mục đang sửa. Thay đổi mục khác được giữ lại; cùng mục đã thay đổi trả 409. Hợp nhất xong vẫn kiểm toàn bộ cây/giá/chuyến, đơn xe và nhóm ghép đang hoạt động, rồi ghi cấu hình/audit trong cùng transaction. Không bỏ qua bảo vệ khi xe đang có khách hoặc giảm ghế quá sức chứa.

Admin thêm/sửa/bỏ xe, khu vực, giá tuyến và thiết lập dùng API theo mục. Import danh mục cũng lưu riêng mục khu vực. Chuyến và yêu cầu kế hoạch tiếp tục dùng phiên bản toàn bản để bảo vệ quan hệ ghế/chuyến. API lưu toàn bản vẫn giữ payload/response hiện có. Token chỉ dùng chống ghi đè cấu hình, không phải quyền xác thực hay khóa dữ liệu chuyển từ Node.

## Đã kiểm và phần còn lại

Đã kiểm MySQL tách riêng và HTTP cục bộ với upstream mô phỏng: v1/v2/ID/cây, cache TTL/cap/backoff, lỗi dữ liệu, quyền admin, Origin, rate limit, địa chỉ nhập tay không bị ghi đè, section token, rollback/audit và bảo vệ đơn hoạt động. Hai worker cùng sửa hai mục giữ được cả hai thay đổi; cùng sửa một mục khác nội dung chỉ một worker lưu được.

Node DOM kiểm VI/EN, lazy load, phản hồi đảo thứ tự, giới hạn địa chỉ, preview/xác nhận rõ ràng và giữ mục đã sửa tay. Regression giỏ quà, chốt đơn chung, cấu hình/chuyến/giữ ghế và HTTP đã chạy. Đây chưa thay cho kiểm layout/accessibility toàn trang trên trình duyệt, API địa chỉ thực tế hoặc hosting cPanel thật. Không nhập dữ liệu thật.

```bash
php tests/area-source.php
php tests/transport-sections.php
php tests/transport-plan.php
node tests/area-source-ui.cjs
node tests/transport-plan-ui.cjs
node tests/gifts-ui.cjs
node tests/draft-checkout-ui.cjs
```

PHP integration tests cần JINGO_TEST_DSN trỏ server MySQL thử, không có dbname; mỗi test tạo/xóa DB ngẫu nhiên riêng. Không chạy test bằng tài khoản chỉ được thao tác database vận hành.
