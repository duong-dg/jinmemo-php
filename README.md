# JinMemo — PHP + MySQL cho hosting

Đây là bản chuyển đổi riêng. Hiện có trang chủ, sản phẩm, nội dung và Liên hệ công khai; giao diện quản trị cùng các API danh mục, tồn, voucher, tính giá và tạo/tra cứu đơn WEB. Đã nối giao diện đặt/tra cứu đơn; đã có ghi nhận thu tiền thủ công; thanh toán trực tuyến, giao vé và công cụ chuyển dữ liệu chưa hoàn tất; chưa thay thế hệ thống hiện tại. Xem [kế hoạch chuyển dữ liệu cũ](docs/MIGRATION.md).

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
- **Chưa chuyển:** gửi/xác minh OTP, thay đổi chính sách qua OTP, tra vị trí IP, giao diện và quyền POS/CTV. Giao diện admin chủ đã có tại `/admin`. Khi policy bật OTP, login PHP trả 503 và không tạo phiên. Không dùng bản này thay thế quản trị đang bật OTP.
- Hash scrypt của Node chưa được hỗ trợ. Khi chuyển dữ liệu phải tạo lại mật khẩu admin bằng cơ chế PHP; không nhập nguyên hash cũ rồi kỳ vọng đăng nhập được. Không nhập phiên Node đang hoạt động.
- Kiểm thử: `JINGO_TEST_DSN` trỏ server MySQL/MariaDB thử riêng, không chứa dbname; `JINGO_TEST_USER` và `JINGO_TEST_PASSWORD` cấp tài khoản có quyền tạo/xóa database thử. Chạy `php tests/admin-auth.php`. Test tạo database tên ngẫu nhiên và xóa đúng database đó khi kết thúc.
- Đã kiểm tra tích hợp trên MariaDB 10.4 đi kèm XAMPP. Chưa kiểm tra trên MySQL của hosting.

## Module loại sản phẩm — đợt 2

- Public: `GET /api/v1/product-types`, `GET /api/v1/product-types/:slug`, thêm `?lang=en` để lấy bản English đủ điều kiện xuất bản.
- Admin: `GET /api/v1/admin/product-types`, `POST /api/v1/admin/product-types`, cùng payload và response `{data: ...}` với bản Node. Yêu cầu phiên admin; POST kiểm tra header và Origin như login.
- Có tạo/sửa, kiểm phiên bản để chống ghi đè, lưu trữ/khôi phục, chống trùng slug và giữ alias khi đổi đường dẫn. Loại đang được bất kỳ sản phẩm nào tham chiếu không thể lưu trữ. Public chỉ trả loại ACTIVE và không trả trường quản trị `english`/`version`.
- Giữ hash nguồn bản dịch tương thích Node; thay đổi tên/mô tả sẽ chuyển English đã xuất bản sang NEEDS_REVIEW. Công cụ biên tập/dịch riêng chưa chuyển; không tự xuất bản bản dịch thiếu sourceHash.
- Schema `002-product-types.sql` tạo bảng sản phẩm; API sản phẩm đã có ở đợt 11, tồn/báo giá khách có ở đợt 12. Ghi sản phẩm lấy khóa `jingo_catalog_locks.id=catalog` để đồng bộ với lưu trữ loại/điểm đến/nhà cung cấp.
- Chạy `php tests/product-types.php` với cấu hình database thử như test admin. Cần extension `mbstring` để kiểm tra độ dài Unicode.
- `APP_NAME=JinMemo` trong `.env` là tên mặc định khi chưa lưu thương hiệu, cũng là tên chẩn đoán ở health API. Tên bảng/cookie kỹ thuật vẫn giữ tiền tố `jingo`. Tên và logo trên giao diện quản trị ưu tiên cấu hình thương hiệu trong database (xem đợt 8).

## Module điểm đến — đợt 3

- Public: `GET /api/v1/destinations`, `GET /api/v1/destinations/:slug`, hỗ trợ `?lang=en` và `localePaths` Việt/English.
- Admin: `GET /api/v1/admin/destinations`, `POST /api/v1/admin/destinations`. Cần phiên admin; POST kiểm header và Origin. Payload giữ schema bản Node.
- Có cây cha–con, tọa độ, thứ tự, tạo/sửa, lưu trữ/khôi phục, kiểm phiên bản, alias slug cũ cho cả hai ngôn ngữ và audit trong transaction.
- Chặn vòng lặp, cấp cha không tồn tại, quá 10 cấp, con ACTIVE dưới cha ARCHIVED và lưu trữ điểm đến có sản phẩm tham chiếu. Public ẩn cả nhánh có cha bị lưu trữ hoặc dữ liệu cây không hợp lệ.
- `coordinates`/`cover` không gửi lên được giữ nguyên khi sửa; gửi `null` để xóa. Ảnh bìa phải tham chiếu ID tồn tại trong thư viện media. Giao diện quản trị điểm đến đã có.
- `sortOrder` được ưu tiên; tên đồng thứ tự hiện dùng so sánh tự nhiên PHP, chưa có collator tiếng Việt như bản Node. Nên đặt sortOrder riêng khi cần thứ tự chính xác.
- Chạy lại `php bin/install.php` hoặc import thêm `database/003-destinations.sql`. Kiểm thử `php tests/destinations.php` và `php tests/http.php` trên server thử riêng như hướng dẫn ở trên.

## Module thư viện ảnh — đợt 4

- Cài schema `004-media.sql` (hoặc chạy lại installer). Bật PHP `gd` có JPEG/PNG/WebP, `fileinfo`, `mbstring`; khuyến nghị `exif` để tự xoay ảnh JPEG từ điện thoại. `upload_max_filesize` tối thiểu `5M`, `post_max_size` tối thiểu `8M`, bộ nhớ xử lý tùy kích thước ảnh (256M trở lên thuận tiện hơn).
- `GET /api/v1/admin/media`: 100 ảnh mới nhất, không trả người upload. `POST` cùng URL: multipart gồm một `file` và `alt` tối đa 300 ký tự; cần phiên admin, header và Origin hợp lệ.
- Kiểm nội dung thực, nhận JPG/PNG/WebP tối đa 5 MB và 25 triệu pixel; GD giải mã, xoay theo EXIF khi có extension, resize trong 1920×1920 không phóng lớn rồi xuất WebP quality 82. Ảnh quá lớn so với bộ nhớ host bị từ chối trước khi giải mã. Không giữ nguyên metadata/EXIF hay nội dung file upload.
- Public `GET /api/v1/media/:id` trả WebP; `/api/v1/media/:id/share.jpg` trả JPEG 1200×630 có nền. File tên UUID, lưu ngoài web root tại `storage/media`; file chia sẻ tạo một lần, cache immutable. Chỉ nội dung ảnh đã được admin đưa vào thư viện là công khai. PDF vé không đi qua module này.
- Dùng khóa file để giới hạn một upload đang tối ưu và một lần tạo ảnh chia sẻ trên mỗi thư mục storage. Thư mục cần quyền ghi của PHP và cần được sao lưu cùng database. Có thể cấu hình `MEDIA_DIR` thành đường dẫn tuyệt đối ngoài web root.
- Đã có giao diện upload/chọn ảnh và nối kiểm tra ID ảnh với điểm đến. Chưa có công cụ nhập ảnh từ bản Node. GD có thể cho hình ảnh/nén khác Sharp; animation không được giữ.
- Kiểm tra HTTP với `php -d extension=gd tests/http.php` (nếu php.ini đã bật GD, dùng `php tests/http.php`; test khởi động PHP server riêng). Cần `curl` và quyền tạo database thử. Test bao gồm upload thật, ảnh giả, resize, tải WebP/JPEG và lưu ảnh bìa điểm đến.

## Module nhà cung cấp — đợt 5

- Cài thêm `005-suppliers.sql` hoặc chạy lại `php bin/install.php`.
- `GET /api/v1/admin/suppliers`: danh sách gồm `productCount` tính từ sản phẩm hiện có, cả sản phẩm chưa công khai. `POST` cùng URL: tạo/sửa, lưu trữ/khôi phục, cùng payload bản Node. Mọi API đều cần phiên admin; POST cần header và Origin hợp lệ. Không có danh sách nhà cung cấp công khai.
- Thông tin: tên, người liên hệ, điện thoại, email, website HTTP/HTTPS và ghi chú. `version` chống ghi đè; audit ghi trong cùng transaction. `productCount` chỉ là kết quả đọc, không gửi lại trường này trong payload lưu.
- Chặn lưu trữ nếu còn sản phẩm liên kết hoặc lô nguồn vé đang bật. Các module ghi sản phẩm/lô nguồn vé sau này cần lấy khóa chung `jingo_catalog_locks.id=catalog` trước khi kiểm tra và ghi để không đua với thao tác lưu trữ nhà cung cấp.
- Schema đã chuẩn bị bảng `jingo_supply_lots` để kiểm tra tham chiếu, chưa có API nhập lô, phân bổ nguồn vé, giá vốn, thanh toán hoặc đối soát nhà cung cấp. Đã có giao diện nhà cung cấp PHP. Danh sách hiện sắp xếp tên tự nhiên PHP, chưa dùng collator tiếng Việt của Node.
- Test: `php tests/suppliers.php` với database thử riêng như trên; bộ HTTP cũng kiểm tra quyền, tạo, danh sách và xung đột của nhà cung cấp.

## Module trang nội dung — đợt 6

- Cài `006-pages.sql` hoặc chạy lại installer. Cần extension `dom` và `mbstring`.
- Admin `GET/POST /api/v1/admin/pages`: tạo/sửa trang thường hoặc Liên hệ, trạng thái DRAFT/PUBLISHED/TRASH, menu/footer, thứ tự, SEO, ảnh bìa và nội dung English. Cần phiên admin; POST cần header và Origin. Payload lưu không chứa `updatedAt` là trường kết quả đọc.
- Public `GET /api/v1/site/pages`, `/site/pages/:slug`, `/site/contact`, `/site/navigation` (đều dưới `/api/v1`). `?lang=en` chỉ trả bản English đã xuất bản khi trang gốc PUBLISHED. Giữ localePaths và alias Việt/English sau khi đổi slug.
- HTML được lọc qua DOM theo danh sách thẻ cho phép; bỏ script, iframe, SVG/MathML, thuộc tính sự kiện và URL không an toàn. Ảnh chỉ dùng URL `/api/v1/media/:uuid` và ID phải có trong thư viện. Bộ lọc PHP có thể chuẩn hóa markup khác thư viện sanitize-html của Node.
- Chống ghi đè bằng version, kiểm slug trùng/alias và audit cùng transaction. Chuyển TRASH rồi khôi phục DRAFT không tự xuất bản. Nội dung rỗng sau lọc không được xuất bản; English xuất bản cần tiêu đề/nội dung.
- Liên hệ dùng ID cố định `00000000-0000-4000-8000-000000000001`, kind CONTACT; không cho đưa vào thùng rác. Map URL chỉ nhận HTTPS. Schema chưa tự thêm nội dung liên hệ mẫu hoặc nhập dữ liệu hiện tại; admin có thể tạo bản ghi bằng API. Không có form gửi yêu cầu liên hệ trong module này.
- Đã có biên tập cơ bản bằng ô nội dung HTML và trang công khai PHP với metadata (đợt 10). Chưa có editor trực quan, công cụ chuyển dữ liệu hoặc sitemap. Danh sách cùng sortOrder dùng so sánh tự nhiên PHP thay vì collator tiếng Việt.
- Test `php tests/pages.php` và bộ HTTP trên database thử riêng như trên. POST trang cho phép JSON tối đa 1 MB để chứa HTML hai ngôn ngữ; các API JSON còn lại vẫn giới hạn 64 KB.

## Module yêu cầu liên hệ — đợt 7

- Cài `007-contact-requests.sql` hoặc chạy lại installer.
- Public `POST /api/v1/site/contact/requests`: JSON gồm UUID `id`, `name` (2–100 ký tự), `email`, `phone`, `subject` (3–180), `message` (10–5000), `locale` vi/en, `website` tùy chọn (honeypot, bình thường để rỗng). Cần header `X-Jingo-Contact: 1` và Origin bắt buộc khớp `PUBLIC_BASE_URL`; không cần đăng nhập.
- Chỉ nhận khi trang Liên hệ có ID cố định đã PUBLISHED; locale en cần bản English đã xuất bản. Honeypot có giá trị trả `{ok:true}` nhưng không lưu. Không gửi email hoặc dữ liệu ra dịch vụ ngoài.
- Idempotency theo UUID: cùng mã/nội dung trả thành công, khác nội dung trả 409. Sau khi trang ngừng xuất bản, gửi lại cũng bị từ chối như bản Node.
- Tối đa 3 yêu cầu/email và 100 toàn website trong 15 phút. Bản PHP lưu bộ đếm trong MySQL thay vì RAM để dùng chung giữa các tiến trình/requests, tự dọn bộ đếm hết hạn khi có yêu cầu mới. Gửi lại bản ghi đã có không tốn lượt; yêu cầu bị từ chối không tăng quota. Khóa catalog chung đồng bộ với việc thay đổi trạng thái trang.
- Admin `GET /api/v1/admin/contact-requests?status=NEW` (NEW/DONE/TRASH): tối đa 100 bản ghi mới nhất, có thông tin khách. `POST /api/v1/admin/contact-requests/:id` với `{ "status": "DONE" }` để chuyển trạng thái hoặc khôi phục NEW. Cần phiên admin; POST cần header/Origin, ghi audit cùng transaction. Không xóa hẳn bản ghi và không công khai danh sách khách.
- Đã có hộp thư admin và biểu mẫu liên hệ công khai PHP (đợt 10). Chưa có email thông báo, chống spam dịch vụ ngoài hoặc nhập dữ liệu bản Node.
- Bộ `php -d extension=gd tests/http.php` kiểm tra quyền, Origin, dữ liệu, honeypot, gửi lặp/xung đột, quota email/toàn website/hết hạn, xuất bản và cập nhật trạng thái. Dùng server database thử riêng như trên.

## Đăng nhập và giao diện quản trị

1. Đặt document root vào `public`, bật rewrite và cấu hình `.env` theo hướng dẫn cài ở đầu tài liệu. `PUBLIC_BASE_URL` phải đúng origin truy cập; dùng HTTPS khi APP_ENV=production.
2. Chạy `php bin/install.php`, rồi `php bin/create-admin.php email-cua-ban@example.com` và nhập mật khẩu 12–72 byte. Không có tài khoản hoặc mật khẩu mặc định. Tài khoản Node cũ cần tạo lại mật khẩu khi chuyển.
3. Mở `https://ten-mien-cua-ban/admin`, đăng nhập bằng tài khoản vừa tạo. `/` hiện mở trang thông tin công khai. Nếu dùng policy đường dẫn riêng, truy cập `/<entrySlug>` để form truyền đúng mã đường dẫn.
4. Sau đăng nhập có tổng quan, loại sản phẩm, điểm đến, thư viện ảnh, nhà cung cấp, trang nội dung, hộp thư liên hệ, SEO và thương hiệu. Form hỗ trợ tạo/sửa, chọn trạng thái, chọn ảnh; trang Liên hệ có nút tạo riêng khi chưa có. Menu không hiển thị module bán vé chưa chuyển.

Trang quản trị dùng HTML/CSS/JS thuần, không cần Node/npm trên hosting. Cookie HttpOnly do API cấp, không lưu token trong localStorage. Đăng xuất thu hồi phiên ở database. Trang HTML không chứa dữ liệu quản trị; API kiểm tra quyền trước khi trả dữ liệu. CSP hạn chế tài nguyên cùng origin, chống nhúng iframe; brand được escape khi hiển thị HTML.

Giới hạn: chưa có OTP, tài khoản POS/CTV, sửa bản dịch loại sản phẩm, editor HTML trực quan hoặc nghiệp vụ bán vé. Bản dịch loại hiện có được giữ khi sửa thông tin gốc. Không tự import nội dung/dữ liệu từ hệ thống Node.

Đã thử trình duyệt: sai/đúng mật khẩu, tạo loại sản phẩm, tạo điểm đến, tạo trang Liên hệ, tải lại giữ phiên và đăng xuất. Đã xem bố cục ở 1366px và 390px. Bộ HTTP kiểm tra HTML/assets, đăng nhập thực/cookie/thu hồi phiên và các API hiện có trên database thử riêng.

## SEO và thương hiệu — đợt 8

- Chạy lại `php bin/install.php` hoặc import `database/008-site-settings.sql` trước khi dùng hai mục mới trong admin. Bảng `jingo_settings` lưu từng cấu hình với version riêng.
- SEO: admin `GET/POST /api/v1/admin/site-seo` giữ hợp đồng bản Node (trả object trực tiếp). Payload `{version,vi:{title,description},en:{title,description},image:null|{id,alt}}`. Tiêu đề tối đa 200, mô tả tối đa 500 ký tự, cả hai ngôn ngữ bắt buộc có nội dung. Public `GET /api/v1/site/seo` trả `{data:...}` không chứa version. Ảnh dùng ID trong thư viện.
- Thương hiệu: admin `GET/POST /api/v1/admin/brand`; payload `{version,name,companyName,email,phone,address,logo:null|{id,alt}}`. Tên bắt buộc tối đa 100 ký tự; thông tin liên hệ là nội dung công khai. Public `GET /api/v1/site/brand` trả cấu hình không có version. API này được bổ sung riêng cho bản PHP để hỗ trợ đóng gói đổi thương hiệu.
- Cả hai mục đều cần phiên admin khi đọc quản trị, header/Origin khi lưu; kiểm version chống ghi đè, kiểm ảnh và audit trong transaction. GET chưa có bản ghi trả cấu hình mặc định với version 0; lần lưu đầu tạo version 1.
- Tên và logo thương hiệu được áp dụng lên trang đăng nhập/quản trị, cả sau tải lại. Đổi tên không tự thay nội dung SEO, trang Liên hệ, SMTP, domain, callback thanh toán hay tên bảng/cookie. Logo lấy từ thư viện ảnh; favicon và tên gửi email chưa nối cấu hình.
- Giao diện admin có **SEO website** và **Thương hiệu**, chỉnh bằng biểu mẫu, chọn ảnh từ thư viện. Trang thông tin công khai đã dùng SEO, canonical/hreflang (đợt 10); robots.txt và sitemap chưa chuyển. Website bán vé chưa hoạt động.
- Đã thử trình duyệt lưu SEO, đổi tên thương hiệu và kiểm tra tên trên màn hình đăng nhập sau tải lại. Bộ HTTP kiểm tra default, quyền, lưu/xung đột, kiểm dữ liệu/ảnh, public projection, escape tên trong HTML và logo.

## Lõi tính giá — đợt 9

- `src/Pricing.php` chuyển các hàm tính giá cơ sở, kiểm lịch giờ và ưu đãi online từ `pricing.ts`, `schedule.ts`, `online-discount.ts`. Đây là lõi nghiệp vụ cho module sản phẩm/đặt vé tiếp theo, chưa phải luồng mua vé hoàn chỉnh.
- Giữ 6 nhóm khách du khách/địa phương, giá miễn phí 0đ, lịch giá theo ngày/thứ và ưu tiên, báo lỗi khi hai lịch có cùng ưu tiên cao nhất. Không tự lấy giá người lớn thay cho nhóm chưa được cấu hình.
- Kiểm ngày lịch thực, khóa bán theo gói hoặc toàn sản phẩm, biên ngày bao gồm ngày đầu/cuối, 0–50 khách/nhóm, tối đa 150 khách/đơn và giới hạn số tiền tương thích số nguyên an toàn JavaScript.
- Suất giờ dùng UTC+7 cố định. Khi đã tới đúng giờ bắt đầu hoặc quá giờ, từ chối báo giá; yêu cầu chọn suất khi có cấu hình. Kết quả giữ snapshot suất và requiresSelection.
- Ưu đãi online theo phần trăm (làm tròn xuống mỗi vé) hoặc số tiền, ưu tiên cấu hình riêng của gói. Giữ giá gốc, số giảm/khách và tổng giảm; không cộng voucher khi thực sự có ưu đãi online. Hàm publicVariants giữ cả giá gốc và giá online.
- API mới `POST /api/v1/admin/pricing-preview`, cần phiên admin và header/Origin. Payload `{productId,variantId,date,quantities:{ADULT:2},slotId?}`. Server lấy sản phẩm và lịch giá từ database, không nhận giá do client gửi. Cho phép xem trước cả sản phẩm nháp. Response có `preview:true`, `inventoryChecked:false`, `voucherChecked:false`.
- API và giao diện tạo/sửa sản phẩm đã có ở đợt 11. Calculator/public quotes có ở đợt 12 và hỗ trợ voucher từ đợt 13; đặt vé còn chờ. Preview admin không giữ tồn, không phát hành vé, không kiểm/cộng giảm voucher và không có giá trị xác nhận đơn.
- Chạy `php tests/pricing.php` không cần database. File `tests/fixtures/pricing-node.json` chứa 49 kết quả/lỗi chạy từ lõi Node hiện tại, cùng thời gian kiểm tra cố định: giá gốc/địa phương, lịch ưu tiên/xung đột, ngày/thứ, miễn phí, số khách, tràn tiền, suất giờ và ưu đãi online. Các test bổ sung kiểm giá public, chặn cộng voucher và tính độc lập múi giờ host. Bộ HTTP kiểm quyền preview, giá server, payload lỗi và không có public quote chưa kiểm tồn.

## Trang công khai và biểu mẫu Liên hệ — đợt 10

- `/` và `/en` là trang công khai, hiển thị các trang và sản phẩm đã xuất bản (sản phẩm thêm ở đợt 11). Không còn tự chuyển khách vào admin. Chưa có đặt vé hoặc thanh toán. `/admin` vẫn là quản trị.
- Các URL `/trang/:slug`, `/en/pages/:slug`, `/lien-he`, `/en/contact` dựng HTML bằng PHP. Chỉ trả nội dung PUBLISHED; English cần published riêng. Slug cũ và dấu `/` cuối chuyển hướng 308 về URL chính; trang nháp, thùng rác hoặc bản dịch chưa xuất bản trả HTML 404.
- Giữ bố cục/màu sắc trang nội dung của bản Next và dùng lại CSS content-page. Menu/footer lấy từ cấu hình trang; logo/tên lấy từ thương hiệu. Chuyển ngôn ngữ chỉ hiện khi trang có bản tương ứng. HTML nội dung được lọc lại trước khi hiển thị; dữ liệu còn lại escape trong template.
- Có title/description, canonical, hreflang, Open Graph và ảnh chia sẻ từ thư viện. `SEO_INDEXING=false` mặc định đặt noindex; chỉ bật true khi website/domain/nội dung sẵn sàng. Chưa có robots.txt/sitemap hoặc dữ liệu có cấu trúc đầy đủ. URL chuẩn lấy từ `PUBLIC_BASE_URL`, không lấy Host người gửi.
- Biểu mẫu Liên hệ gửi API hiện có, có trạng thái gửi/thành công/lỗi, honeypot và mã idempotency giữ lại khi thử lại nội dung giống nhau. Không gửi email hoặc xác nhận đặt vé. Lời nhắn được đọc tại admin → Yêu cầu liên hệ. JavaScript cần HTTPS (hoặc localhost) cho UUID; hosting production vốn yêu cầu HTTPS.
- Cách dùng: admin → Trang nội dung → Tạo trang Liên hệ nếu chưa có → nhập nội dung/liên hệ → chọn PUBLISHED → lưu. Chọn hiện menu/footer nếu muốn. Có nút **Xem trang** cạnh trang đã xuất bản. Tạo trang thường và bản English tương tự. Không tự thêm thông tin mẫu hoặc liên hệ giả khi cài hosting.
- Khi cập nhật: upload cả `src`, `views`, các file mới trong `public/assets` và `public/index.php`; giữ `.env`/`storage` hiện tại. Không có migration SQL mới trong đợt này; vẫn nên chạy installer nếu bỏ qua các đợt trước.
- Đã kiểm thử HTTP cho root công khai, ngôn ngữ, canonical/alias, nội dung an toàn, ẩn nháp và biểu mẫu liên hệ; thử trình duyệt gửi lời nhắn rồi đọc đúng nội dung trong admin. Đã kiểm bố cục trang English ở 390px không tràn ngang. Dữ liệu ví dụ/chụp ảnh chỉ nằm trong database thử riêng.

## Sản phẩm, gói giá và danh mục công khai — đợt 11

- Admin có mục **Sản phẩm**: tạo/sửa vé/tour, loại sản phẩm, nhãn hoặc liên kết điểm đến, nhà cung cấp, mô tả/HTML chi tiết, SEO, ảnh bìa và tối đa 20 ảnh bổ sung. Cần tạo loại sản phẩm ACTIVE trước. Chọn điểm đến sẽ lấy tên chuẩn từ danh mục; server kiểm cả chuỗi cha đều ACTIVE.
- Có tối đa 30 gói, 6 nhóm khách mỗi gói, điều kiện áp dụng và tối đa 30 suất giờ. Bật nhóm và nhập 0 là miễn phí; bỏ chọn là không áp dụng. Có ưu đãi chung hoặc riêng gói NONE/PERCENT/AMOUNT. Giá cấu hình tối đa 100 triệu VND mỗi khách.
- Biểu mẫu có lịch giá theo gói/nhóm/ngày/thứ/ưu tiên và khoảng khóa bán toàn sản phẩm hoặc riêng gói. Server chặn mã gói/suất/lịch trùng, lịch tham chiếu sai, giá cho nhóm chưa bật, khoảng ngày sai, giờ kết thúc không sau giờ bắt đầu và lịch giá cùng ưu tiên chồng phạm vi. Sửa mã/bỏ gói phải sửa các lịch đang tham chiếu gói đó trước khi lưu.
- Admin `GET/POST /api/v1/admin/products`, POST JSON tối đa 1 MB; save cần phiên admin, header/Origin và version. `POST /api/v1/admin/products/:id/archive` với `{version,archived}` lưu trữ hoặc khôi phục về DRAFT. Không xóa dữ liệu đơn/vé. Audit và kiểm tham chiếu/alias nằm trong transaction với khóa catalog chung.
- Public `GET /api/v1/products` và `/products/:slug`, hỗ trợ `lang=en`. Có filter type ID, destination ID (gồm con), q tìm tên/nhãn điểm đến không dấu. Tìm theo tên các cấp cha như bản Node chưa nối. `/products/:id/sale-calendar` trả suất/khóa bán không có lý do nội bộ. Không trả supplierId, rules, english hoặc version; giá public có originalPrices/giá online.
- Homepage hiển thị sản phẩm PUBLISHED. `/ve/:slug`, `/en/ve/:slug` dựng chi tiết, ảnh, gói/nhóm khách, điều kiện và suất giờ. Slug cũ chuyển 308; nháp/lưu trữ/English chưa duyệt trả 404. Bảng giá nền là tham khảo; biểu mẫu kiểm tra theo ngày/suất/số khách và tồn được bổ sung ở đợt 12. Chưa có nút mua/thanh toán.
- Bản dịch sản phẩm theo sourceHash tương thích Node; sửa nội dung nguồn đổi PUBLISHED thành NEEDS_REVIEW. API chấp nhận schema English, giao diện hiện giữ bản dịch đã có nhưng chưa biên tập/xuất bản dịch mới. Không dịch tự động. Sửa giá/lịch không tự làm bản dịch nội dung hết hiệu lực.
- Riêng đợt sản phẩm không có migration mới; tồn/báo giá cần `009-inventory.sql`, voucher cần `010-vouchers.sql`. Upload code, views và toàn bộ assets mới, giữ `.env` và `storage`. Chưa có duplicate sản phẩm, import dữ liệu, giỏ hàng hoặc đơn hàng.
- Test: `php tests/products.php` đối chiếu 25 fixture schema/hash từ Node và test database riêng; bộ HTTP kiểm quyền, lưu/xung đột, public projection/giá, chi tiết, homepage, alias, calendar và archive. `php tests/pricing.php` vẫn đối chiếu 49 bộ giá. Đã thử trình duyệt tạo loại, tạo/xuất bản sản phẩm, giá 0 địa phương, thêm suất/lịch, xem trang chi tiết và bố cục 390px.

## Tồn vé và kiểm tra giá theo ngày — đợt 12

- **Cập nhật database:** chạy `php bin/install.php` hoặc import `database/009-inventory.sql` qua phpMyAdmin. Bổ sung bảng cấu hình, sức chứa và phân bổ tồn. Upload toàn bộ code/assets/views mới, giữ `.env` và `storage`. CSS/JS có tham số phiên bản theo mtime để trình duyệt tải bản mới sau cập nhật.
- Admin có mục **Tồn vé**: chọn sản phẩm, khoảng ngày tối đa 367 ngày; đặt tổng sức chứa theo gói, cả ngày hoặc từng suất, các thứ áp dụng và ngưỡng cảnh báo. Đây là tổng sức chứa, không phải số chỗ cộng thêm. Bật giới hạn sẽ chặn các ngày chưa có sức chứa. Ngày/suất có cả hai giới hạn dùng mức còn lại nhỏ nhất. Mọi nhóm khách, kể cả vé miễn phí, đều chiếm một suất.
- API quản trị giữ đường dẫn Node: `GET /api/v1/admin/inventory?productId=...&from=...&to=...`, `POST /inventory/config` với `{productId,version,enabled}`, `POST /inventory/capacity` với `{productId,version,variantId,slotId?,startDate,endDate,weekdays?,capacity,lowStockThreshold?}` dưới `/api/v1/admin`. Cần phiên admin, header/Origin khi ghi. Có version chống ghi đè, khóa sản phẩm, kiểm không hạ tồn dưới số đã phân bổ và audit cùng transaction.
- Public `POST /api/v1/quotes` nhận `{productId,variantId,date,quantities,slotId?,voucherCode?}`; trả giá theo lịch/ưu đãi/voucher, tên bản dịch nếu được xuất bản, tổng tiền và `inventory`. Chỉ sản phẩm PUBLISHED được báo giá. Voucher được nối từ đợt 13 và không cộng dồn ưu đãi online.
- `GET /api/v1/products/:slug/prices?variantId=...&date=...&slotId=...` trả giá đơn vị theo nhóm và tồn. Ngày khóa bán, suất quá giờ, nhóm không áp dụng hoặc vượt 150 khách bị chặn theo lõi giá. Yêu cầu vượt số còn lại/chưa mở tồn trả 409. Không nhận giá hoặc tổng tiền do client tự đặt.
- Trang sản phẩm có biểu mẫu chọn ngày, suất, số khách và voucher, hiển thị tổng giá/số suất sau khi kiểm tra server; thay lựa chọn xóa kết quả cũ. Kết quả không giữ chỗ, tạo đơn hoặc xác nhận mua. Chưa có giỏ/đặt vé/thanh toán.
- `Inventory::allocate/release` là hook nội bộ cho module đơn hàng tương lai, bắt buộc caller transaction; phân bổ khóa dòng sản phẩm rồi kiểm tồn hiện tại và ghi, giải phóng lặp không cộng tồn hai lần. Chưa có API tạo phân bổ thủ công và bảng allocations chưa gắn FK đơn vì module đơn hàng chưa chuyển. Không tự nhập hoặc phân loại tồn/đơn cũ.
- Báo cáo admin hiện có tổng/đã phân bổ/còn lại/cảnh báo. Chưa có thông tin mã đơn/trạng thái thanh toán hoặc màn hình phân loại đơn cũ; response đánh dấu `orderDetailsAvailable:false`, `pending:null`, `legacy:[]` thay vì giả số đơn chờ thu.
- Test `php tests/inventory.php` trên database thử: dùng chung ngày/suất, legacy bảo thủ, vé miễn phí, không giữ tồn khi báo giá, rollback khi giảm tồn sai, allocate/release và **hai tiến trình tranh suất cuối chỉ một được phân bổ**. Bộ HTTP kiểm quyền, capacity/version, quote theo ngày, vượt tồn, ngày chưa mở, không nhận voucher và prices API. Các bài này chạy trên MariaDB local; cần kiểm lại MySQL/hosting thực khi triển khai.
- Đã mở màn hình tồn và biểu mẫu giá trên trình duyệt. Kiểm thử thao tác xác nhận bị hạn chế do hộp thoại cũ của môi trường tự động hóa; API/concurrency đã qua. Xác nhận bật/tắt hiện dùng nút trong trang; admin chỉ khởi động sau khi các script module tải xong.

## Voucher và mã giảm giá — đợt 13

- **Cập nhật:** chạy lại `php bin/install.php` hoặc import `database/010-vouchers.sql`. Upload cả `vouchers-admin.js`, `quote.js` và views/code mới, giữ `.env`/`storage`. Tạo hai bảng voucher/lượt dùng, không thay đổi dữ liệu sản phẩm/tồn hiện tại.
- Admin → **Mã giảm giá** có tạo/sửa, bật/tắt, phần trăm hoặc số tiền, giá trị đơn tối thiểu, mức giảm tối đa và tổng lượt dùng. Mã 3–40 ký tự không dấu, tự chuẩn hóa chữ hoa; không đổi mã sau khi tạo. Có hiệu lực theo ngày đặt tính tại Việt Nam, giới hạn ngày sử dụng/từng thứ, phạm vi ALL/PRODUCTS/VARIANTS và tối đa 200 mục áp dụng.
- `GET/POST /api/v1/admin/vouchers` cần phiên admin; POST kiểm header/Origin và version. Kiểm sản phẩm/gói tồn tại, phạm vi không trùng/không rỗng khi riêng, tối đa 100% và không hạ giới hạn lượt dưới số đã dùng. Ghi audit cùng transaction; số lượt tính từ bản ghi chưa released. Không có API liệt kê voucher công khai.
- Trang sản phẩm có ô nhập mã; quotes trả `discount:{code,amount,subtotal}` và tổng sau giảm. Kiểm tồn vẫn dùng toàn bộ số khách, bao gồm khách miễn phí. Kiểm phạm vi, ngày sử dụng, thứ, thời hạn theo ngày đặt, minSubtotal, trần giảm và lượt còn lại. Không dùng voucher cho đơn miễn phí, không giảm dưới 0 và không cộng với ưu đãi online khi đã có số giảm thực tế.
- Báo giá không ghi lượt dùng. `allocate/release` là hook cho module đơn hàng tương lai, cần transaction; giữ khóa voucher và kiểm lại quota bằng current read để tránh vượt lượt khi nhiều tiến trình cùng đặt. Lặp allocate cùng đơn/voucher đang dùng không cộng hai lượt; release lặp không trả hai lần. Caller tương lai phải gọi discount với khóa để kiểm điều kiện/giá trong cùng transaction trước khi allocate. Bảng lượt dùng chưa gắn FK đơn vì module đơn hàng chưa chuyển.
- Chưa có checkout/đơn hàng/thanh toán, vì vậy admin response đánh dấu `checkoutEnabled:false` dù báo giá có thể áp dụng mã. Chưa có import voucher/lượt dùng từ bản Node, mã chung giỏ nhiều gói hoặc gửi mã qua email. Không tự tạo chương trình mẫu khi cài hosting.
- Test `php tests/vouchers.php` trên database thử: 52 fixture phạm vi/ngày từ helper Node, chuẩn hóa, schema, trần giảm, thời hạn, phiên bản, giới hạn lượt, allocate/release, chống cộng dồn và hai tiến trình tranh lượt cuối chỉ một thành công. Bộ HTTP kiểm quyền, lưu/xung đột, mã bất biến, áp dụng vào quote, tắt mã và báo giá không tiêu lượt.
- Đã thử trình duyệt tạo voucher theo sản phẩm và áp dụng mã 10%: 450.000đ → 405.000đ; kiểm bố cục 390px không tràn ngang. Dữ liệu/ảnh thử chỉ ở database local riêng.

## Tạo và tra cứu đơn WEB — đợt 14

- Chạy lại `php bin/install.php` hoặc import thêm `database/011-orders.sql`. Giữ `.env`, database và `storage` khi cập nhật code. Migration chỉ tạo bảng mới, không tự chuyển đơn từ bản Node.
- `POST /api/v1/orders`: nhận `requestId` UUID, `accessKey` 64 ký tự hex do trình duyệt sinh bằng bộ sinh ngẫu nhiên bảo mật, productId, variantId, date, quantities, expectedTotal, contact, accepted=true; tùy chọn slotId/voucherCode/locale. Contact có name/email/phone/note và invoice tùy chọn. Server tự tính giá, kiểm sản phẩm xuất bản, suất, tồn và voucher; không nhận giá do khách tự quyết định.
- Public POST yêu cầu `Origin` đúng `PUBLIC_BASE_URL`, `X-Jingo-Order: 1` và JSON. Giới hạn theo IP kết nối trực tiếp: tạo 30/phút, tra cứu 240/phút; không tin header IP do khách gửi. Hosting sau proxy dùng chung IP sẽ dùng chung quota, cần cấu hình riêng khi triển khai thực tế.
- Giá thay đổi trả 409. Tạo đơn, snapshot giá/điều kiện, phân bổ tồn và lượt voucher cùng transaction. Mặc định CONFIRMED/UNPAID như luồng WEB bản Node. Cùng requestId/nội dung trả lại cùng đơn; khác nội dung trả 409. Access key chỉ lưu hash; không đưa key lên URL query hoặc log.
- `POST /api/v1/orders/lookup` nhận code và accessKey hoặc email. Nếu có accessKey sai, không dùng email để bỏ qua. Không trả contact, thông tin hóa đơn hay hash nội bộ. `GET /api/v1/admin/orders/:id` cần phiên admin và trả thêm contact.
- Đợt này là API nền: **chưa có nút đặt đơn, trang tra cứu, danh sách/xử lý đơn trong admin, hủy/thu tiền, email xác nhận, giỏ hàng hoặc giao file vé**. Không có email nào được gửi khi tạo đơn. `checkoutEnabled:false` ở API voucher vẫn chỉ tình trạng giao diện checkout chưa bật. Các ghi chú “chưa có đơn” trong đợt trước được thay thế bởi phạm vi API nêu ở đây.
- Kiểm thử: `php tests/orders.php` trên database thử kiểm giá, chống lặp, tra cứu riêng tư, quota voucher, rollback khi hết tồn, snapshot và hai tiến trình tranh suất cuối/gửi lại cùng yêu cầu. `php tests/http.php` kiểm Origin/header, phương thức, quyền admin, tra cứu và giới hạn tần suất. Đã chạy trên PHP 8.2/MariaDB 10.4 local; chưa thử trên hosting thật.

## Giao diện đặt đơn và tra cứu — đợt 15

- Trang sản phẩm có biểu mẫu liên hệ và xác nhận đặt đơn sau khi kiểm tra giá thành công. Chỉ sản phẩm không phải demo mới có nút đặt. Đổi ngày, gói/số khách hoặc mã giảm làm kết quả báo giá hết hiệu lực; cần kiểm tra lại trước khi đặt.
- Khách nhập tên, email, điện thoại, ghi chú và xác nhận đã xem điều kiện/ngày/số khách/tổng tiền. Tạo đơn sẽ phân bổ tồn và lượt voucher qua API đợt 14. Thông báo ghi rõ đơn chưa thu tiền, chưa gửi email và việc giao vé được xử lý riêng.
- Trình duyệt sinh requestId và accessKey bằng crypto; giữ nguyên payload khi lỗi mạng/5xx/429. Yêu cầu đang chờ được giữ trong sessionStorage của tab để tải lại và thử lại cùng đơn. Xóa bản chờ sau thành công hoặc lỗi từ chối xác định. Nếu trình duyệt chặn sessionStorage thì chỉ hỗ trợ thử lại khi trang còn mở. Không tự gửi lại khi tải trang.
- Thành công hiện mã đơn và liên kết xem đơn dùng fragment; trang tra cứu xóa fragment khỏi history hiện tại trước khi gọi API. Không đưa accessKey vào query URL. Khách cũng có thể mở `/tra-cuu` hoặc `/en/orders`, nhập mã đơn và email. Footer có liên kết tra cứu; trang này luôn noindex. Chi tiết giữ tên/giá/điều kiện theo snapshot lúc đặt, dù đổi ngôn ngữ giao diện.
- Admin voucher API hiện trả `checkoutEnabled:true`. Chưa có danh sách/xử lý đơn trong giao diện admin, thanh toán online, email, hóa đơn trong biểu mẫu, giỏ nhiều gói hay giao file PDF. API invoice đã có từ đợt 14 nhưng biểu mẫu đợt này chỉ nhận thông tin liên hệ cơ bản.
- Cập nhật toàn bộ code/views/assets bằng Git; không có migration mới đợt này. Database cần có `011-orders.sql` từ đợt trước. Giữ `.env`/`storage`; dùng HTTPS và PUBLIC_BASE_URL đúng domain.
- Kiểm thử HTTP: biểu mẫu sản phẩm, trang tra cứu VI/EN, canonical slash, noindex, asset, quyền API và tạo/tra cứu đơn. Kiểm thử trình duyệt trên database riêng: đặt thành công, liên kết riêng bỏ fragment, email sai/đúng, tiếng Anh, chiều rộng 390px không tràn; tắt máy chủ thử lỗi mạng, khởi động lại/tải trang/thử lại và tra cứu đơn phục hồi. Bộ orders kiểm tra chống lặp và hai tiến trình tranh suất cuối vẫn qua.

## Quản lý đơn trong admin — đợt 16

- **Cập nhật:** chạy `php bin/install.php` hoặc import thêm `database/012-order-actions.sql`. Migration tạo bảng lịch sử xử lý gắn với đơn; không sửa giá/snapshot hay tự chuyển dữ liệu cũ. Upload cả `orders-admin.js` cùng views/assets/code bằng Git. Giữ `.env`, database và `storage`.
- Admin → **Đơn hàng** có danh sách phân trang, tìm theo mã/tên/email/điện thoại và lọc trạng thái đơn/thanh toán. Thống kê theo bộ lọc: tổng đơn, đơn chưa thu chưa hủy, đơn hủy và giá trị đơn PAID. Đây chưa phải báo cáo đối soát dòng tiền; chưa có module thu tiền.
- Chi tiết hiển thị snapshot gói, ngày/suất, số khách, giá/điều kiện, voucher, liên hệ, ghi chú và hóa đơn nếu có. Có hướng dẫn hiện tại và lịch sử người xử lý/lý do/nội dung/thời gian, sắp theo phiên bản đơn. Không trả access hash/request hash qua API quản trị hoặc công khai.
- Có cập nhật hướng dẫn cho đơn CONFIRMED và hủy đơn chưa thu tiền. Bắt buộc lý do và bước xác nhận trong trang; không dùng hộp thoại confirm của trình duyệt. Hướng dẫn và trạng thái hủy được phản ánh khi khách tra cứu. Không gửi email khi xử lý.
- `GET /api/v1/admin/orders` hỗ trợ q (120 ký tự), booking ALL/REQUESTED/CONFIRMED/CANCELLED, payment ALL/UNPAID/PAID/CREDIT, page và pageSize 1–100. Trả data/page/pageSize/summary; tìm chuỗi con, ký tự % và _ không được coi là wildcard. Tổng và danh sách đọc trong một transaction.
- `POST /api/v1/admin/orders/:id/actions` nhận requestId UUID, version, action CANCEL/INSTRUCTIONS, note 1–1000 ký tự; INSTRUCTIONS thêm instructions 1–4000 ký tự. Cần phiên admin và kiểm Origin/header như các mutation khác. Khóa đơn, kiểm version; lặp đúng requestId/nội dung không ghi thêm lịch sử. Khác nội dung hoặc phiên bản cũ trả 409.
- Hủy chỉ nhận đơn UNPAID chưa hủy. Trả tồn và lượt voucher, tăng version, ghi trạng thái và lịch sử trong cùng transaction; lỗi bất kỳ hoàn tác tất cả. Không thể hủy trực tiếp đơn PAID/CREDIT. Chưa có hoàn tiền, xóa/lưu trữ/khôi phục, thu tiền, POS/CTV hay giao file vé.
- Kiểm thử `tests/orders.php`: tìm/lọc/phân trang, quyền riêng tư, cập nhật hướng dẫn, idempotency/version, hủy/trả tồn/voucher, chặn hủy đơn đã thu, ép lỗi ghi lịch sử để kiểm rollback, hai tiến trình cùng thao tác/gửi lại. `tests/http.php` kiểm quyền, Origin/header, schema, hành động, bộ lọc và asset. Trình duyệt local đã thử tìm email/lọc đơn hủy, lưu hướng dẫn, xem lịch sử và xác nhận hủy; chi tiết 390px không tràn ngang. Chưa kiểm trên hosting thật.

## Ghi nhận thu tiền đơn WEB — đợt 17

- **Cập nhật:** chạy `php bin/install.php` hoặc import thêm `database/013-order-payments.sql`. Tạo bảng mã giao dịch dùng chung và khoản thu của đơn. Không ghi nhận tiền hay thay đổi trạng thái đơn tự động khi nâng cấp. Giữ `.env`, database và `storage`.
- Admin → Đơn hàng → Xem đơn → **Ghi nhận đã thu tiền**. Chọn Chuyển khoản hoặc Tiền mặt, xem đúng số tiền tổng đơn, nhập mã giao dịch ngân hàng và ghi chú, rồi xác nhận lại số tiền/hình thức/mã đơn. Chỉ dùng sau khi đã kiểm tra tiền thực nhận. Đây là ghi sổ thủ công; không gọi ngân hàng, chuyển tiền hay kiểm chứng giao dịch tự động.
- `PAY` trong `POST /api/v1/admin/orders/:id/actions` cần requestId/version/note, amount nguyên 0–15 tỷ đúng snapshot.total, reference 1–200 ký tự, method BANK/CASH (mặc định BANK). Reference ngân hàng bỏ khoảng trắng đầu/cuối và chuẩn hóa chữ hoa, không nhận ký tự điều khiển; tiền tố CASH: dành riêng cho phiếu tiền mặt. Tiền mặt tự tạo reference CASH:requestId, trường reference đầu vào vẫn cần chuỗi không rỗng để tương thích action schema.
- Chỉ thu cho đơn CONFIRMED/UNPAID. Khóa đơn và kiểm version, mã giao dịch duy nhất toàn hệ thống (lưu khóa SHA-256 của reference đã chuẩn hóa), mỗi đơn một khoản thu đủ tổng tiền. Ghi mã, khoản thu, trạng thái PAID/version và lịch sử cùng transaction. Gửi lại cùng action không tạo khoản thu mới; lỗi ghi lịch sử hoàn tác cả mã giao dịch/khoản thu/trạng thái.
- Chi tiết admin hiện hình thức/số tiền/reference/người xử lý/thời gian. Khách tra cứu chỉ thấy trạng thái đã thanh toán, không nhận mã giao dịch, lịch sử nội bộ hay thông tin admin. Không trả tồn/voucher khi ghi nhận thu. Đơn PAID không thể hủy trực tiếp; không có nút sửa/xóa khoản thu trong đợt này.
- Chưa có thanh toán online/webhook, hoàn tiền, thu thiếu/thừa, đặt cọc/trả nhiều lần, báo cáo đối soát hoặc công nợ CTV. Tổng “Đã thu” trong danh sách vẫn là tổng giá trị snapshot của các đơn PAID theo bộ lọc. Chưa nối email và gửi file vé/PDF.
- Kiểm thử `php tests/payments.php`: BANK/CASH/đơn miễn phí, đúng tổng, chuẩn hóa/trùng reference, replay, chặn hủy sau thu, quyền riêng tư, ép lỗi lịch sử kiểm rollback; hai tiến trình gửi lại cùng action, tranh cùng mã giao dịch ở hai đơn và thu đồng thời một đơn. Bộ orders và HTTP tiếp tục qua; HTTP kiểm quyền/mutation, số tiền, khoản thu, replay và trạng thái khách/bộ lọc. Trình duyệt thử chọn tiền mặt/chuyển khoản, bước xác nhận, lưu khoản thu giả lập, chi tiết PAID không có nút thu/hủy, thống kê và bố cục 390px. Chỉ kiểm trên database local riêng, chưa thử hosting thật.
