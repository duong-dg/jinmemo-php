# Import mã tổng chỉ gồm quà và cấp liên kết mới

Đợt 144 bổ sung `--with-gift-bundles` cho bộ nhập nghiệp vụ. Cùng một giao dịch ghi danh mục/tồn hiện tại, toàn bộ đơn quà hợp lệ, mã JB chỉ gồm quà, checkout_members và khoản thu BUNDLE_PAYMENT gốc vào các bảng vận hành PHP. Không chạy lại tạo đơn/thu tiền, không trừ hoặc hoàn tồn lần nữa.

**Chưa nhận mã tổng ghép thêm vé hoặc xe.** Có mã ghép liên quan quà thì cả scope quà bị chặn, không tách quà ra khỏi mã tổng. Đơn quà có sổ thu/hoàn riêng ngoài mô hình được kiểm cũng vẫn chặn.

## Cập nhật schema

Backup trước, rồi tại thư mục PHP trên hosting:

```bash
git pull origin main
php bin/install.php
```

Installer tự chạy schema 062; không cần chọn file SQL trong phpMyAdmin. Schema mới thêm cờ chờ hoàn tất chuyển dữ liệu, cho phép access_hash NULL khi chưa có khóa nguồn, bảng provenance `jingo_bundle_transfers` và giữ UTF-8/sáu chữ số phần giây cho sổ tham chiếu thu. Mã PHP cũ có sẵn giữ cờ mặc định 0. Không chạy import thật trong quá trình còn phát triển.

## Trình tự khi chuyển thật

Chốt dừng ghi Node, backup, lấy export full-v2 nguyên bản và copy/import ảnh như quy trình chung. Chạy dry-run:

```bash
php bin/import-business-settings.php --file=/home/USER/private/export.sql --scope=gifts --with-gift-bundles
```

Cờ này bao gồm nhập đơn quà. Sau khi duyệt, chạy lại cùng file/scope/cờ với `--apply --fingerprint=GIÁ_TRỊ_TỪ_DRY_RUN`. Scope `all` có thể nhận thêm cấu hình thanh toán thủ công cùng giao dịch.

Mã tổng được giữ ID, code, trạng thái PENDING/PAID, giá/tổng, VI/EN, phương thức/hướng dẫn lịch sử, fingerprint, liên hệ, thời gian và quan hệ với quà. Khoản thu giữ tham chiếu, chủ, số tiền và thời gian gốc. Kiểm một thành phần giftId, một membership, đúng codes/amounts và các trường PAID của con khớp mã tổng/khoản thu. Giá/phí lịch sử được đối chiếu với snapshot, không áp giá hiện tại.

Import xong, mã có `lookup_pending=1`, `access_hash=NULL`; SQL không chứa khóa gốc nên không tạo hash giả. Admin thấy trạng thái chờ đối soát. Tra cứu khách, tạo lại mã bằng request cũ, gateway và thu/hoàn mới trên root đều bị chặn. Bộ nhập lịch sử hoàn có đường đọc riêng để đối soát, không mở lại các thao tác này.

Sau khi tất cả các đơn cha và khoản thu trong export đã sẵn sàng, nhập sổ hoàn theo [IMPORT-REFUNDS.md](IMPORT-REFUNDS.md):

```bash
php bin/import-refunds.php --file=/home/USER/private/export.sql
```

Duyệt dry-run và dùng fingerprint để apply. Bộ nhập hoàn kiểm toàn file, nên cha thuộc nhóm khác chưa có vẫn làm bước này dừng; cần hoàn tất các adapter liên quan, không bỏ bản ghi để vượt kiểm tra.

## Cấp liên kết tra cứu thay thế

**Liên kết cũ không thể phục hồi từ export v2.** Sau khi sổ hoàn của root khớp hoàn toàn với snapshot dự kiến, quản trị chủ động cấp khóa mới. Kiểm từng mã:

```bash
php bin/reset-bundle-lookup.php --code=JBXXXXXXXXXX
```

Sau khi duyệt báo cáo, dùng lại mã và fingerprint:

```bash
php bin/reset-bundle-lookup.php --code=JBXXXXXXXXXX --apply --fingerprint=GIÁ_TRỊ_TỪ_DRY_RUN --output=/home/USER/private/lookup-JBXXXXXXXXXX.json
```

- Thư mục riêng phải có sẵn, nằm ngoài dự án và **mọi webroot** của tài khoản; file mới, không ghi đè/symlink. Hosting dùng HTTPS, HTTP chỉ được dùng cho localhost. Không đặt file vào storage/media/public/uploads hoặc nơi khách có thể tải trực tiếp.
- File JSON chứa URL riêng theo đúng VI/EN, dạng `/chot-don#JB:KEY` hoặc `/en/checkout#JB:KEY`; giao diện tra cứu sẵn có nhận được. Báo cáo stdout chỉ trả đường dẫn file, không trả khóa/URL. Quản trị tự kiểm người nhận và chia sẻ liên kết khi phù hợp; công cụ không gửi mail hay tin nhắn.
- Khóa được tạo ngẫu nhiên 256 bit; DB chỉ lưu SHA-256 và bản mã bằng khóa PHP hiện tại. Không thay fingerprint request nguồn. Request tạo cũ không dùng được để vượt khóa đã cấp lại.
- Cấp lại kiểm provenance của mã/thành phần/khoản thu, digest toàn bộ sổ hoàn root và fingerprint trạng thái mới nhất. Dữ liệu đổi hoặc thiếu lịch sử thì dừng. Thành công mới bỏ cờ chờ để dùng luồng PHP bình thường; không tạo khoản thu/hoàn hoặc thay đổi tồn.
- Giữ file riêng và backup khóa mã hóa PHP. Lệnh chỉ xử lý mã nhập còn chờ; chạy lại không tự xoay khóa đã kích hoạt. Nếu lỗi khi commit chưa rõ kết quả, giữ file đã tạo và kiểm lại trạng thái trước khi chạy thêm; không ghi đè file.

## Kiểm và giới hạn

Nhập kiểm full-v2/manifest/PK/FK, schema/khả năng chứa dữ liệu và xung đột đích. Giữ khóa catalog, transaction/audit nguyên tử, fingerprint và replay; bản ghi đích khác nguồn hoặc mất khoản thu đã nhập không được tự sửa. Sau khi dùng mã để thu/hủy/chỉnh tồn, import snapshot cũ sẽ không đặt lại trạng thái hoạt động. Có thể replay không ghi thêm khi dữ liệu còn khớp, kể cả đã cấp khóa mới.

Giới hạn phần bổ sung: tối đa 5.000 mã quà được chọn; metadata bundle/membership/receipt/refund được đọc tối đa 50.000 dòng/32 MiB. Tồn/đơn/ảnh vẫn theo giới hạn và điều kiện các importer trước. TIMESTAMP của bảng runtime có giới hạn thời gian riêng; file ngoài khả năng lưu được báo lỗi.

Đã kiểm local bằng dữ liệu tổng hợp qua helper Node, chưa nhập dữ liệu production. Đây là hoàn thiện luồng quà riêng; chưa chứng nhận hoàn tất mã tổng ghép nhiều phân hệ hoặc toàn bộ việc chuyển dữ liệu. `operationalReady=false` toàn hệ thống vẫn giữ.
