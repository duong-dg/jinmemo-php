# Sao lưu và phục hồi PHP trên cPanel

Cập nhật đợt 122, schema vẫn 052. Admin → Cấu hình website → Sao lưu & phục hồi.

## Phân biệt file

| File | Dùng cho |
| --- | --- |
| `jinmemo-php-database-YYYY-MM-DD.sql` | Khôi phục database PHP vào một database mới, trống. Có cấu trúc `jingo_*` và dữ liệu vận hành |
| `jinmemo-backup-...tar.gpg` | Archive mã hóa; xem nhãn chỉ database PHP hoặc tác vụ ngoài. Dùng khóa và quy trình của chính hệ thống tạo archive |
| SQL chứa `jm_transfer_*` từ bản Node | Dữ liệu staging để importer chuyển sang PHP theo TRANSFER-CONTRACT.json; không phải database PHP chạy được |

## Xuất SQL database PHP

Chọn Chuẩn bị xuất SQL, đọc phạm vi rồi chọn Tải SQL database PHP. File chứa dữ liệu riêng của khách, hash mật khẩu và cấu hình đã mã hóa. Không chia sẻ công khai hoặc đặt file SQL dưới public. HTTP yêu cầu phiên admin, Origin/header mutation và trả no-store. Sự kiện xuất được ghi vào audit trước khi gửi file.

Bộ xuất dùng consistent snapshot REPEATABLE READ chỉ đọc, giữ nguyên tên/thứ tự cột và SHOW CREATE TABLE của mọi bảng `jingo_*` InnoDB trong database hiện tại. Không tính lại đơn, giá, tồn hoặc sổ thu. Các cột tự sinh giữ biểu thức trong DDL và được database phục hồi tính lại; cột thường được giữ chính xác gồm Unicode, NULL, binary/bit, BIGINT và DECIMAL, DATETIME(6). Bảng ngoài prefix không xuất. View hoặc bảng khác InnoDB làm dừng tác vụ. Không thay schema/DDL trong khi xuất.

Các bảng phiên đăng nhập admin/POS/CTV, OTP và giới hạn thử/đặt/liên hệ được tạo đủ cấu trúc nhưng để trống dữ liệu. Khôi phục xong cần đăng nhập lại. Mật khẩu PHP và chính sách bảo mật vẫn được giữ. Ảnh, file vé gốc trong MEDIA_DIR/.tickets, file `.smtp-key`, cấu hình `.env` và mã nguồn không nằm trong SQL: phải sao lưu riêng toàn bộ storage/MEDIA_DIR gồm file ẩn, cùng cấu hình và revision mã nguồn phù hợp.

Một lần xuất tối đa 512 MiB, ghi vào file tạm riêng ngoài public, không giữ toàn bộ bảng trong RAM. Khóa xuất dùng chung theo database; hai request không xuất đồng thời. File tạm đóng/xóa sau khi gửi hoặc gặp lỗi. Giới hạn thời gian PHP/web server, dung lượng tạm và giới hạn import của hosting vẫn áp dụng; database lớn nên dùng công cụ backup của hosting.

Đây là API mới phù hợp bản PHP: `POST /api/v1/admin/backups/database-export`. Không đặt tên `mysql-export` của nguồn Node vì định dạng đó là staging chuyển dữ liệu và loại bỏ auth/secrets; không thể thay thế hai loại file cho nhau.

## Thử phục hồi riêng

1. Tạo database MySQL mới, trống trong cPanel và cấp quyền cho tài khoản thử. Không chạy installer trước khi nhập SQL này vì file đã có CREATE TABLE.
2. Dùng phpMyAdmin/công cụ MySQL nhập SQL vào database mới. Không dùng tùy chọn tiếp tục sau lỗi hoặc nhập vào database vận hành có bảng/dữ liệu.
3. Chuẩn bị một bản mã PHP và storage/MEDIA_DIR riêng, gồm `.tickets` và `.smtp-key` đúng thời điểm sao lưu. Chỉnh `.env` của bản thử sang database/URL thử. Không kích hoạt cron/email/provider trong môi trường thử bằng tài khoản sản xuất.
4. Kiểm đăng nhập, số đơn/giỏ, tổng thu/hoàn, tồn, nội dung và file vé. Giữ nguyên file PDF gốc. Đối soát các đơn phát sinh sau thời điểm snapshot trước khi quyết định chuyển vận hành.
5. Chỉ chuyển domain/cấu hình sang bản phục hồi sau khi kiểm xong. Ứng dụng không có endpoint tự ghi đè database đang chạy.

Đợt này đã thử export/import database riêng trên MariaDB 10.4 local; chưa phục hồi dữ liệu thật của bạn hoặc chạy trên cPanel thật.

## Danh sách bản sao mã hóa

Thêm đường dẫn tuyệt đối, ngoài public vào `.env`, ví dụ:

```dotenv
BACKUP_DIR=/home/USER/private-backups
```

Không cấu hình thì mặc định dùng storage/backups; nếu chưa có thư mục/tác vụ, màn hình báo chưa xác nhận lịch. SQL export không phụ thuộc thư mục archive này.

Đọc metadata cùng hợp đồng nguồn: `<id>.json` và `<id>.tar.gpg` với id dạng `jinmemo-backup-YYYYMMDDTHHMMSSZ-8hex`. JSON có id, createdAt/verifiedAt (ISO UTC), bytes, sha256, revision và snapshot với orders/carts/products/payments/ticketFiles. Có thể thêm `<id>.restore-test.json` chứa cùng id/sha256 và testedAt; nếu không khớp sẽ không hiển thị ghi nhận phục hồi. Publisher nên ghi file tạm rồi đổi tên khi hoàn tất, giữ quyền ghi thư mục cho tác vụ tin cậy.

`setup.json` dùng schedule `03:15 Asia/Ho_Chi_Minh`, keep 1–90, installedAt UTC. `last-run.json` dùng state RUNNING/SUCCESS/FAILED, updatedAt, startedAt/phase nếu có. Chỉ các trường đã cho phép được trả về; đường dẫn/khóa/lý do lỗi nội bộ không lộ ra UI. Tối đa 90 metadata mới nhất, JSON tối đa 64 KiB; bản thiếu hoặc sai kích thước không hiển thị. Có nhắc khi bản mới nhất quá 36 giờ.

`GET /api/v1/admin/backups` và `GET /api/v1/admin/backups/:id/download` chỉ cho admin. File phải là file thường trong thư mục riêng; không nhận tên tùy ý, symlink hoặc đường dẫn thoát thư mục. Khi tải, kiểm lại SHA-256 trên chính file đang mở, rồi mới ghi audit và gửi bytes. SHA-256 xác minh file khớp metadata; ứng dụng không tự giải mã hoặc xác nhận nội dung archive đã phục hồi được. Marker thử phục hồi là ghi nhận từ tác vụ ngoài.

**Phần còn thiếu sau đợt 122:** tự cài/xác minh lịch, thử phục hồi trên cPanel thật và cấu hình tài khoản/SSL/cron/offsite. Lệnh database bên dưới chỉ sao lưu database; lệnh application bổ sung media/file vé/khóa/cấu hình/source Git, không phải bản sao nguyên tài khoản cPanel hoặc hệ điều hành Ubuntu. Không tự tạo setup.json để giả rằng đã cài lịch. Không có tự upload SQL/giải nén/ghi đè dữ liệu bằng HTTP. Bản tar.gpg của Node không tự trở thành bản PHP hoặc được chuyển sang MySQL chỉ vì được liệt kê ở đây.

## Kiểm thử

`php tests/backups.php` với JINGO_TEST_DSN server thử riêng, không dbname: metadata/private whitelist, kích thước/SHA-256/restore marker, đường dẫn riêng, export rồi import toàn bộ cấu trúc, Unicode/emoji/numeric/binary/bit/null/time precision/cột sinh, bỏ phiên, giới hạn dung lượng, khóa đồng thời, rollback/audit, quyền HTTP/CSRF/cookie và header tải file.

`node tests/backups-ui.cjs`: review chưa tạo export, chống bấm lặp, kiểm định dạng response, giữ private-entry header, dọn blob và bỏ phản hồi cũ sau khi rời trang. Đã xem renderer thật bằng fixture mô phỏng tại desktop/mobile; không tải SQL hoặc archive sản xuất.

## CLI sao lưu database mã hóa — đợt 121

Lệnh mới dành cho CLI, không có endpoint HTTP chạy mã hóa. Cần PHP CLI với pdo_mysql/Phar/proc_open và GnuPG 2 có loopback pinentry. Hosting không cho dùng GPG/proc_open thì dùng công cụ backup của hosting; không thay bằng SQL công khai. Đã thử GPG thật và phục hồi database local; chưa kiểm cPanel thật.

Cấu hình đường dẫn tuyệt đối trong .env (hoặc env của cron):

```dotenv
BACKUP_DIR=/home/USER/private-backups
BACKUP_GPG_BINARY=/usr/bin/gpg
BACKUP_GPG_HOME=/home/USER/private-gpg
BACKUP_PASSPHRASE_FILE=/home/USER/private-secrets/backup-passphrase
```

Tạo sẵn các thư mục riêng ngoài public, quyền 0700; file passphrase là file thường, 0600, một dòng ít nhất 32 byte. Nên tạo từ 32 byte ngẫu nhiên, không ghi passphrase vào .env, tham số lệnh hoặc cron log. Giữ một bản khóa an toàn ngoài hosting trước khi phụ thuộc vào archive; mất khóa sẽ không giải mã được. GPG home dành riêng cho tác vụ này. Không dùng symlink cho thư mục/file cấu hình. Trên Windows dùng GPG đi kèm Git có thể đặt BACKUP_GPG_PATH_STYLE=CYGWIN; cPanel không cần tùy chọn này.

Chạy thử từ thư mục mã PHP đã clone Git:

```bash
cd /home/USER/jinmemo.cloud
/usr/local/bin/php bin/database-backup.php --revision="$(git rev-parse HEAD)" --keep=7
```

Chỉnh đúng đường dẫn PHP CLI của hosting. Revision phải là commit 40–64 hex của mã tương ứng. Lệnh không tự cài cron hay tạo setup.json. Sau khi chạy thử và giữ khóa ngoài hosting, có thể đặt chính lệnh này vào Cron Jobs của cPanel; chọn giờ theo múi giờ server. Nếu server UTC, 20:15 UTC tương ứng 03:15 hôm sau ở Việt Nam. Màn hình chưa xác nhận lịch chỉ có nghĩa chưa có metadata xác nhận cài lịch; kiểm last-run và lịch cPanel thực tế.

Tác vụ xuất consistent SQL bằng DatabaseExport (vẫn bỏ dữ liệu phiên/OTP/giới hạn), đóng thành tar gồm đúng database.sql và manifest.json, mã hóa AES256, giải mã lại và so SHA-256 tar trước khi publish. Manifest có scope PHP_DATABASE_ONLY, hash SQL, số dòng bảng và revision. Admin hiển thị nhãn chỉ database rõ ràng, không gồm ảnh/file vé, .smtp-key, .env hoặc code. Snapshot ticketFiles luôn 0 vì file vé không nằm trong archive. payments là số dòng jingo_order_payments, không phải doanh thu.

Khóa file ngăn chạy chồng cùng thư mục; export còn dùng khóa database hiện có. Chỉ giữ 1–90 archive do CLI này tạo và xác minh metadata/hash trước khi xóa bản cũ. Không xóa archive ngoài ứng dụng, archive bị sửa SHA-256 hoặc metadata hỏng; do đó có thể còn nhiều file hơn keep nếu có file không xác minh được. Bản mới luôn được giữ, kể cả khi lỗi retention sau publish. last-run chỉ ghi trạng thái/phase, không ghi khóa, đường dẫn hoặc stderr GPG; preflight cấu hình không đạt có thể dừng trước khi cập nhật last-run.

SQL/tar giải mã tạm nằm trong thư mục .work riêng 0700, được dọn khi hoàn tất hoặc lỗi có xử lý. Dừng tiến trình đột ngột/mất điện có thể để lại .work; chỉ dọn khi chắc chắn không có tác vụ chạy. Cần đủ quota cho SQL, tar, ciphertext và bản giải mã đối chiếu (có thể nhiều lần kích thước SQL); giới hạn SQL vẫn 512 MiB.

Phục hồi: chép archive và passphrase sang máy tin cậy/thư mục riêng, dùng GPG giải mã, kiểm manifest.databaseSha256 của database.sql rồi làm quy trình nhập vào database mới trống ở trên. Sau đó vẫn phải phục hồi riêng media/khóa/.env/code đúng thời điểm. verifiedAt chỉ xác nhận archive giải mã khớp byte, không phải chứng nhận đã thử phục hồi trên hosting; CLI không tạo restore-test.json giả.

Kiểm thử mới: php tests/database-backup.php với JINGO_TEST_DSN server thử riêng và JINGO_TEST_GPG đường dẫn GPG. Có mã hóa/giải mã thật, SQL import vào database riêng, manifest, scope, CLI/env, khóa chạy trùng, retention giữ bản ngoài/tampered và lỗi mã hóa không công bố file chưa kiểm.

## Snapshot ứng dụng PHP — đợt 122

`bin/application-backup.php` tạo scope PHP_APPLICATION, khác PHP_DATABASE_ONLY. Dùng cùng cấu hình GPG/thư mục riêng ở trên, thêm:

```dotenv
BACKUP_COORDINATION_DIR=/home/USER/private-backup-coordination
BACKUP_GIT_BINARY=/usr/bin/git
```

Tạo coordination directory trước với quyền 0700, ngoài public, source và media. PHP web và PHP CLI phải cùng tài khoản hoặc có quyền mở file khóa 0600; không mở rộng thành 777. BACKUP_COORDINATION_DIR phải được lưu thật trong .env với cùng đường dẫn tuyệt đối, không chỉ đặt cho cron. Không để môi trường web/worker ghi đè sang thư mục khác. Triển khai mã mới, làm mới PHP/worker cũ và kiểm một request bình thường hoạt động trước khi dùng tác vụ này. Khi không cấu hình biến này, các request hiện có hoạt động như trước.

Chỉ chạy khi đã dừng nguồn ghi SQL/FTP/import/deploy ngoài ứng dụng và thống nhất DB/MEDIA/config giữa web và CLI. Gate chỉ phối hợp các kết nối qua bootstrap PHP, không khóa phpMyAdmin, FTP, ứng dụng khác hoặc tiến trình chạy mã cũ. Ví dụ chạy thủ công trong giờ bảo trì:

```bash
cd /home/USER/jinmemo.cloud
/usr/local/bin/php bin/application-backup.php --maintenance --revision="$(git rev-parse HEAD)" --keep=7
```

Không tự cài cron hoặc thay cấu hình website khi git pull. Cờ --maintenance là xác nhận chạy cửa sổ chụp; sau khi operator đã thử thành công mới cân nhắc lịch ở giờ ít truy cập. Tác vụ chờ tối đa 30 giây cho request/worker cũ kết thúc. Trong lúc chụp, request cần database trả HTTP 503 + Retry-After: 30, worker mới cũng bị chặn; ảnh/CSS được phục vụ trực tiếp có thể vẫn tải được. Cửa sổ chụp gồm SQL, quét/đối chiếu media và Git/config; có thể kéo dài theo dung lượng và tốc độ hosting. Gate được nhả trước khi đóng gói ngoài/mã hóa GPG, hoặc khi gặp lỗi, không giữ website chờ toàn bộ bước mã hóa.

Phải có Git repository sạch, HEAD khớp revision, .env là file thường và MEDIA_DIR riêng ngoài public. Bản sao code là Git archive theo commit, không gồm .git/history, không tự lưu sửa đổi chưa commit hoặc file code untracked. Text được xuất với LF để không phụ thuộc autocrlf của máy chạy. Nếu có sửa riêng trên host, phải rà soát/commit hoặc dùng backup hosting phù hợp trước, không bỏ qua kiểm tra sạch.

Archive ngoài chứa đúng database.sql, manifest.json, media.tar, source.tar, environment.env và effective-config.json. Media gồm file ẩn, khóa .smtp-key và file vé gốc trong .tickets; không phân tích hay đổi PDF. Mỗi file media và các phần archive có SHA-256/bytes trong manifest; đọc lại archive media và quét lại nguồn để phát hiện thay đổi. .smtp-key phải có sẵn đúng 32 byte của ứng dụng; không tạo khóa mới để thay khóa cũ đã mất. Mật khẩu GPG/GPG home/backup directory/coordination phải tách khỏi source và media, để không tự đưa archive hoặc khóa giải mã vào bản sao. Symlink/file đặc biệt và tên file không hỗ trợ làm dừng tác vụ, không âm thầm bỏ qua. Giới hạn hiện tại: SQL 512 MiB, media 50.000 file/2 GiB; cần đủ quota cho các bản SQL/tar/mã hóa/giải mã tạm.

Snapshot DB vẫn loại phiên đăng nhập/OTP/rate limit như DatabaseExport. SQL và media ổn định khi mọi nguồn ghi tuân thủ gate và các nguồn ngoài đã dừng. environment.env là file cấu hình trên đĩa; effective-config.json là cấu hình CLI đã dùng, cả hai đều riêng tư trong archive mã hóa. Marker verifiedAt chỉ xác nhận GPG giải mã khớp byte, không tự tuyên bố đã thử vận hành cPanel. Admin phân biệt bản ứng dụng PHP/bản chỉ DB/bản từ tác vụ ngoài. Retention tính riêng mỗi scope, không để giữ một bản DB làm xóa bản ứng dụng.

### Thử phục hồi độc lập

1. Đem archive và mật khẩu GPG đến máy/thư mục riêng tin cậy, giải mã và kiểm SHA-256 từng phần theo manifest; kiểm danh sách đường dẫn trước khi giải nén archive của chính mình. Không giải nén vào public hoặc thư mục vận hành.
2. Tạo database mới trống và nhập database.sql; không chạy installer trước. Các phiên cũ không được phục hồi.
3. Đặt source Git từ source.tar vào một thư mục triển khai mới; media.tar vào MEDIA_DIR mới ngoài public, gồm .tickets và .smtp-key nguyên gốc. Giữ quyền thư mục riêng 0700 và khóa 0600; quyền code/public chỉnh theo PHP/web server của môi trường thử.
4. Dùng environment.env/effective-config.json để đối chiếu, không chép nguyên cấu hình sản xuất rồi mở website. Chỉnh DB/URL/MEDIA/coordination/backup paths riêng, APP_ENV development, SEO false; tắt cron, gửi email và provider LIVE trong môi trường thử trước khi khởi động ứng dụng. Không dùng khóa GPG hoặc thư mục backup sản xuất cho môi trường thử.
5. Kiểm dữ liệu, tổng thu/hoàn/tồn, tra cứu/file vé gốc và khả năng giải mã cấu hình bằng khóa đã phục hồi; đối soát phát sinh từ lúc snapshot trước khi chuyển domain. Tài khoản cPanel, document root, SSL, lịch cron và nơi giữ bản offsite phải cấu hình riêng. Chưa có endpoint/script tự ghi đè hệ thống đang chạy.

Đã thử archive mã hóa thật, SQL import vào database riêng và giải mã dữ liệu bằng .smtp-key lấy lại từ media, PDF nguyên byte, hash/source revision, HTTP/worker drain/503, lỗi preflight/thiếu khóa/source bẩn/đường dẫn chồng và retention theo scope. Chưa thử tài khoản cPanel thật hoặc tự động khôi phục vào hệ thống vận hành. Lệnh kiểm thử: php tests/application-backup.php với JINGO_TEST_DSN, JINGO_TEST_GPG và JINGO_TEST_GIT trỏ công cụ local.
