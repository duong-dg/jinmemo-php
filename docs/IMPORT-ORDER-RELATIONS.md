# Liên kết kênh bán và lịch sử thao tác trong kho chờ — đợt 138

Sau `git pull`, chạy **database/060-order-relation-holding.sql** qua phpMyAdmin hoặc installer. Migration tạo ba bảng riêng, có FK trỏ lõi đơn trong jingo_transfer_orders của schema 059; không chép dữ liệu hoặc thay đổi bảng đơn đang bán.

`bin/import-order-relations.php` bổ sung kênh CTV/POS và lịch sử gốc vào kho chuyển đổi. Không ghi các bảng quan hệ vận hành, không thực thi lại thao tác PAY/CREDIT/DELIVER/CANCEL/đính kèm/thu hồi file. `operationalReady` luôn false. Chưa chạy nhập dữ liệu thật; vẫn đợi chuyển đủ các module để nhập một lần.

## Trường được giữ

| Nguồn | Kho chờ | Cột giữ nguyên |
| --- | --- | --- |
| jm_transfer_partner_orders | jingo_transfer_partner_orders | order_id, partner_id |
| jm_transfer_pos_orders | jingo_transfer_pos_orders | order_id, counter_id, admin_id |
| jm_transfer_order_actions | jingo_transfer_order_actions | id, order_id, request_hash, admin_id, action, note, created_at |

Giữ ID, người thao tác, ghi chú kể cả dấu/emoji/khoảng trắng và thời gian UTC sáu chữ số. Không gộp sự kiện cùng timestamp, không tự sinh ID mới, không trim note hoặc đổi người thao tác thành admin hiện tại.

**Nguồn không có order_version ở order_actions.** PHP vận hành có order_version/data nhưng đó là adapter khác. Đợt này giữ schema lịch sử gốc, không suy ra phiên bản từ thứ tự timestamp, số hành động hoặc version cuối của đơn; không tự chuyển note thành một action payload có thể thực thi. Cơ chế xuất bản/đọc lịch sử gốc trong ứng dụng còn phải hoàn thiện.

`request_hash` thông thường là hex SHA-256 64 ký tự. Nhánh REVOKE_FILE trong ticket-files.module.ts của nguồn ghi ID file (UUID) vào trường này, nên kho chờ dùng VARCHAR(64), nhận và giữ UUID đúng nhánh đó. Không pad thành hash 64 ký tự, không tính lại hash. Không có đầy đủ input gốc để kiểm lại phép băm của mọi sự kiện; bước này kiểm cấu trúc và bảo toàn giá trị.

## Điều kiện kiểm

- Staging phải có orders, partner_orders, pos_orders, order_actions, partners, counters theo đúng tên cột TRANSFER-EXPORT-SCHEMA.json. Hai bảng kênh có thể rỗng nhưng không được thiếu; thiếu bảng không được suy ra tất cả là WEB. Không nhận view hoặc bảng không phải InnoDB.
- Toàn bộ lõi đơn trong nhóm nguồn phải khớp 13 cột với jingo_transfer_orders, gồm snapshot/contact/version/hash và thời gian. Chạy [import lõi đơn](IMPORT-ORDER-CORES.md) ở bước trước; thiếu hoặc khác thì chặn, không sửa lõi đơn từ lệnh này.
- Lượt liên kết/hành động phải có UUID hợp lệ và tham chiếu đúng ID đơn nguồn (không tự đổi hoa/thường). CTV/quầy được trỏ tới phải có ID chính xác trong cả metadata staging lẫn DB PHP. Không yêu cầu CTV/quầy hiện tại còn ACTIVE: lịch sử được giữ cho cả tài khoản/quầy lưu trữ; không thay trạng thái hoặc transfer_pending.
- Không một đơn vừa thuộc CTV vừa thuộc POS. Đơn CREDIT cần liên kết CTV. Snapshot có voucher web không được gán sang CTV/POS. WEB là đơn không có liên kết trong hai bảng nguồn đầy đủ; không tạo hàng WEB giả.
- Action được nhận: ARCHIVE, RESTORE, INSTRUCTIONS, CONFIRM, CANCEL, CREDIT, PAY, DELIVER, ATTACH_FILE, REVOKE_FILE. Action CREDIT phải có chủ CTV. Kiểm actor/độ dài note/timestamp/định dạng request_hash theo nhánh; không yêu cầu actor còn tồn tại hoặc tạo lại tài khoản admin không được export.
- Không ghi đè hàng khác cùng ID/order_id. Hàng đã khớp chỉ đếm matchingRows. Kiểm chiều ngược: hàng kho chờ thuộc đơn trong nhóm nguồn không được tồn tại thêm bên ngoài lịch sử nguồn; việc bỏ quan hệ sau khi đã nhập bị chặn, không tự đổi CTV/POS thành WEB.

Metadata CTV/quầy được dùng để xác nhận ID tham chiếu, không chứng nhận mọi thuộc tính metadata của chúng khớp bằng bước này; phần đó thuộc importer tương ứng. ID quầy phân biệt hoa/thường đúng nguồn. Các nhóm đơn ngoài tập nguồn hiện tại không bị xóa hoặc đổi liên kết.

## Lệnh và phạm vi kết quả

Chuẩn bị backup, export đã kiểm và staging cố định khác database PHP, cùng MySQL. Sau này tại cửa sổ chuyển đổi cần dừng request/worker ghi, nhập metadata và lõi trước rồi mới tới bước này. Không chép từ kho chờ sang bảng vận hành bằng SQL để bỏ qua các module chưa làm.

```bash
php bin/import-order-relations.php --source-db=TAIKHOAN_staging
```

Mặc định transaction read-only, không nhập. Báo scope order-relations-holding-only, counts theo bảng, tổng đơn WEB/CTV/POS, lỗi và fingerprint. Không in mã/ID đơn, hash, tên/email/ghi chú hay người thao tác. Lỗi chỉ có tên bảng/thứ tự dòng đọc/mã, không phải vị trí dòng file SQL. Khi có lỗi không cấp fingerprint; ready=true chỉ xác nhận phạm vi kho chờ này, không phải đơn đã vận hành được.

Chỉ khi tới đợt chuyển dữ liệu và đã xem kết quả:

```bash
php bin/import-order-relations.php --source-db=TAIKHOAN_staging --apply --fingerprint=HASH_DRY_RUN_LIEN_KET
```

Hash riêng theo scope, lõi nguồn và ba bảng liên kết/lịch sử. Apply khóa catalog, đọc khóa chia sẻ staging/metadata cha, khóa lõi và hàng kho chờ; kiểm lại trước khi thêm nguyên nhóm cùng audit order-relations.hold-import. Lỗi rollback toàn bộ, replay không thêm hàng/audit. Hàng đích không nằm trong hash để replay dùng lại token, nhưng luôn được khóa và so sánh; không có chế độ ghi đè/force.

Giới hạn 50.000 dòng vật lý mỗi bảng mỗi bên, tổng metadata 32 MiB; đọc không buffer, metadata CTV/quầy chỉ đọc ID. Tối đa 100 chi tiết nhưng vẫn đếm đầy đủ lỗi đã phát hiện. Exit 0 phạm vi đạt, 2 dữ liệu cần xem lại, 1 tham số/hạ tầng/giới hạn không hoàn tất. Không thay cờ pending, gửi email hoặc ghi thu/tồn/công nợ.

## Đã kiểm và còn thiếu

```bash
node tests/build-order-relation-transfer-fixture.mjs /path/to/node-repository
php tests/order-relation-transfer.php
php tests/order-core-transfer.php
php tests/partner-transfer.php
```

Fixture sinh bằng helper exporter Node đã kiểm SHA-256, có WEB/CTV/POS, actor admin/partner/gateway, note Unicode/khoảng trắng và timestamp microsecond; REVOKE_FILE dùng request_hash UUID theo nguồn. Kiểm cha lõi/metadata thiếu, kênh trùng, CREDIT/voucher sai kênh, hash/actor/note/ngày giờ, cột lạ/order_version tự thêm, schema/capacity/PK, trùng ID/lỗi giới hạn báo cáo, fingerprint đổi, rollback/audit/replay/CLI và hai worker. So sánh toàn bộ cột nguồn–kho chờ, migration replay giữ lịch sử; bảng vận hành/thu/tồn/mail/ledger vẫn rỗng và CTV vẫn pending. Regression lõi đơn và importer CTV chạy với schema mới.

Chưa dùng dump thật/cPanel. Chưa chuyển thu tiền/tham chiếu thanh toán, lưu trữ đơn, giỏ/chia sẻ/điều xe, tồn/lô/vé/file, ledger hoặc cơ chế xuất bản nguyên khối. Lưu được lịch sử thao tác không chứng minh tiền/tồn/file đã chuyển hoặc cho phép mở khóa.
