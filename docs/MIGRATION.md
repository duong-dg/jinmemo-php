# Chuyển dữ liệu bản Node/PostgreSQL sang PHP/MySQL

Có thể chuyển dữ liệu. Bản Node hiện có **Xuất dữ liệu MySQL (.sql)** tạo các bảng staging `jm_transfer_*`, giữ tên cột nguồn. File này nhập được vào database chuyển dữ liệu riêng, nhưng chưa phải schema vận hành của PHP. Repo PHP chưa có bộ nhập toàn bộ staging vào bảng `jingo_*`.

Đợt 20 đã có **bộ nhập riêng cho bài viết**, mặc định chỉ kiểm tra; xem [IMPORT-POSTS.md](IMPORT-POSTS.md). Nó chưa nhập đơn hoặc các module khác. Các ghi chú “chưa có importer toàn bộ” bên dưới vẫn áp dụng.

Đợt 21 thêm [bộ nhập metadata ảnh](IMPORT-MEDIA.md), dùng sau khi chép file ID.webp gốc vào thư mục media PHP. Nó giữ đủ trường media nguồn, bỏ qua metadata trùng khớp và không ghi đè ảnh/metadata khác. Migration 016 giữ được microsecond của created_at.

## Cách chuyển dự kiến

1. Sao lưu PostgreSQL và toàn bộ file upload. Giữ bản gốc để phục hồi.
2. Xuất file MySQL transfer v1 từ bản Node, nhập vào database staging riêng. Công cụ ánh xạ sau này đọc các bảng đó và nạp vào schema PHP đã chạy `php bin/install.php`; không đổi tiền tố bằng tìm/thay rồi import thẳng vào database đang chạy.
3. Chạy thử trên database mới, kiểm tra số bản ghi, ID tham chiếu, mã đơn, số tiền, trạng thái thanh toán, tồn vé và lượt voucher. Đơn cũ phải giữ nguyên snapshot giá, không tính lại theo bảng giá mới.
4. Chuyển file ảnh và file vé/PDF gốc cùng dữ liệu tham chiếu. PDF chỉ cần được đính kèm gửi khách, không cần tách mã hay dựng lại PDF.
5. Khi chuyển chính thức, tạm dừng phát sinh đơn/thanh toán ở bản cũ, xuất lần cuối và đối chiếu trước khi đổi website. Không để hai bản cùng nhận đơn trên hai database độc lập.

## Các điểm cần xử lý

- Giữ ID sản phẩm, gói vé, nhà cung cấp, voucher và đơn; chuyển kiểu JSON, boolean và thời gian UTC đúng nghĩa.
- Giữ mã đơn, snapshot, liên hệ, lịch sử, thanh toán, mã giao dịch duy nhất và phân bổ tồn/voucher. Chỉ nhập những module mà PHP đã hỗ trợ đầy đủ; bảng chưa hỗ trợ phải được giữ trong bản sao lưu để chuyển sau.
- PHP đã có luồng WEB/POS/CTV, giỏ, thanh toán và giao vé tương ứng các đợt chuyển gần đây (schema 050). Tuy nhiên chưa có importer toàn bộ đơn/ledger/tồn từ staging: không import trực tiếp dump bản cũ vào bảng vận hành chỉ vì tên cột giống nhau. Cần kiểm tra đầy đủ quan hệ và sổ thu trước khi chuyển dữ liệu thật.
- Mật khẩu admin bản Node dùng scrypt, bản PHP hiện dùng bcrypt: cần đặt lại mật khẩu hoặc bổ sung cơ chế xác minh/chuyển hash có kiểm thử. Phiên đăng nhập cũ không mang sang.
- Cấu hình SMTP, khóa mã hóa, domain và callback thanh toán phải được đối chiếu khi chuyển các module tương ứng. Không đưa bí mật vào file xuất công khai hoặc Git.
- Chuyển giữa hai phiên bản PHP/MySQL sau này: giữ database và chạy migration bổ sung theo hướng dẫn từng phiên bản; không xóa database để cài lại.

Không sửa dump bằng tìm/thay cú pháp hàng loạt rồi nhập vào database thật. Công cụ chuyển cần chế độ chạy thử và báo cáo đối chiếu trước khi sử dụng.

## Đối chiếu trường với export bên Node

Tham chiếu exporter tại commit `585cd8a36c106dd3552fb397281f7f2e9c299f50`. [TRANSFER-CONTRACT.json](TRANSFER-CONTRACT.json) lưu danh sách trường vé/file và các khác biệt đã biết. Bộ test có SQL fixture sinh bằng chính `mysqlCreate/mysqlInsert` của exporter, không phải dump dữ liệu khách hàng.

- `jm_transfer_order_tickets` → `jingo_order_tickets`: giữ đủ id/order_id/code/code_hash/kind/covers/note/created_at.
- `jm_transfer_ticket_files` → `jingo_ticket_files`: giữ đủ id/order_id/ticket_id/name/mime/bytes/hash/created_at/revoked_at/revoke_reason/file_kind/deleted_at/partner_shared/sharing_version. Thời gian mới dùng DATETIME(6). Không tự đặt lại file_kind, sharing_version hoặc trạng thái thu hồi/xóa khi chuyển.
- File vật lý: giữ nguyên nội dung và ID, chép vào `MEDIA_DIR/.tickets/{id}.bin` (mặc định `storage/media/.tickets`). Đối chiếu bytes/SHA-256 với metadata. SQL không chứa file ảnh/PDF.
- Cấu trúc đã làm ở các đợt trước có chỗ cần adapter: `order_actions.note` ở PHP nằm trong JSON `data.note`; `order_payments.reference` nằm trong bảng `jingo_payment_references` nối qua reference_hash; `payment_methods.method` nằm trong `jingo_order_payments.method`. Phải kiểm cả bản ghi mã giao dịch dùng chung khi nhập tiền cũ.
- Mã đơn vé `JM…` đã được nhận ở tra cứu PHP cùng mã `JG…`; giữ mã gốc khi nhập. Các mã giỏ/xe/quà/phòng cần module tương ứng, không đưa vào bảng đơn vé một cách tự động.
- Trước khi viết importer cần kiểm giới hạn ID/chuỗi, độ chính xác thời gian ở các bảng PHP cũ, hash yêu cầu/idempotency và những cột nguồn bổ sung. Chưa có bảo đảm toàn bộ bảng export đã tương thích. Dữ liệu chưa hỗ trợ tiếp tục giữ trong staging và bản backup, không bỏ cột để ép nhập.

## Bài viết từ export

Đợt 19 bổ sung `jingo_post_categories`, `jingo_posts`, `jingo_post_revisions`, `jingo_post_metrics`, giữ đúng tên cột nguồn (danh sách `postTables` trong contract). Fixture SQL sinh bởi exporter thật đã được nhập vào database thử và ánh xạ đủ bốn bảng. Đây là kiểm thử cấu trúc, chưa phải lệnh nhập dữ liệu sản xuất.

Giữ nguyên JSON data gồm English/status/sourceHash, publishedAt, relatedProductIds, relatedRideIds và featured; giữ created_at/updated_at/revision.created_at ở UTC với microsecond, saved_by/reason/version và BIGINT views. Không đưa trường views từ client vào đè bộ đếm đã nhập. Giữ alias POST/POST_EN trong bảng slug_aliases và chép ảnh media theo ID cũ. Bản PHP đọc được nội dung EN đã xuất bản; chưa có trình biên tập bản dịch, luồng xe, quản lý chuyên mục đầy đủ hoặc ghi lượt xem mới.

Đợt 109 bổ sung phân hệ thời tiết, migration 051. Cache và hạn mức là dữ liệu runtime riêng; công cụ import đơn/ledger/tồn vẫn chưa hoàn tất. Các trường bảng nguồn không được đổi tên để ép nhập. Xem WEATHER.md và PORT-COMPLETION.md.

Đợt 124 thêm `bin/check-transfer.php --file=/private/path/export.sql` để kiểm file Node transfer v1 trực tiếp, không cần import vào staging DB. Kiểm preamble/footer/số dòng, whitelist, 61 bảng/365 cột của mẫu nguồn đóng băng và giá trị cơ bản; thiếu module được báo riêng. Không chạy SQL hay sửa input, không kiểm quan hệ/ledger và `importReady` luôn false. Xem [hướng dẫn kiểm file](TRANSFER-FILE-AUDIT.md) trước khi dùng kết quả. Schema PHP vẫn 052; chưa có importer toàn bộ.

Đợt 125 thêm tùy chọn `--relations` cho cùng lệnh để kiểm 61 PK/26 unique/55 FK khai báo trong nguồn bằng hai lượt đọc và chỉ mục hash có giới hạn. Phân biệt bản ghi cha không tồn tại với bảng đích chưa xuất/không thuộc whitelist; không giả lập `gateway_attempts` bị exporter bỏ qua. Không kiểm quan hệ JSON/đa loại, ledger/tồn hoặc collation đích; `importReady` vẫn false. Không sửa file SQL, kết nối MySQL hoặc nhập dữ liệu thật.

Đợt 130: thêm bin/import-catalog.php cho bốn danh mục nền và alias liên quan, giữ ID/JSON/version, kiểm cây/slug/file bìa và không ghi đè metadata khác. Mặc định transaction read-only; --apply cần fingerprint của dry-run, kiểm lại rồi ghi nguyên khối/audit. Không tự nạp file SQL hay nhập dữ liệu vận hành, chưa bao gồm sản phẩm/đơn/tồn/công nợ. Xem [hướng dẫn importer danh mục](IMPORT-CATALOG.md); không SQL mới, schema vẫn 054.

Đợt 131 thêm bin/import-products.php cho sản phẩm/variants/prices/rules/closures/English và alias PRODUCT, cùng các danh mục nền. Giữ updated_at UTC microsecond bằng migration 055, không tái tạo ID/gói hoặc tính lại giá. Cần dry-run/fingerprint riêng và media hợp lệ; không nhập đơn/tồn/công nợ hay dữ liệu thật khi cập nhật. Xem [import sản phẩm](IMPORT-PRODUCTS.md).

Đợt 132 thêm bin/import-price-groups.php: mở rộng nhóm sản phẩm/danh mục với price_groups, giữ DIRECT/DISCOUNT/legacy/status/rules và version, kiểm tham chiếu sản phẩm/gói/nhóm khách/slot, dry-run/fingerprint/transaction nguyên khối. Không nhập partners hoặc ledger, không tạo công nợ; schema vẫn 055, không SQL mới. Xem [import nhóm giá CTV](IMPORT-PRICE-GROUPS.md).

Đợt 133 thêm bin/import-partners.php cho metadata CTV, giữ ID/email/JSON/version và kiểm group/email. Nguồn không có password_hash: tài khoản mới dùng dấu cần cấp lại mật khẩu và transfer_pending=1 (migration 056, cột runtime riêng); login/giao dịch bị chặn tới khi nhập/đối chiếu đơn/ledger xong. Không nhập session/mật khẩu hoặc mở khóa tự động khi admin đặt mật khẩu. Tài khoản PHP đã khớp metadata giữ credential/cờ hiện tại. Xem [import hồ sơ CTV](IMPORT-PARTNERS.md).

Đợt 134 thêm bin/check-partner-credit-transfer.php để đối soát công nợ nguồn trực tiếp từ file export: đơn/chủ/tổng/trạng thái CHARGE, mã REPAYMENT hai chiều, số nguyên an toàn, FIFO và quá hạn với mốc UTC cố định. Kiểm digest bằng creditSummary Node thật; giới hạn index/báo cáo, không in thông tin khách. Không SQL/migration/import/mở khóa, importReady vẫn false. Còn importer đơn/tồn/ledger và đối soát đích. Xem [đối soát công nợ nguồn](PARTNER-CREDIT-TRANSFER-AUDIT.md).

Đợt 135 thêm bin/import-vouchers.php cho metadata mã giảm giá vé cùng danh mục/sản phẩm/alias. Giữ ID/code/JSON/version, kiểm target sản phẩm/gói và trùng mã, dry-run/fingerprint/transaction/replay. Migration 057 thêm cờ runtime transfer_pending: voucher mới nhập chờ đơn/voucher_uses và đối soát lượt; báo giá/ghi/trả lượt bị chặn, lưu/bật không mở khóa. Voucher PHP matching giữ trạng thái/lượt đã có. Không nhập dữ liệu thật hoặc lịch sử uses/đơn/tồn/ledger; xem [import voucher](IMPORT-VOUCHERS.md).

Đợt 136 thêm bin/import-voucher-uses.php cho quan hệ order_id/voucher_id/released, chờ đơn/voucher cha đã chuyển và khớp nguyên bản. Giữ lượt active/released, kiểm hai chiều/quota/kênh website, không repricing/sửa đơn hoặc mở khóa. Migration 058 giữ created_at đơn DATETIME(6); dry-run/fingerprint/transaction/audit/replay và hai worker đã kiểm bằng exporter fixture. Test seed cha chỉ mô phỏng điều kiện, chưa phải importer đơn. Chưa nhập thật; xem [import lịch sử voucher](IMPORT-VOUCHER-USES.md).

Đợt 137 thêm bin/import-order-cores.php và migration 059 tạo jingo_transfer_orders. Kiểm/giữ nguyên 13 cột lõi đơn, tiền lịch sử/JSON/UTC microsecond/hash và collision; read-only dry-run, fingerprint/transaction/audit/replay/hai worker. Chỉ ghi kho chờ, không jingo_orders/thu/tồn/vé/email; operationalReady=false. Đây chưa phải importer đơn tổng thể hoặc bước đáp ứng cha runtime cho voucher_uses. Xem [lõi đơn](IMPORT-ORDER-CORES.md).

Đợt 138 thêm bin/import-order-relations.php, migration 060 tạo ba bảng kho chờ cho partner_orders/pos_orders/order_actions. Giữ đúng source schema/actor/note/timestamp và UUID request_hash của REVOKE_FILE; không tự tạo order_version, chạy lại thao tác hoặc sửa bảng vận hành. Kiểm lõi/metadata/kênh/reverse links, dry-run/fingerprint/atomic/replay/hai worker; operationalReady=false. Chưa nhập thật; xem [liên kết/lịch sử](IMPORT-ORDER-RELATIONS.md).

Đợt 139 thêm importer tổng hợp 61 bảng vào kho payload lossless (schema 061), đọc export trực tiếp không chạy SQL. Có kiểm quan hệ và đối soát tiền/tồn/voucher/nợ/vé/giỏ, kiểm original media/ticket bytes và runtime schema plan. Không publish runtime, không suy ngược secret bị exporter bỏ hoặc giả gateway parent. Người dùng chỉ có export MySQL: dữ liệu không được export/file vật lý cần quy trình bổ sung, không tự reset hash. Bộ hồi quy 167 bài đã đạt trên local sau cập nhật sáu ca harness cũ; xem [FULL-TRANSFER.md](FULL-TRANSFER.md). Chưa nhập dữ liệu thật.

Đợt 140 bổ sung nhận full-v2 từ Node, giữ hợp đồng v1 riêng. Xem EXPORT-IMPORT-COORDINATION.md để phối hợp cập nhật exporter/mapper, chạy schema tự động và kiểm SQL/ảnh/file gốc trước khi lưu kho chờ. Chưa thực hiện import thật hoặc xuất bản vận hành.
