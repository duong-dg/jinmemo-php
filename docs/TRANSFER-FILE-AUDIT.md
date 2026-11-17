# Kiểm file chuyển dữ liệu trước khi nhập — đợt 124–125

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
- Exit code `0`: đạt toàn bộ mẫu file và các kiểm tra được yêu cầu; `2`: lỗi hoặc thiếu bảng/quan hệ cần xem xét; `1`: không thể chạy/đọc file. Không dùng exit 0 để tự động bật import hay chuyển vận hành.

Chỉ nhận whitelist `jm_transfer_*`, preamble cố định, `CREATE TABLE` không khóa, từng dòng `INSERT` do exporter tạo, comment số dòng và footer `TRANSFER COMPLETE`. Không nhận SQL bổ sung, bảng auth, cột `secret`/`password_hash`, statement sau footer, file dở dang hoặc đếm dòng lệch. Hỗ trợ LF/CRLF. Giới hạn 512 MiB/file, 32 MiB/dòng, 256 cột/bảng và 100 chi tiết lỗi (tổng lỗi vẫn được đếm); PHP cần đủ memory_limit cho một dòng lớn. Dòng lớn hơn giới hạn phải xuất/xử lý lại riêng, không được âm thầm bỏ qua.

Giá trị được kiểm hex/UTF-8, JSON, UUID dạng chuẩn, ngày/giờ hợp lệ, boolean, BIGINT và giới hạn integer nguồn; không đổi tiền/BIGINT sang float. Có kiểm DECIMAL(65,20) không bị làm tròn/tràn và DOUBLE hữu hạn. SQL NULL được đối chiếu NOT NULL của mẫu. Đây không phải xác thực business JSON hoặc ràng buộc database.

**Mặc định chưa kiểm** PK/unique/FK; bật tùy chọn bên dưới để kiểm các ràng buộc đã khai báo trong mẫu nguồn. Vẫn chưa kiểm tham chiếu bên trong JSON, adapter runtime, tổng thu/hoàn/tồn, xung đột collation, file media/PDF vật lý và nguồn gốc file. Chưa có importer toàn bộ. Các importer ảnh/bài viết hiện có vẫn có dry-run và điều kiện riêng. Không đổi prefix staging thành `jingo_`, không import dump này vào DB đang vận hành. PDF tiếp tục chỉ đính kèm/giao file gốc.

## Đối chiếu khóa và quan hệ — đợt 125

```bash
php /home/USER/jinmemo.cloud/bin/check-transfer.php --file=/home/USER/private-transfer/jinmemo-export.sql --relations
```

Lệnh vẫn chỉ đọc file, không cần MySQL. Hai lượt đọc cùng parser giới hạn của đợt 124: lượt đầu kiểm PK/unique và lập chỉ mục hash; lượt hai kiểm FK, kể cả cha nằm sau con trong file. Hash toàn file của hai lượt phải khớp. Bản ghi/file có lỗi định dạng không được dùng để kết luận quan hệ đạt.

Mẫu `TRANSFER-RELATIONS.json` gồm **61 PK, 26 unique, 55 FK** lấy từ đúng schema/hash của mẫu cột. Chỉ kiểm những ràng buộc nguồn thực sự khai báo, không suy ra quan hệ chỉ từ tên cột. Tham chiếu đa loại `kind/entity_id`, ID trong JSON và các quy tắc CHECK/nghiệp vụ chưa được kiểm. Tham chiếu FK nullable không có giá trị được bỏ qua; UNIQUE cho phép nhiều SQL NULL theo PostgreSQL. Khóa UUID được chuẩn hóa hoa/thường, số nguyên giữ dạng chuỗi chính xác, khóa text so theo byte. Chưa chứng minh tương đương collation MySQL đích.

Kết quả thêm `relations`:

- `PASSED`: đã kiểm các ràng buộc khai báo trên tất cả bản ghi trong file và không thấy lỗi/tham chiếu chưa giải quyết. Không đồng nghĩa đủ bảng; vẫn xem `profileMatches`/`missingTables`.
- `FAILED`: phát hiện khóa trùng, FK trỏ đến bản ghi không tồn tại trong bảng đã xuất, hoặc file thay đổi giữa hai lượt. `errors` chỉ ghi mã lỗi, bảng/cột, dòng SQL và thứ tự bản ghi, không ghi ID/giá trị khóa.
- `INCOMPLETE`: có tham chiếu chưa đối chiếu được hoặc chạm giới hạn chỉ mục. `TARGET_NOT_EXPORTED` là đích không có trong whitelist exporter (hiện có `gateway_events.attempt_id → gateway_attempts.id`); `TARGET_TABLE_MISSING` là đích thuộc whitelist nhưng không có trong file. Hai trường hợp này không tự chứng minh dữ liệu bị hỏng; báo số tham chiếu để lập kế hoạch bổ sung nguồn.
- `SKIPPED`: file không qua kiểm định dạng/giá trị/schema. Chưa kết luận quan hệ.

`declaredConstraintsValid` là true khi PASSED, false khi FAILED, null khi chưa kết luận. `formatValid` và `profileMatches` mô tả kiểm file gốc, không bao gồm lỗi quan hệ; với `--relations`, CLI chỉ trả exit 0 khi profile đầy đủ **và** quan hệ PASSED. `importReady` vẫn luôn false.

Giới hạn **100.000 khóa được lập chỉ mục tổng cộng**, không phải 100.000 bản ghi: mỗi PK/unique có thể tạo thêm khóa. Chỉ giữ hash SHA-256 của tuple đã mã hóa độ dài trong bộ nhớ, không tạo DB/chỉ mục trên đĩa; tối đa 100 chi tiết lỗi nhưng giữ tổng số. Chạm giới hạn báo `INDEX_LIMIT`, không âm thầm bỏ phần dư hay báo đạt. PHP phải có đủ memory_limit cho chỉ mục và dòng SQL lớn; chưa thử hosting cPanel thật. Đợt này không đổi schema PHP 052, không có bước import.

## Mẫu và kiểm thử

`TRANSFER-EXPORT-SCHEMA.json` đóng băng schema nguồn `7e3578d2c27f166ba42a48ce4ee5b21e7ff33cbb`, gồm cột CREATE và ADD COLUMN theo ordinal, bỏ hai cột bí mật đúng exporter. SHA-256 schema/exporter được lưu riêng. Schema PHP vẫn **052**, không cần chạy migration cho đợt này.

```bash
php tests/transfer-audit.php
php tests/transfer-relations.php
node tests/build-transfer-audit-fixture.mjs /path/to/local/node-repository
node tests/build-transfer-relations.mjs /path/to/local/node-repository --check
```

PHP test chạy độc lập, không cần DB/Node. Lệnh Node tùy chọn để tái tạo fixture tổng hợp bằng chính `mysqlCreate`/`mysqlInsert` của nguồn đã ghim hash; cần dependency TypeScript của checkout nguồn. Fixture chứa dữ liệu giả, không phải database đủ quan hệ để import. Test kiểm file mẫu đủ 61 bảng, số lớn giữ nguyên, dữ liệu hỏng, SQL lạ, riêng tư, file thiếu module, CLI/exit code và hash input không đổi. Chưa thử CLI trên cPanel thật.

Script `build-transfer-relations.mjs` tái tạo/đối chiếu mẫu ràng buộc từ schema nguồn đã ghim hash, không cần PostgreSQL/TypeScript và không sửa nguồn. `--check` chỉ so sánh file mẫu hiện có. Bộ kiểm quan hệ dùng fixture exporter, sửa dữ liệu giả để kiểm khóa đơn/ghép trùng, số nguyên/UUID, tuple không nhập nhằng, NULL, cha vắng/rỗng, đích ngoài export, giới hạn chỉ mục, riêng tư và exit code. Không đưa fixture này vào database sản xuất.
