# Import mã giảm giá vé — đợt 135

Sau `git pull`, chạy **database/057-voucher-transfer-pending.sql** qua phpMyAdmin hoặc installer theo quy trình cập nhật trước khi dùng chức năng voucher. Migration idempotent thêm `jingo_vouchers.transfer_pending` mặc định 0, giữ nguyên mã/lượt dùng của voucher PHP đang có. Không tự nhập dữ liệu cũ.

`bin/import-vouchers.php` nhập metadata voucher vé và danh mục/sản phẩm/alias phụ thuộc trong cùng transaction. Đây là mã giảm giá vé, không phải voucher phòng. Chưa nhập đơn hoặc `voucher_uses`, không suy ra số lượt đã dùng bằng 0 từ việc chưa có lịch sử ở PHP.

## Dữ liệu và điều kiện

Nguồn `jm_transfer_vouchers` có đúng `id,code,data,version`; giữ nguyên ID, mã, JSON và phiên bản cột, kể cả JSON có version cũ khác cột. Không gọi API save để tạo lại ID/tăng version. Schema validator kiểm phần trăm/số tiền, hạn mức, ngày đặt/ngày sử dụng/thứ, bật/tắt, phạm vi ALL/PRODUCTS/VARIANTS. Nhận JSON legacy thiếu scope/targets/ngày sử dụng theo mặc định runtime cũ và giữ JSON gốc.

Mã cột/JSON phải khớp, viết hoa đúng định dạng chuẩn, không tự trim/đổi mã nguồn. Chặn trùng mã khác ID, trùng/collision ID và metadata đích khác nguồn. Phạm vi sản phẩm/gói phải tham chiếu danh mục nguồn+đích tồn tại, không đổi tên gói để ép khớp. Các kiểm ảnh/HTML/sản phẩm/alias của importer sản phẩm vẫn được áp dụng.

**Mọi voucher mới nhập có transfer_pending=1**, giữ `enabled`, maxUses và các trường JSON gốc. Cờ runtime riêng không có trong export, được ghi ở TRANSFER-CONTRACT.json → runtimeOnlyColumns.vouchers. Không sửa `enabled=false` để giả trạng thái chuyển đổi.

- Chặn báo giá, ghi lượt và trả lượt qua dịch vụ voucher bằng HTTP 409 khi đang chờ. Luồng quote/đơn dùng chung kiểm tra này; lỗi rollback transaction, không cấp thêm lượt.
- Admin có nhãn “Chờ chuyển dữ liệu”, thông tin “Chờ đối soát lượt dùng”; không hiển thị 0 lượt như dữ liệu hoàn chỉnh. Form cho chỉnh metadata nhưng giải thích lưu/bật không mở khóa. Không gửi cờ runtime từ form; API từ chối trường lạ để ngăn client tự mở khóa.
- Chưa có bước mở khóa trong đợt này. Phải nhập đơn/lịch sử voucher_uses, đối chiếu lượt chưa released và maxUses, rồi thực hiện quy trình hoàn tất chuyển hệ thống trước khi sử dụng.

Voucher PHP đã tồn tại và metadata/version khớp chỉ được đếm matchingRows; giữ transfer_pending và toàn bộ lượt dùng hiện có. Không khóa lại voucher đang chạy khi replay. Chỉnh metadata qua admin vẫn tăng version như trước, nên cần đối chiếu lại nếu sửa giữa các bước nhập.

## Lệnh khi đến đợt chuyển dữ liệu

Giữ backup, kiểm file export và chuẩn bị staging riêng cùng MySQL theo [import sản phẩm](IMPORT-PRODUCTS.md). Metadata media/file gốc cần hoàn tất trước. Không chạy import thật cho tới khi có đủ importer tổng thể và kế hoạch đối soát theo yêu cầu nhập một lần.

```bash
php bin/import-vouchers.php --source-db=TAIKHOAN_staging
```

Mặc định read-only dry-run. Báo cáo scope `catalog-products-vouchers-only`, số dòng/mã lỗi/fingerprint và `voucherAccessPolicy=NEW_VOUCHERS_PENDING_ORDER_USAGE_RECONCILIATION`. Không in mã giảm giá, tên, ghi chú hay dữ liệu khách. `ready=true` chỉ xác nhận metadata trong phạm vi này; không có nghĩa lượt dùng/đơn đã chuyển hoặc voucher sử dụng được.

Chỉ tại cửa sổ chuyển đổi, sau khi xem kết quả:

```bash
php bin/import-vouchers.php --source-db=TAIKHOAN_staging --apply --fingerprint=HASH_TU_DRY_RUN_VOUCHER
```

Apply cần hash riêng đúng scope; kiểm lại nguồn/đích/ảnh, khóa catalog và hàng đích, ghi nguyên khối cùng audit vouchers.import. Lỗi rollback toàn bộ; replay không nhân bản voucher/audit. Fingerprint metadata không chứa cờ runtime/lượt dùng, nhưng importer cũng không sửa chúng trên hàng matching. Schema/capacity không đúng bị chặn; không đọc thêm bảng để giả đã hỗ trợ.

`jm_transfer_voucher_uses` cùng các bảng ngoài phạm vi tiếp tục nằm trong `notImportedTables`, không nhập, xóa hoặc đổi released. CLI cũ import-products/import-partners/import-price-groups không tự mở rộng sang voucher. Scope voucher không trộn nhóm giá/CTV trong constructor để báo cáo rõ phạm vi.

Giới hạn engine giữ nguyên: 50.000 dòng mỗi bảng mỗi bên, tổng metadata 16 MiB, tối đa 100 chi tiết lỗi; ảnh 10 MiB/file và 2 GiB tổng. Exit 0 metadata đạt, 2 cần xem dữ liệu, 1 tham số/hạ tầng/giới hạn không hoàn tất.

## Kiểm thử

```bash
node tests/build-voucher-transfer-fixture.mjs /path/to/node-repository
php tests/voucher-transfer.php
node tests/voucher-transfer-ui.cjs
php tests/vouchers.php
php tests/orders.php
php tests/product-transfer.php
php tests/price-group-transfer.php
php tests/partner-transfer.php
node tests/admin-editor-ui.cjs
```

Fixture sinh từ helper exporter Node đã kiểm SHA-256: voucher ALL legacy, theo gói với lịch sử dụng, FIXED tắt, cột version khác JSON; bảng uses có lượt còn hiệu lực và đã released. Kiểm dry-run không ghi, ID/code/JSON/version, thiếu sản phẩm/gói, trùng mã, metadata/schema/fingerprint, rollback audit, apply/replay, giữ trạng thái và lượt voucher PHP, migration replay, hai worker và CLI. Kiểm pending chặn quote/allocate/release, lưu/bật không mở khóa, form bỏ cờ runtime và danh sách không báo lượt 0 sai.

Các kiểm PHP cần JINGO_TEST_DSN MySQL thử, tự tạo/xóa DB ngẫu nhiên. Node DOM chạy đầy đủ script admin/form; chưa kiểm layout/trình duyệt/cPanel thật. Không dùng dump thật, không import dữ liệu vận hành. Importer đơn/tồn/voucher_uses/ledger và quy trình đối soát/mở khóa vẫn còn.

Đợt 136 bổ sung [import lịch sử lượt dùng](IMPORT-VOUCHER-USES.md), chạy sau khi đơn/voucher cha đã được chuyển và khớp. Giữ released và hạn mức, không tự tạo đơn hoặc xóa transfer_pending. Migration 058 giữ timestamp đơn microsecond. Importer đơn tổng thể còn thiếu nên chưa chạy chuỗi này trên dữ liệu thật.
