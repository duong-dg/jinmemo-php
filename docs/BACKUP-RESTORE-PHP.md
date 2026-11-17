# Sao lưu và phục hồi PHP trên cPanel

Đợt 112, schema vẫn 052. Admin → Cấu hình website → Sao lưu & phục hồi.

## Phân biệt file

| File | Dùng cho |
| --- | --- |
| `jinmemo-php-database-YYYY-MM-DD.sql` | Khôi phục database PHP vào một database mới, trống. Có cấu trúc `jingo_*` và dữ liệu vận hành |
| `jinmemo-backup-...tar.gpg` | Bản sao mã hóa do tác vụ ngoài ứng dụng tạo; phải dùng khóa và quy trình của chính hệ thống tạo archive |
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

**Chưa chuyển trong đợt này:** bộ tạo archive GPG/lịch cron/retention và quy trình phục hồi toàn bộ stack Ubuntu của bản Node sang cPanel. Không tự tạo setup.json để giả rằng đã cài lịch. Không có tự upload SQL/giải nén/ghi đè dữ liệu bằng HTTP. Bản tar.gpg của Node không tự trở thành bản PHP hoặc được chuyển sang MySQL chỉ vì được liệt kê ở đây.

## Kiểm thử

`php tests/backups.php` với JINGO_TEST_DSN server thử riêng, không dbname: metadata/private whitelist, kích thước/SHA-256/restore marker, đường dẫn riêng, export rồi import toàn bộ cấu trúc, Unicode/emoji/numeric/binary/bit/null/time precision/cột sinh, bỏ phiên, giới hạn dung lượng, khóa đồng thời, rollback/audit, quyền HTTP/CSRF/cookie và header tải file.

`node tests/backups-ui.cjs`: review chưa tạo export, chống bấm lặp, kiểm định dạng response, giữ private-entry header, dọn blob và bỏ phản hồi cũ sau khi rời trang. Đã xem renderer thật bằng fixture mô phỏng tại desktop/mobile; không tải SQL hoặc archive sản xuất.
