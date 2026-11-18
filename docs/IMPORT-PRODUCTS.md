# Import sản phẩm và gói vé từ export Node — đợt 131

Đợt 132 bổ sung lệnh riêng import-price-groups.php gồm cả nhóm giá CTV trên cùng engine, xem IMPORT-PRICE-GROUPS.md. Lệnh import-products.php ở tài liệu này vẫn giữ phạm vi cũ.

**Cập nhật hosting:** sau git pull, import `database/055-product-time-precision.sql` bằng phpMyAdmin hoặc installer theo quy trình hiện có. Cột jingo_products.updated_at được nâng lên DATETIME(6), giữ giá trị hiện có và mặc định thời gian mới có microsecond; thao tác sửa sản phẩm cũng ghi UTC_TIMESTAMP(6). Không tự nhập dữ liệu khi cập nhật.

Importer mới dùng chung engine của importer danh mục: **bin/import-products.php**. Phạm vi gồm loại sản phẩm/điểm đến/nhà cung cấp/quầy, sản phẩm (gói vé và giá trong JSON) cùng alias TYPE/DESTINATION/DESTINATION_EN/PRODUCT. Có thể nhập một nhóm nguyên khối vào DB đích trống hoặc chạy sau importer danh mục; dữ liệu đã khớp chỉ được kiểm, không ghi đè/tăng version. Không bao gồm price_groups của CTV, đơn, tồn, lô nguồn cung, công nợ hoặc ledger.

## Giữ đúng trường nguồn

`jm_transfer_products` phải có đúng **id, slug, data, version, updated_at** như mẫu export nguồn trong TRANSFER-EXPORT-SCHEMA.json (revision 7e3578d). Đích jingo_products phải cùng cột và đủ capacity, updated_at là DATETIME(6). Giữ ID/slug, JSON gốc, version cột và UTC microsecond; không đổi tên trường, gọi save(), tạo ID hoặc tính lại giá để ép nhập. JSON version cũ hơn version cột vẫn được giữ, runtime đọc version cột như Node.

Trong JSON giữ variants/prices/conditions/timeSlots/schedulePeriods/saleWindow, rules/closures, onlineDiscount, English/sourceHash, SEO và các cấu hình tour/service/transport/location được schema PHP hỗ trợ. Dùng ProductSchema hiện có để kiểm kiểu, giới hạn giá, trùng ID gói/lịch, rule tham chiếu đúng sản phẩm/gói/nhóm khách và ngày/khung giờ. Không tái tạo gói vé thành bảng khác hoặc phân bổ tồn khi nhập sản phẩm.

Loại sản phẩm/nhà cung cấp/điểm đến phải tồn tại trong tập danh mục nguồn+đích. Sản phẩm PUBLISHED cần các tham chiếu đang active, gồm toàn bộ cha điểm đến. Sản phẩm DRAFT/ARCHIVED được giữ tham chiếu đến danh mục archived nếu còn tồn tại, không tự bật lại danh mục hay mở bán. Kiểm slug hiện tại và alias khác chủ; giữ alias PRODUCT cũ để URL vẫn đọc đúng.

Cover/gallery và ảnh trong detailHtml Việt/English cần metadata active và file WebP hiện có đúng bytes/kích thước. Kiểm hash vào fingerprint và đọc lại trước khi ghi. JSON phải có cover/gallery để runtime đọc được. HTML phải qua bộ làm sạch mà không đổi nội dung; nếu phải bỏ tag/attribute hay sửa định dạng thì báo INVALID_RECORD để đối chiếu nguồn, **không tự sửa rồi nhập**. Không tự đổi trạng thái bản dịch/xuất bản, không copy hoặc viết lại file ảnh/PDF.

## Cách dùng khi đã sẵn sàng nhập

Theo IMPORT-CATALOG.md, chuẩn bị staging riêng từ SQL Node, DB PHP schema **055**, quyền đọc staging, metadata/file media trước nếu có ảnh. Không nạp dump Node vào DB vận hành, không chạy bằng dữ liệu thật trước khi hoàn tất kế hoạch nhập tổng thể.

```bash
php bin/import-products.php --source-db=TAIKHOAN_staging
```

Mặc định transaction read-only. Báo cáo scope=catalog-and-products-only, tên DB nguồn/đích, counts/errors/fingerprint; không in JSON hay thông tin liên hệ. `ready` chỉ có nghĩa nhóm sản phẩm/danh mục này đã kiểm đạt, không phải toàn bộ file SQL đã nhập được. Các bảng và alias ngoài phạm vi còn nguyên trong staging, được báo riêng.

Khi đã xem kết quả và đến cửa sổ chuyển hệ thống:

```bash
php bin/import-products.php --source-db=TAIKHOAN_staging --apply --fingerprint=HASH_TU_DRY_RUN_SAN_PHAM
```

Không dùng lại hash của importer danh mục. Hash này bao gồm dữ liệu thuộc phạm vi sản phẩm/danh mục và ảnh được kiểm; thay đổi updated_at cũng làm hash khác. Luôn kiểm lại metadata đích/quan hệ trước apply. Nguồn/file khác hoặc ID đích có metadata/version khác sẽ chặn, không tự gộp/ghi đè. Khóa catalog và các hàng đích, thêm toàn bộ nhóm/alias/audit products.import trong một transaction; lỗi thì rollback. Replay không thêm bản ghi/audit trùng. Không tự thu tiền, gửi mail, trừ tồn hay tạo đơn.

Giữ các giới hạn engine: 50.000 dòng mỗi bảng mỗi bên, 16 MiB tổng metadata nguồn+đích, mỗi ảnh tối đa 10 MiB và tổng ảnh được kiểm tối đa 2 GiB, 100 chi tiết lỗi. Cần dừng nguồn ghi SQL/FTP/app ngoài hệ thống trong lúc chuyển dữ liệu thật. Exit 0: nhóm kiểm đạt; 2: lỗi dữ liệu; 1: lỗi tham số/hạ tầng/giới hạn. --apply bắt buộc fingerprint đã xem.

## Kiểm thử và phần còn lại

Fixture sản phẩm được tạo bằng exporter Node thật và dữ liệu schema fixture nguồn. MySQL riêng kiểm JSON/ID/version/UTC microsecond, dependency thiếu, rule/gói sai, HTML cần làm sạch, gallery thiếu/hỏng/trashed, source thay đổi, audit rollback/apply/replay và không ghi đè target. Runtime đọc qua alias cũ và tính giá bằng lịch đã nhập đúng 550.000 theo fixture. CLI mặc định dry-run; hai worker đồng thời chỉ một worker thêm 10 bản ghi/alias, không ghi audit lặp. Regression danh mục và 25 fixture schema/hash/CRUD sản phẩm đã qua.

```bash
php tests/product-transfer.php
php tests/catalog-transfer.php
php tests/products.php
node tests/build-product-transfer-fixture.mjs /path/to/local/node-repository
```

PHP tests cần JINGO_TEST_DSN trỏ server MySQL thử, tự tạo/xóa DB ngẫu nhiên. Hosting chạy importer không cần Node. Chưa dùng dump vận hành hoặc hosting thật; importer nhóm giá CTV/voucher/đơn/tồn/công nợ/ledger và đối soát toàn hệ thống vẫn còn.
