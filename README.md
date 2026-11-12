# JinMemo — PHP + MySQL cho hosting

Đây là bản chuyển đổi riêng. Hiện có bộ khung PHP, phần cơ bản của xác thực admin, API loại sản phẩm, điểm đến, thư viện ảnh, nhà cung cấp, trang nội dung và yêu cầu liên hệ; chưa thay thế website hiện tại. Chưa có giao diện PHP hoặc công cụ chuyển dữ liệu.

## Cài đặt bộ khung

1. Dùng PHP 8.2 trở lên và extension `pdo_mysql`. Bật HTTPS trên hosting.
2. Tải cả thư mục này lên hosting, đặt document root của domain vào `hosting-php/public`. Chỉ thư mục `public` được truy cập từ web; `.env`, `src`, `bin`, `storage` phải nằm ngoài document root.
3. Sao chép `.env.example` thành `.env`, nhập thông tin database MySQL do hosting cấp. File dùng các dòng `KEY=value`, không hỗ trợ nội suy biến hoặc comment cuối dòng.
4. Apache cần cho phép rewrite qua `.htaccess`. Hosting dùng máy chủ khác cần cấu hình chuyển URL không phải file/thư mục sang `public/index.php`.
5. Nếu có terminal, chạy `php bin/check.php` để kiểm tra kết nối database, rồi `php bin/install.php` để tạo các bảng. Không có terminal thì import lần lượt các file `database/*.sql` theo số thứ tự qua phpMyAdmin. Cấu hình `PUBLIC_BASE_URL` đúng origin domain, không thêm dấu `/` cuối. Có thể chạy lại installer; không xóa dữ liệu hiện có.
6. Mở `/api/v1/health` để kiểm tra PHP chạy. Endpoint này không kiểm tra kết nối MySQL.

Chạy local từ thư mục này: `php -S 127.0.0.1:8088 -t public public/index.php` (chỉ để kiểm tra API của bộ khung).

## Phạm vi chuyển đổi đã chốt

- Đích PHP + MySQL, chuyển theo module và giữ hình thức giao diện hiện tại.
- Giữ URL `/api/v1` và hợp đồng API khi chuyển từng module.
- Vé đính kèm: lưu và gửi đúng file gốc cho khách. Không đọc QR từ PDF, tách trang hoặc tạo lại PDF mang thương hiệu.
- Module đính kèm sẽ cần kiểm tra quyền upload/download, loại và dung lượng file, lưu ngoài web root và cơ chế giao file qua email/link có kiểm quyền. Bộ khung hiện chưa triển khai upload hoặc gửi mail.
- Chuyển các module: đăng nhập/phân quyền → danh mục/nội dung → giá/lịch/tồn/voucher → giỏ/đơn/thanh toán → file gốc/email → quầy/CTV/công nợ/báo cáo/sao lưu.
- Khi chuyển đơn/tồn/thanh toán, kiểm tra giao dịch đồng thời và chống ghi nhận lặp trên MySQL thật. Các bài kiểm thử Node.js là nguồn đối chiếu hành vi.

Không dùng hai database độc lập để cùng ghi đơn và trừ tồn trong quá trình chuyển đổi. Chốt cách xuất/nhập dữ liệu và chuyển vận hành sau khi hoàn tất các module phụ thuộc.

## Module admin — đợt 1

- API đã có: `POST /api/v1/admin/login`, `GET /api/v1/admin/me`, `POST /api/v1/admin/logout`, `GET /api/v1/admin/login-policy`, `GET /api/v1/admin/login-entry/:slug`.
- Login nhận JSON `{ "email": "admin@example.com", "password": "..." }`. POST yêu cầu header `X-Jingo-Admin: 1`; Origin nếu có phải khớp `PUBLIC_BASE_URL`. Khi bật đường dẫn riêng, login cần `X-Jingo-Admin-Entry` khớp policy.
- Cookie `jingo_admin`: HttpOnly, SameSite Strict, Secure trên HTTPS, hết hạn 8 giờ. Database chỉ lưu SHA-256 của token. `/me` kiểm tra phiên tại database, logout xóa phiên.
- Giới hạn 5 lần thử/tài khoản và 100 lần/IP trong 15 phút, có khóa dòng khi cập nhật; lưu nhật ký thành công/thất bại/đăng xuất. Chỉ lấy IP kết nối trực tiếp, chưa tin header proxy.
- Tạo tài khoản: `php bin/create-admin.php admin@example.com`, nhập mật khẩu qua stdin (12–72 byte). Lệnh không ghi đè tài khoản đã có. Mật khẩu có thể hiện trên terminal khi nhập; không đặt mật khẩu trong tham số dòng lệnh.
- **Chưa chuyển:** gửi/xác minh OTP, thay đổi chính sách qua OTP, tra vị trí IP, giao diện và quyền POS/CTV. Khi policy bật OTP, login PHP trả 503 và không tạo phiên. Không dùng bản này thay thế quản trị đang bật OTP.
- Hash scrypt của Node chưa được hỗ trợ. Khi chuyển dữ liệu phải tạo lại mật khẩu admin bằng cơ chế PHP; không nhập nguyên hash cũ rồi kỳ vọng đăng nhập được. Không nhập phiên Node đang hoạt động.
- Kiểm thử: `JINGO_TEST_DSN` trỏ server MySQL/MariaDB thử riêng, không chứa dbname; `JINGO_TEST_USER` và `JINGO_TEST_PASSWORD` cấp tài khoản có quyền tạo/xóa database thử. Chạy `php tests/admin-auth.php`. Test tạo database tên ngẫu nhiên và xóa đúng database đó khi kết thúc.
- Đã kiểm tra tích hợp trên MariaDB 10.4 đi kèm XAMPP. Chưa kiểm tra trên MySQL của hosting.

## Module loại sản phẩm — đợt 2

- Public: `GET /api/v1/product-types`, `GET /api/v1/product-types/:slug`, thêm `?lang=en` để lấy bản English đủ điều kiện xuất bản.
- Admin: `GET /api/v1/admin/product-types`, `POST /api/v1/admin/product-types`, cùng payload và response `{data: ...}` với bản Node. Yêu cầu phiên admin; POST kiểm tra header và Origin như login.
- Có tạo/sửa, kiểm phiên bản để chống ghi đè, lưu trữ/khôi phục, chống trùng slug và giữ alias khi đổi đường dẫn. Loại đang được bất kỳ sản phẩm nào tham chiếu không thể lưu trữ. Public chỉ trả loại ACTIVE và không trả trường quản trị `english`/`version`.
- Giữ hash nguồn bản dịch tương thích Node; thay đổi tên/mô tả sẽ chuyển English đã xuất bản sang NEEDS_REVIEW. Công cụ biên tập/dịch riêng chưa chuyển; không tự xuất bản bản dịch thiếu sourceHash.
- Schema `002-product-types.sql` có sẵn bảng sản phẩm phục vụ kiểm tra tham chiếu; **chưa có API sản phẩm/giá/tồn**. Khi chuyển thao tác ghi sản phẩm phải lấy khóa `jingo_catalog_locks.id=catalog` trước để đồng bộ với việc lưu trữ loại.
- Chạy `php tests/product-types.php` với cấu hình database thử như test admin. Cần extension `mbstring` để kiểm tra độ dài Unicode.
- `APP_NAME=JinMemo` trong `.env` điều khiển tên ứng dụng ở health API. Tên bảng/cookie kỹ thuật vẫn giữ tiền tố `jingo`. Logo, email và các thông tin thương hiệu khác sẽ được gắn cấu hình khi chuyển các module tương ứng.

## Module điểm đến — đợt 3

- Public: `GET /api/v1/destinations`, `GET /api/v1/destinations/:slug`, hỗ trợ `?lang=en` và `localePaths` Việt/English.
- Admin: `GET /api/v1/admin/destinations`, `POST /api/v1/admin/destinations`. Cần phiên admin; POST kiểm header và Origin. Payload giữ schema bản Node.
- Có cây cha–con, tọa độ, thứ tự, tạo/sửa, lưu trữ/khôi phục, kiểm phiên bản, alias slug cũ cho cả hai ngôn ngữ và audit trong transaction.
- Chặn vòng lặp, cấp cha không tồn tại, quá 10 cấp, con ACTIVE dưới cha ARCHIVED và lưu trữ điểm đến có sản phẩm tham chiếu. Public ẩn cả nhánh có cha bị lưu trữ hoặc dữ liệu cây không hợp lệ.
- `coordinates`/`cover` không gửi lên được giữ nguyên khi sửa; gửi `null` để xóa. Ảnh bìa phải tham chiếu ID tồn tại trong thư viện media. Chưa có giao diện quản trị điểm đến PHP.
- `sortOrder` được ưu tiên; tên đồng thứ tự hiện dùng so sánh tự nhiên PHP, chưa có collator tiếng Việt như bản Node. Nên đặt sortOrder riêng khi cần thứ tự chính xác.
- Chạy lại `php bin/install.php` hoặc import thêm `database/003-destinations.sql`. Kiểm thử `php tests/destinations.php` và `php tests/http.php` trên server thử riêng như hướng dẫn ở trên.

## Module thư viện ảnh — đợt 4

- Cài schema `004-media.sql` (hoặc chạy lại installer). Bật PHP `gd` có JPEG/PNG/WebP, `fileinfo`, `mbstring`; khuyến nghị `exif` để tự xoay ảnh JPEG từ điện thoại. `upload_max_filesize` tối thiểu `5M`, `post_max_size` tối thiểu `8M`, bộ nhớ xử lý tùy kích thước ảnh (256M trở lên thuận tiện hơn).
- `GET /api/v1/admin/media`: 100 ảnh mới nhất, không trả người upload. `POST` cùng URL: multipart gồm một `file` và `alt` tối đa 300 ký tự; cần phiên admin, header và Origin hợp lệ.
- Kiểm nội dung thực, nhận JPG/PNG/WebP tối đa 5 MB và 25 triệu pixel; GD giải mã, xoay theo EXIF khi có extension, resize trong 1920×1920 không phóng lớn rồi xuất WebP quality 82. Ảnh quá lớn so với bộ nhớ host bị từ chối trước khi giải mã. Không giữ nguyên metadata/EXIF hay nội dung file upload.
- Public `GET /api/v1/media/:id` trả WebP; `/api/v1/media/:id/share.jpg` trả JPEG 1200×630 có nền. File tên UUID, lưu ngoài web root tại `storage/media`; file chia sẻ tạo một lần, cache immutable. Chỉ nội dung ảnh đã được admin đưa vào thư viện là công khai. PDF vé không đi qua module này.
- Dùng khóa file để giới hạn một upload đang tối ưu và một lần tạo ảnh chia sẻ trên mỗi thư mục storage. Thư mục cần quyền ghi của PHP và cần được sao lưu cùng database. Có thể cấu hình `MEDIA_DIR` thành đường dẫn tuyệt đối ngoài web root.
- Đã nối kiểm tra ID ảnh với điểm đến. Chưa có giao diện chọn/upload ảnh PHP hoặc công cụ nhập ảnh từ bản Node. GD có thể cho hình ảnh/nén khác Sharp; animation không được giữ.
- Kiểm tra HTTP với `php -d extension=gd tests/http.php` (nếu php.ini đã bật GD, dùng `php tests/http.php`; test khởi động PHP server riêng). Cần `curl` và quyền tạo database thử. Test bao gồm upload thật, ảnh giả, resize, tải WebP/JPEG và lưu ảnh bìa điểm đến.

## Module nhà cung cấp — đợt 5

- Cài thêm `005-suppliers.sql` hoặc chạy lại `php bin/install.php`.
- `GET /api/v1/admin/suppliers`: danh sách gồm `productCount` tính từ sản phẩm hiện có, cả sản phẩm chưa công khai. `POST` cùng URL: tạo/sửa, lưu trữ/khôi phục, cùng payload bản Node. Mọi API đều cần phiên admin; POST cần header và Origin hợp lệ. Không có danh sách nhà cung cấp công khai.
- Thông tin: tên, người liên hệ, điện thoại, email, website HTTP/HTTPS và ghi chú. `version` chống ghi đè; audit ghi trong cùng transaction. `productCount` chỉ là kết quả đọc, không gửi lại trường này trong payload lưu.
- Chặn lưu trữ nếu còn sản phẩm liên kết hoặc lô nguồn vé đang bật. Các module ghi sản phẩm/lô nguồn vé sau này cần lấy khóa chung `jingo_catalog_locks.id=catalog` trước khi kiểm tra và ghi để không đua với thao tác lưu trữ nhà cung cấp.
- Schema đã chuẩn bị bảng `jingo_supply_lots` để kiểm tra tham chiếu, chưa có API nhập lô, phân bổ nguồn vé, giá vốn, thanh toán hoặc đối soát nhà cung cấp. Chưa có giao diện nhà cung cấp PHP. Danh sách hiện sắp xếp tên tự nhiên PHP, chưa dùng collator tiếng Việt của Node.
- Test: `php tests/suppliers.php` với database thử riêng như trên; bộ HTTP cũng kiểm tra quyền, tạo, danh sách và xung đột của nhà cung cấp.

## Module trang nội dung — đợt 6

- Cài `006-pages.sql` hoặc chạy lại installer. Cần extension `dom` và `mbstring`.
- Admin `GET/POST /api/v1/admin/pages`: tạo/sửa trang thường hoặc Liên hệ, trạng thái DRAFT/PUBLISHED/TRASH, menu/footer, thứ tự, SEO, ảnh bìa và nội dung English. Cần phiên admin; POST cần header và Origin. Payload lưu không chứa `updatedAt` là trường kết quả đọc.
- Public `GET /api/v1/site/pages`, `/site/pages/:slug`, `/site/contact`, `/site/navigation` (đều dưới `/api/v1`). `?lang=en` chỉ trả bản English đã xuất bản khi trang gốc PUBLISHED. Giữ localePaths và alias Việt/English sau khi đổi slug.
- HTML được lọc qua DOM theo danh sách thẻ cho phép; bỏ script, iframe, SVG/MathML, thuộc tính sự kiện và URL không an toàn. Ảnh chỉ dùng URL `/api/v1/media/:uuid` và ID phải có trong thư viện. Bộ lọc PHP có thể chuẩn hóa markup khác thư viện sanitize-html của Node.
- Chống ghi đè bằng version, kiểm slug trùng/alias và audit cùng transaction. Chuyển TRASH rồi khôi phục DRAFT không tự xuất bản. Nội dung rỗng sau lọc không được xuất bản; English xuất bản cần tiêu đề/nội dung.
- Liên hệ dùng ID cố định `00000000-0000-4000-8000-000000000001`, kind CONTACT; không cho đưa vào thùng rác. Map URL chỉ nhận HTTPS. Schema chưa tự thêm nội dung liên hệ mẫu hoặc nhập dữ liệu hiện tại; admin có thể tạo bản ghi bằng API. Không có form gửi yêu cầu liên hệ trong module này.
- Chưa có giao diện biên tập/render trang PHP, công cụ chuyển dữ liệu, sitemap hay metadata giao diện. Danh sách cùng sortOrder dùng so sánh tự nhiên PHP thay vì collator tiếng Việt.
- Test `php tests/pages.php` và bộ HTTP trên database thử riêng như trên. POST trang cho phép JSON tối đa 1 MB để chứa HTML hai ngôn ngữ; các API JSON còn lại vẫn giới hạn 64 KB.

## Module yêu cầu liên hệ — đợt 7

- Cài `007-contact-requests.sql` hoặc chạy lại installer.
- Public `POST /api/v1/site/contact/requests`: JSON gồm UUID `id`, `name` (2–100 ký tự), `email`, `phone`, `subject` (3–180), `message` (10–5000), `locale` vi/en, `website` tùy chọn (honeypot, bình thường để rỗng). Cần header `X-Jingo-Contact: 1` và Origin bắt buộc khớp `PUBLIC_BASE_URL`; không cần đăng nhập.
- Chỉ nhận khi trang Liên hệ có ID cố định đã PUBLISHED; locale en cần bản English đã xuất bản. Honeypot có giá trị trả `{ok:true}` nhưng không lưu. Không gửi email hoặc dữ liệu ra dịch vụ ngoài.
- Idempotency theo UUID: cùng mã/nội dung trả thành công, khác nội dung trả 409. Sau khi trang ngừng xuất bản, gửi lại cũng bị từ chối như bản Node.
- Tối đa 3 yêu cầu/email và 100 toàn website trong 15 phút. Bản PHP lưu bộ đếm trong MySQL thay vì RAM để dùng chung giữa các tiến trình/requests, tự dọn bộ đếm hết hạn khi có yêu cầu mới. Gửi lại bản ghi đã có không tốn lượt; yêu cầu bị từ chối không tăng quota. Khóa catalog chung đồng bộ với việc thay đổi trạng thái trang.
- Admin `GET /api/v1/admin/contact-requests?status=NEW` (NEW/DONE/TRASH): tối đa 100 bản ghi mới nhất, có thông tin khách. `POST /api/v1/admin/contact-requests/:id` với `{ "status": "DONE" }` để chuyển trạng thái hoặc khôi phục NEW. Cần phiên admin; POST cần header/Origin, ghi audit cùng transaction. Không xóa hẳn bản ghi và không công khai danh sách khách.
- Chưa có giao diện biểu mẫu/hộp thư PHP, email thông báo, chống spam dịch vụ ngoài hoặc nhập dữ liệu bản Node.
- Bộ `php -d extension=gd tests/http.php` kiểm tra quyền, Origin, dữ liệu, honeypot, gửi lặp/xung đột, quota email/toàn website/hết hạn, xuất bản và cập nhật trạng thái. Dùng server database thử riêng như trên.
