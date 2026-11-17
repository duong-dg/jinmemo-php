# Kiểm file chuyển dữ liệu trước khi nhập — đợt 124

Lệnh này đọc file **MySQL transfer v1 do bản Node xuất**, không kết nối database, không chạy SQL, không sửa file và không nhập dữ liệu khách hàng. Không dùng cho backup PHP hoặc dump SQL tùy ý.

Trong Terminal cPanel, đặt file SQL ở thư mục riêng **ngoài public_html/document root**, rồi chạy:

```bash
php /home/USER/jinmemo.cloud/bin/check-transfer.php --file=/home/USER/private-transfer/jinmemo-export.sql
```

Thay USER và đường dẫn bằng tài khoản/thư mục thật. Lệnh không cần cấu hình MySQL hoặc `.env`. Dùng PHP CLI 8.1 trở lên như ứng dụng. Không đưa dump hoặc báo cáo vào Git hay thư mục công khai. Báo cáo JSON chỉ gồm cấu trúc, số dòng, mã lỗi/vị trí và hash khi đã đọc hết file; không xuất giá trị từng bản ghi. Hash dùng đối chiếu file, không chứng minh người xuất hay tính đúng đắn nghiệp vụ. Giữ nguyên input trong suốt lúc kiểm tra.

## Đọc kết quả

- `formatValid`: file đã đọc hết, đúng dialect được hỗ trợ, cột/giá trị không có lỗi phát hiện, có footer và số dòng từng bảng khớp. Sai schema mẫu cũng làm kết quả này thất bại; đây là kiểm tra theo mẫu đóng băng, không phải parser MySQL tổng quát.
- `profileMatches`: thêm điều kiện đủ 61 bảng và đúng 365 cột theo thứ tự/kiểu của mẫu nguồn đóng băng. Bảng vắng được liệt kê ở `missingTables`; nguồn có thể chưa tạo module đó, nên bảng vắng riêng lẻ không chứng minh file bị hỏng.
- `profileSourceCommit`: revision dùng tạo **mẫu đối chiếu**, không phải revision đã chứng thực của file đang kiểm. Export v1 không ghi source commit; bản nguồn khác cùng định dạng v1 có thể lệch cột và cần rà soát thủ công, không tự sửa dump.
- `importReady` luôn là **false**, kể cả khi mọi kiểm tra file đạt. `unchecked` liệt kê các kiểm tra chưa làm.
- Exit code `0`: đạt toàn bộ mẫu file; `2`: lỗi hoặc thiếu bảng cần xem xét; `1`: không thể chạy/đọc file. Không dùng exit 0 để tự động bật import hay chuyển vận hành.

Chỉ nhận whitelist `jm_transfer_*`, preamble cố định, `CREATE TABLE` không khóa, từng dòng `INSERT` do exporter tạo, comment số dòng và footer `TRANSFER COMPLETE`. Không nhận SQL bổ sung, bảng auth, cột `secret`/`password_hash`, statement sau footer, file dở dang hoặc đếm dòng lệch. Hỗ trợ LF/CRLF. Giới hạn 512 MiB/file, 32 MiB/dòng, 256 cột/bảng và 100 chi tiết lỗi (tổng lỗi vẫn được đếm); PHP cần đủ memory_limit cho một dòng lớn. Dòng lớn hơn giới hạn phải xuất/xử lý lại riêng, không được âm thầm bỏ qua.

Giá trị được kiểm hex/UTF-8, JSON, UUID dạng chuẩn, ngày/giờ hợp lệ, boolean, BIGINT và giới hạn integer nguồn; không đổi tiền/BIGINT sang float. Có kiểm DECIMAL(65,20) không bị làm tròn/tràn và DOUBLE hữu hạn. SQL NULL được đối chiếu NOT NULL của mẫu. Đây không phải xác thực business JSON hoặc ràng buộc database.

**Chưa kiểm** PK/unique/FK, tham chiếu bên trong JSON, adapter runtime, tổng thu/hoàn/tồn, xung đột collation, file media/PDF vật lý và nguồn gốc file. Chưa có importer toàn bộ. Các importer ảnh/bài viết hiện có vẫn có dry-run và điều kiện riêng. Không đổi prefix staging thành `jingo_`, không import dump này vào DB đang vận hành. PDF tiếp tục chỉ đính kèm/giao file gốc.

## Mẫu và kiểm thử

`TRANSFER-EXPORT-SCHEMA.json` đóng băng schema nguồn `7e3578d2c27f166ba42a48ce4ee5b21e7ff33cbb`, gồm cột CREATE và ADD COLUMN theo ordinal, bỏ hai cột bí mật đúng exporter. SHA-256 schema/exporter được lưu riêng. Schema PHP vẫn **052**, không cần chạy migration cho đợt này.

```bash
php tests/transfer-audit.php
node tests/build-transfer-audit-fixture.mjs /path/to/local/node-repository
```

PHP test chạy độc lập, không cần DB/Node. Lệnh Node tùy chọn để tái tạo fixture tổng hợp bằng chính `mysqlCreate`/`mysqlInsert` của nguồn đã ghim hash; cần dependency TypeScript của checkout nguồn. Fixture chứa dữ liệu giả, không phải database đủ quan hệ để import. Test kiểm file mẫu đủ 61 bảng, số lớn giữ nguyên, dữ liệu hỏng, SQL lạ, riêng tư, file thiếu module, CLI/exit code và hash input không đổi. Chưa thử CLI trên cPanel thật.
