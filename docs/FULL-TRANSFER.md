# Luồng kiểm và lưu toàn bộ export — đợt 139

**Đây chưa phải chức năng đưa toàn bộ dữ liệu cũ vào vận hành.** Đợt này bổ sung kiểm và lưu trọn bộ 61 bảng có trong export vào kho chờ chung, đối soát chéo các nhóm tiền/tồn/vé, kiểm file gốc và báo chi tiết trở ngại chuyển sang schema PHP. Không có lệnh force/publish hoặc tự xóa cờ pending.

Người dùng hiện chỉ có file export MySQL. Bộ export không bao gồm mọi dữ liệu của ứng dụng Node: mật khẩu/session không xuất; secret của giỏ/đơn phòng bị bỏ; các bảng mail/khóa gửi mail và gateway_attempts không được xuất. File SQL cũng không chứa ảnh/PDF gốc. Không suy ra được khóa gốc từ access_hash hoặc phục hồi bytes file từ SHA-256. Không tự tạo dữ liệu giả để gọi là đã chuyển xong.

## Cập nhật hosting

Chạy `database/061-full-transfer-holding.sql` sau git pull hoặc installer theo quy trình cập nhật, giữ nguyên các migration trước. Tạo jingo_transfer_records với khóa `(table_name,row_key)`, payload JSON và row_hash; không nhập dữ liệu khi cập nhật.

Mỗi payload giữ **tên cột nguồn và giá trị chuỗi/null nguyên bản**. JSON trong các cột data/snapshot/contact được giữ như chuỗi JSON nguồn; BIGINT/DECIMAL được giữ như chuỗi, không qua float hoặc số JavaScript. Giữ ngày giờ, trạng thái released/deleted/revoked, UUID request_hash legacy, mã tham chiếu và ID. Đây là kho chờ riêng, không phải bộ bảng mà API bán hàng đọc.

## Một lệnh cho đủ 61 bảng

Giữ export ngoài webroot, backup và nguồn cố định. Lệnh đọc đúng dialect Node transfer v1, không thực thi SQL, không cần nạp dump vào một database staging nữa.

```bash
php bin/import-transfer.php --file=/private/export.sql
```

Mặc định read-only dry-run. Kiểm đầy đủ profile 61 bảng/365 cột, preamble/footer/số dòng/SHA-256, kiểu/NULL/JSON/ngày/giới hạn số, PK/unique và FK đã khai báo. Giữ giới hạn chỉ mục của kiểm quan hệ. Tham chiếu đến gateway_attempts không được xuất sẽ được ghi unresolved và chặn khả năng vận hành, không dựng bản ghi cha giả. Lỗi cấu trúc/quan hệ thực sự chặn cả bước lưu kho chờ.

Đối soát nguồn gồm:

- Lõi đơn: snapshot và phép tính dòng/giảm/tổng, kênh WEB/CTV/POS, CREDIT cần chủ CTV.
- Thu tiền: đơn PAID có khoản thu đúng tổng, mã giao dịch và payment method; kiểm chiều ngược mã ORDER_PAYMENT.
- Công nợ CTV: CHARGE khớp chủ/tổng/mã đơn CREDIT, không ghi trùng; REPAYMENT âm và mã đối ứng dương, số dư không âm, phép cộng trong phạm vi nguyên an toàn. Đây không phải phép kiểm quá hạn theo mốc hiện tại; dùng bộ kiểm công nợ riêng nếu cần FIFO/quá hạn.
- Voucher: liên kết hai chiều với snapshot, cờ trả lượt khớp đơn hủy, lượt chưa trả không vượt maxUses.
- Tồn: allocation khớp sản phẩm/gói/ngày/suất/số khách/cờ release, không vượt bucket; không tạo lại tồn từ doanh thu.
- Nguồn cung: allocation và hạn mức lô, giá vốn số nguyên, nghĩa vụ mua finite lot/on-demand và khoản trả nhà cung cấp không vượt nghĩa vụ đã biết. Không tự giải phóng hoặc nhập lại mã kho.
- Vé: hash mã, số khách đã giao, mã ISSUED đúng vé/đơn và phần phân bổ lô; file metadata đúng vé/đơn.
- Giỏ: thành viên, vị trí không trùng, kênh/chủ/quầy và tổng snapshot con.

Các semantics JSON của phòng/xe/quà/hoàn tiền/cổng và toàn bộ adapter vận hành **chưa được chứng nhận đầy đủ**. Chúng vẫn được lưu nguyên bản nếu file/quan hệ/những điều kiện đang kiểm đạt; các mục unchecked/activationBlockers được giữ trong báo cáo. Không gắn nhãn PASSED của đối soát nguồn thành chứng nhận toàn hệ thống.

## File gốc, gồm PDF chỉ đính kèm

```bash
php bin/check-transfer-files.php --file=/private/export.sql --media-dir=/private/media --ticket-dir=/private/tickets
```

Ảnh đặt tên UUID.webp; file vé đặt tên UUID.bin, giữ nội dung gốc. Kiểm UUID/path, không theo symlink file, bytes và SHA-256 vé, dimensions/type WebP. Giữ cả metadata file revoked/deleted; không im lặng bỏ qua file thiếu. Tối đa 10 MiB/file và 2 GiB tổng. PDF là bytes đính kèm: không đọc QR, render, tách trang, OCR hoặc tạo lại PDF.

Có thể gộp kiểm file vào lệnh chính:

```bash
php bin/import-transfer.php --file=/private/export.sql --media-dir=/private/media --ticket-dir=/private/tickets
```

Kiểm file chạy trước; SHA của export phải khớp giữa các bước. Nếu file gốc không đạt thì apply bị chặn, không ghi kho chờ. Manifest hash chỉ xác nhận những bytes đã kiểm tại thời điểm chạy, không tự sao chép file hoặc bảo đảm file không bị sửa sau đó. Chỉ file SQL thì chưa thể kiểm hoặc giao lại PDF/ảnh không có trong SQL.

## Ghi kho chờ sau này, không nhập thật lúc phát triển

Sau khi xem dry-run và tới cửa sổ chuyển dữ liệu:

```bash
php bin/import-transfer.php --file=/private/export.sql --apply --fingerprint=HASH_DRY_RUN
```

Có thể kèm cả hai thư mục để chặn khi file gốc sai/thiếu. Token riêng theo scope, schema nguồn và SHA của toàn file. Nhiều lượt đọc phải cùng SHA, kiểm lại sau khi lấy khóa DB. Khóa catalog và hàng đích, thêm nguyên nhóm cùng audit transfer.full-hold trong một transaction. Lỗi rollback; replay khớp không thêm hàng/audit. Không ghi đè payload khác, hàng dư hoặc dữ liệu kho chờ hỏng hash.

Kho chờ ghi toàn bộ 61 bảng kể cả nhóm có bảng rỗng; không bỏ một module vì chưa có adapter. Payload không phải SQL để thực thi lại. Không ghi vào đơn/tồn/thu/ledger/mail vận hành hoặc gọi provider. Exit 0 chỉ xác nhận kho chờ trong phạm vi này, 2 cần xem dữ liệu/file, 1 tham số/hạ tầng/giới hạn không hoàn tất. operationalReady luôn false.

Giới hạn: file 512 MiB/dòng 32 MiB theo parser, 100.000 hàng và 64 MiB payload nguồn, tối đa tổng nguồn+đích 128 MiB; quan hệ tối đa 100.000 khóa chỉ mục. 100 chi tiết lỗi nhưng vẫn đếm đầy đủ. Không in payload, email/tên/ID/mã vé/hash yêu cầu hoặc đường dẫn file trong báo cáo; báo đếm, mã lỗi và fingerprint.

## Báo cáo adapter và dữ liệu không có trong export

runtimePlan so sánh tên cột của 61 bảng nguồn với schema PHP hiện có, chỉ rõ bảng thiếu/cột cần adapter. COLUMN_SET_MATCH_ONLY không chứng minh kiểu, JSON, quyền hoặc hành vi đã tương đương. Những điểm đã xác nhận gồm policy_pages, payment_methods, tham chiếu thanh toán/reference_hash, order_actions.note/data và order_version, cờ pending và secret runtime.

Với chỉ export hiện có, còn cần thiết kế/kiểm quy trình cấp proof và khóa mail mới mà không âm thầm ghi đè hash gốc; khôi phục hoặc cấu hình lại phần không được export. Không có quy trình tự động đó trong đợt này. Không nhập mật khẩu cũ hoặc mở tài khoản CTV chỉ bằng cách chép hash; tài khoản mới vẫn cần cấp mật khẩu sau đối soát.

Gateway events có thể trỏ attempt không được xuất. Các hàng này được giữ trong kho chờ, nhưng chưa được đưa sang luồng webhook đang chạy hoặc tự đánh dấu đã thu. Cấu hình SMTP/provider mới, file gốc, kiểm dữ liệu staging và cPanel phải được xác minh trước khi thay hệ thống.

## Kiểm thử và kết quả hiện tại

```bash
node tests/build-full-transfer-fixture.mjs /path/to/node-repository
php tests/full-transfer.php
```

Fixture đủ 61 bảng sinh bằng helper exporter Node đóng băng: đơn/CTV/POS, thu tiền/trả nợ, voucher, bucket/allocation/lô/kho mã/vé/file, bài viết với BIGINT > Number.MAX_SAFE_INTEGER. Kiểm đầy đủ vòng dry-run/apply/replay/rollback/CLI/hai worker, đếm và hash dữ liệu, sai tiền/tồn/lượt/vé, tham chiếu external không bị giả lập, file gốc bị đổi/thiếu/path không an toàn và no-live-writes. Fixture PDF chỉ là bytes thử cho kiểm hash, không kiểm cú pháp/render PDF.

Đã chạy 167 bài độc lập PHP/Node của repository trên MySQL cục bộ, không phải dữ liệu/hosting thật. Sáu bài ban đầu lỗi vì cấu hình hoặc kỳ vọng cũ: main HTTP thiếu GD; CSV đã có thêm mã giỏ; mock payment choice cũ; ba fixture save kế hoạch xe gửi thêm sections từ response. Đã cập nhật harness/fixture đúng API hiện tại, giữ các kiểm quyền/giá/stock/replay và chạy lại sáu bài, tất cả đạt. Không sửa nghiệp vụ để né các kiểm đó. Các test source/core/quan hệ/voucher và file audit vẫn còn độc lập.

Chạy bộ hồi quy trên **MySQL thử cục bộ không dbname**, không phải DB vận hành:

```bash
node tests/run-suite.mjs --php=/path/to/php --scope=all --report=/private/suite.json
node tests/run-suite.mjs --php=/path/to/php --rerun-failed=/private/suite.json --report=/private/suite.json
```

Cần JINGO_TEST_DSN (ví dụ mysql:host=127.0.0.1;port=33317;charset=utf8mb4) và quyền tạo/xóa DB ngẫu nhiên. Runner không cho DSN remote/đã chọn database, chạy hai bài song song, bỏ worker/scenario helper, tự bật GD cho bài HTTP nếu cần; ghi lại lần thất bại trước khi rerun. --scope=transfer chỉ chạy nhóm chuyển dữ liệu. Báo cáo suite không thay kiểm giao diện/cPanel/SMTP hoặc thanh toán thật.

## Đối chiếu trong admin

Mục **Cấu hình website → Dữ liệu chuyển đổi** chỉ xem dữ liệu kho chờ: số dòng theo 61 nhóm, tìm mã/tên/email/điện thoại, xem snapshot tóm tắt, lịch sử thao tác/thu tiền/file metadata. Phân trang 50 đơn, mỗi nhóm chi tiết tối đa 100 bản ghi; không lộ request_hash/access_hash/password hoặc payload tùy ý. Mọi API đều cần phiên admin, chỉ GET, no-store; CTV/POS/public không được đọc. Kiểm row_hash trước khi hiển thị, dữ liệu bị sửa trả 409. Không có nút thu tiền/gửi vé/kích hoạt và không tự mở file chưa được kiểm.

Bổ sung `tests/transfer-review.php` và `tests/transfer-review-ui.cjs`: HTTP quyền/GET/filter/privacy/no-store/integrity và DOM chống chèn HTML, đọc kho chờ không ghi nghiệp vụ. Sau tích hợp cuối, 18 bài nhóm chuyển dữ liệu đều đạt; hợp với bộ hồi quy 167 bài trước đó là **169 bài độc lập đã chạy đạt**. Chưa có kiểm layout trực quan trên cPanel hoặc provider thật.

## Full-v2 (đợt 140)

CLI tự nhận v1/full-v2. V2 kiểm hợp đồng 68 bảng nguồn + manifest (69 nhóm/404 cột), số dòng, kiểu cột và SHA-256 nguyên bytes INSERT. Node v2 không có comment đếm dòng từng bảng như v1; manifest/footer cung cấp số đếm. MISSING_SOURCE phải khai báo, vẫn chặn vận hành; cột/khóa credential bị loại, không tạo giả access_hash. JSON LONGTEXT giữ nguyên token số. Metadata manifest không phải bảng runtime. Kho đã có v1 không tự ghi đè bằng v2. Settings, danh tính admin/POS và lịch sử mail chỉ giữ kho chờ, không kích hoạt đăng nhập hoặc gửi mail. Xem [quy trình phối hợp](EXPORT-IMPORT-COORDINATION.md). Các giới hạn xuất bản nêu trên vẫn áp dụng.

Đợt 141 có CLI riêng `import-business-settings.php` xuất bản hai cấu hình vào runtime (IMPORT-BUSINESS-SETTINGS.md). `import-transfer.php` vẫn giữ vai trò kho chờ, không tự gọi adapter hay mở bán.

Đợt 142: adapter runtime quà đã hỗ trợ đơn độc lập cùng catalog/tồn trong một giao dịch; xem IMPORT-GIFT-ORDERS.md. CLI kho chờ chung vẫn không tự xuất bản hoặc chạy adapter.

Đợt 143 có adapter runtime cho lịch sử hoàn (IMPORT-REFUNDS.md). Cha/khoản thu vận hành phải khớp trước, khác với kho chờ chưa thể dùng làm cha runtime. Lệnh lưu kho chờ không tự gọi importer hoàn.

Đợt 144: adapter runtime quà/mã tổng quà có luồng cấp liên kết thay thế sau đối soát, xem IMPORT-GIFT-BUNDLES.md. Lệnh kho chờ chung vẫn không tự gọi adapter hoặc mở vận hành.

Đợt 145 thêm adapter runtime cho kế hoạch/xe riêng chưa thu (IMPORT-PRIVATE-RIDES.md); không tự chạy từ lệnh kho chờ. Còn các nhánh ghép/đã thu/POS/QR/mã tổng; không chứng nhận toàn bộ xe hoặc publication đã hoàn tất.

Đợt 147 bổ sung phạm vi --with-paid-qr cho native private WEB SePay PAID receipts/events/links nguyên tử, giữ request replay và thời gian chính xác qua schema 064. Không tự suy ra QR attempts hoặc nguồn thiếu bằng chứng; chưa nhận cash/refund/POS/shared/mixed bundle. Xem IMPORT-PAID-RIDES.md; operationalReady vẫn false.
