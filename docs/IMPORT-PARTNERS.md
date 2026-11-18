# Import hồ sơ tài khoản CTV — đợt 133

Sau git pull, import **database/056-partner-transfer-pending.sql** bằng phpMyAdmin hoặc installer theo quy trình cập nhật. Migration thêm jingo_partners.transfer_pending TINYINT(1), mặc định 0 cho tài khoản PHP hiện có, không đổi hồ sơ/mật khẩu. Không tự import hoặc khóa thêm tài khoản cũ khi cập nhật.

Lệnh mới **bin/import-partners.php** nhập danh mục nền, sản phẩm/gói/alias, nhóm giá và **hồ sơ partners** trong cùng transaction. Chưa nhập đơn, tồn, quan hệ partner_orders, partner_ledger, công nợ hoặc phiên đăng nhập. Đây chưa phải bước đưa CTV cũ vào vận hành.

## Trường nguồn và trạng thái đích

jm_transfer_partners có đúng **id, email, data, version**: exporter Node cố ý bỏ password_hash. Giữ ID/email/JSON/version, groupId và cấu hình creditEnabled/creditLimit/creditDays; không biến hạn mức thành số dư hoặc tạo khoản nợ đầu kỳ. Email trong cột/JSON phải khớp và được chuẩn hóa chữ thường như nguồn; trùng email khác ID sẽ chặn, không tự gộp tài khoản.

Schema Partners hiện có kiểm tên/phone/status/UUID/hạn mức/kỳ hạn; groupId phải tồn tại trong tập nhóm giá nguồn+đích. Tài khoản ACTIVE cần nhóm ACTIVE, PAUSED/DISABLED giữ được tham chiếu nhóm archived nếu còn tồn tại. Cột lạ hoặc password/password_hash/secret trong JSON sẽ bị chặn, không sao chép bí mật tình cờ có trong staging.

**Tài khoản mới nhập chưa được đăng nhập hoặc giao dịch:**

- Ghi transfer_pending=1 và giá trị đánh dấu mật khẩu cần cấp lại. Giá trị đánh dấu không phải password/hash hợp lệ; không tạo hoặc gửi mật khẩu dùng chung.
- Giữ status ACTIVE/PAUSED/DISABLED và cấu hình công nợ nguyên gốc trong JSON. transfer_pending là kiểm soát runtime riêng, không sửa dữ liệu nguồn thành DISABLED để giả trạng thái.
- Login/me bị chặn; tạo đơn, báo giá, ghi nợ, ghi trả nợ và các nhánh ghi của giỏ CTV có kiểm tra trạng thái chuyển dữ liệu. Sổ admin báo transferPending và available=0; giao diện không trình bày số dư chưa nhập như số dư hoàn chỉnh và không có nút ghi trả nợ trong màn hình chờ.
- Admin nhìn thấy “chờ nhập/đối chiếu” và “cần cấp mật khẩu”. Lưu hồ sơ/đặt mật khẩu mới qua chức năng hiện có **không tự xóa transfer_pending**; client cũng không được gửi cờ này để mở khóa.

Đợt này **chưa có bước mở khóa chuyển đổi**. Bước đó phải đi cùng hoàn tất nhập và đối chiếu đơn/sổ công nợ, rồi cấp mật khẩu và kiểm vận hành. Chưa dùng tài khoản mới nhập trước khi các bước ấy hoàn tất. Không nhập scrypt Node vào cột mật khẩu PHP hoặc dùng mật khẩu cũ để suy ra quyền truy cập.

Nếu ID đích đã có và toàn bộ metadata/version khớp, chỉ đếm matchingRows và **giữ mật khẩu, phiên và transfer_pending hiện có**. Không reset mật khẩu hoặc khóa tài khoản PHP đang dùng chỉ vì chạy lại importer. Khác metadata/version thì chặn, không ghi đè. Credentials/cờ runtime không nằm trong fingerprint dữ liệu nguồn và không được copy từ staging.

transfer_pending được khai báo riêng tại TRANSFER-CONTRACT.json → runtimeOnlyColumns.partners; các cột nguồn không bị đổi tên hoặc thêm vào export. Thay đổi mật khẩu qua admin vẫn tăng version/revoke session như trước, nên không chỉnh hồ sơ/cấp mật khẩu giữa các bước nhập nếu chưa đối chiếu lại metadata.

## Sử dụng khi đã sẵn sàng

Theo IMPORT-PRICE-GROUPS.md, giữ backup, kiểm SQL export, nạp vào **database staging riêng** cùng MySQL, chuẩn bị metadata/file media và DB PHP schema **056**. Không nạp dump Node vào database vận hành, không chạy import thật trước khi hoàn tất kế hoạch tổng thể.

```bash
php bin/import-partners.php --source-db=TAIKHOAN_staging
```

Mặc định transaction read-only, không tạo hồ sơ/credential/session. Báo cáo scope=catalog-products-groups-partners-only, tên DB/counts/mã lỗi/fingerprint và chính sách khóa tài khoản mới; không in email/tên/phone/password/hash. Các bảng ngoài phạm vi còn nguyên trong staging. ready=true chỉ nói metadata thuộc nhóm này kiểm đạt, không có nghĩa tài khoản đã đăng nhập được hay công nợ đã chuyển xong.

Chỉ khi tới cửa sổ chuyển dữ liệu và đã xem kết quả:

```bash
php bin/import-partners.php --source-db=TAIKHOAN_staging --apply --fingerprint=HASH_TU_DRY_RUN_HO_SO_CTV
```

Cần hash riêng từ lệnh này. Kiểm lại nguồn/ảnh/metadata đích, giữ khóa catalog/hàng đích; thêm toàn bộ nhóm/alias/audit partners.import trong một transaction. Lỗi rollback toàn bộ. Replay không thêm tài khoản/audit, không đổi credential/cờ. Giữ giới hạn engine 16 MiB tổng metadata, 50.000 dòng mỗi bảng mỗi bên, ảnh 10 MiB/file và 2 GiB tổng, 100 chi tiết lỗi. Exit 0 nhóm kiểm đạt; 2 lỗi dữ liệu; 1 lỗi tham số/hạ tầng/giới hạn. Các lệnh importer cũ không tự mở rộng sang partners.

## Đã kiểm và phần còn lại

Fixture từ exporter Node thật, không có password/session. MySQL/CLI kiểm JSON/version/group/email, cột mật khẩu bất thường, dữ liệu bí mật trong JSON, rollback/replay, fingerprint, giữ credential PHP đã có và hai worker đồng thời không trùng dữ liệu. Kiểm pending chặn login/session/quote/order/repayment, ledger không có hạn mức khả dụng và password reset không tự mở khóa. Node DOM kiểm nhãn chờ/cấp mật khẩu và màn sổ không có nút giao dịch. Regression CRUD/login CTV, báo giá, đơn/giỏ và công nợ/HTTP của tài khoản cũ đã qua. Chưa dùng dump vận hành/cPanel thật.

```bash
php tests/partner-transfer.php
node tests/partner-transfer-ui.cjs
php tests/partners.php
php tests/partner-auth.php
php tests/partner-credit.php
php tests/partner-orders.php
php tests/partner-catalog.php
php tests/carts.php
```

Tests cần JINGO_TEST_DSN trỏ MySQL thử, tự tạo/xóa DB ngẫu nhiên; không chạy trên DB vận hành. Importer đơn/tồn/partner_orders/ledger, đối soát số dư và bước mở khóa chuyển hệ thống vẫn còn.
