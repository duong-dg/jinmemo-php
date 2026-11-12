# JinMemo — PHP + MySQL cho hosting

Đây là bản chuyển đổi riêng. Hiện có trang chủ thông tin, trang nội dung và Liên hệ công khai; giao diện quản trị PHP cùng các API danh mục, điểm đến, ảnh, nhà cung cấp, nội dung, liên hệ, SEO/thương hiệu và lõi tính giá. Chưa có luồng bán vé hoặc công cụ chuyển dữ liệu; chưa thay thế hệ thống hiện tại.

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
- Schema `002-product-types.sql` tạo bảng sản phẩm; API sản phẩm đã có ở đợt 11 và preview giá ở đợt 9, tồn vé chưa chuyển. Ghi sản phẩm lấy khóa `jingo_catalog_locks.id=catalog` để đồng bộ với lưu trữ loại/điểm đến/nhà cung cấp.
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
- API và giao diện tạo/sửa sản phẩm đã có ở đợt 11. Chưa có calculator tương tác hoặc public `/api/v1/quotes`: còn chờ kiểm tồn, voucher và luồng đặt vé. Preview không giữ tồn, không phát hành vé, không kiểm/cộng giảm voucher và không có giá trị xác nhận đơn.
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
- Homepage hiển thị sản phẩm PUBLISHED. `/ve/:slug`, `/en/ve/:slug` dựng chi tiết, ảnh, gói/nhóm khách, điều kiện và suất giờ. Slug cũ chuyển 308; nháp/lưu trữ/English chưa duyệt trả 404. Giá là tham khảo theo giá nền/ưu đãi, chưa tính theo ngày khách chọn hoặc kiểm tồn. Chỉ có CTA tư vấn khi trang Liên hệ được xuất bản, chưa có nút mua/thanh toán.
- Bản dịch sản phẩm theo sourceHash tương thích Node; sửa nội dung nguồn đổi PUBLISHED thành NEEDS_REVIEW. API chấp nhận schema English, giao diện hiện giữ bản dịch đã có nhưng chưa biên tập/xuất bản dịch mới. Không dịch tự động. Sửa giá/lịch không tự làm bản dịch nội dung hết hiệu lực.
- Không cần migration SQL mới nếu đã cài `001`–`008`. Upload code, views và toàn bộ assets mới (đặc biệt `products-admin.js`), giữ `.env` và `storage`. Chưa có duplicate sản phẩm, import dữ liệu, tồn, voucher, báo giá công khai theo ngày, giỏ hàng hoặc đơn hàng.
- Test: `php tests/products.php` đối chiếu 25 fixture schema/hash từ Node và test database riêng; bộ HTTP kiểm quyền, lưu/xung đột, public projection/giá, chi tiết, homepage, alias, calendar và archive. `php tests/pricing.php` vẫn đối chiếu 49 bộ giá. Đã thử trình duyệt tạo loại, tạo/xuất bản sản phẩm, giá 0 địa phương, thêm suất/lịch, xem trang chi tiết và bố cục 390px.
