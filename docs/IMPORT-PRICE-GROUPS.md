# Import nhóm giá CTV — đợt 132

Thêm **bin/import-price-groups.php**, dùng chung engine với importer sản phẩm/danh mục. Phạm vi gồm loại sản phẩm/điểm đến/nhà cung cấp/quầy, sản phẩm/gói vé/alias và **price_groups**. Chưa nhập partners, mật khẩu/phiên đăng nhập, đơn, công nợ hoặc ledger. Không tự gắn nhóm vào tài khoản, mở bán, thu tiền hoặc ghi công nợ.

Không thêm SQL migration; đích cần schema **055** từ đợt trước. Staging nằm trên cùng MySQL và cần các bảng của IMPORT-PRODUCTS.md cùng jm_transfer_price_groups. Bảng này giữ đúng **id, data, version** sang jingo_price_groups, không đổi ID hoặc tạo lại nhóm qua API save.

## Dữ liệu và kiểm tra

Giữ JSON nguyên gốc: name/status, pricingMode nếu có, discountType/discountValue và rules gồm ID/sản phẩm/gói/nhóm khách/ngày/độ ưu tiên/amount/slotId. Version cột và JSON được giữ riêng như nguồn. Nhóm ARCHIVED không tự active; dữ liệu cũ không có pricingMode vẫn để thiếu, không tự suy diễn/ghi thêm giá trị.

Dùng schema PriceGroups hiện có để kiểm UUID, tiền/phần trăm, ngày, trùng ID và phạm vi/ưu tiên rule chồng nhau. Mỗi rule phải trỏ đến sản phẩm trong tập nguồn+đích, đúng variant và nhóm khách đang có giá. Nếu có slotId, slot phải được khai báo trong timeSlots hoặc schedulePeriods của gói; không tự đổi slot/variant để ép khớp. Đây là kiểm tham chiếu metadata, không mở rộng/đổi công thức tính giá runtime.

Giữ rule trỏ đến sản phẩm archived nếu entity/gói/nhóm khách còn tồn tại, như kiểm tham chiếu khi lưu nhóm hiện có. Không tự bật sản phẩm hoặc thay lịch giá. Metadata/version khác ở đích sẽ chặn, không gộp theo tên, ghi đè hoặc đổi nhóm của CTV đang dùng.

## Cách dùng khi đã sẵn sàng chuyển dữ liệu

Theo IMPORT-PRODUCTS.md: giữ backup, kiểm SQL export, nạp vào **staging riêng**, chuẩn bị media/metadata ảnh trước nếu có. Không import dump Node trực tiếp vào database PHP vận hành. Chưa chạy dữ liệu thật trước khi hoàn tất kế hoạch nhập tổng thể.

```bash
php bin/import-price-groups.php --source-db=TAIKHOAN_staging
```

Mặc định dry-run transaction read-only. Báo cáo scope=catalog-products-price-groups-only, tên DB/counts/mã lỗi/fingerprint, không in nội dung JSON hoặc tên/ghi chú trong nhóm. ready=true chỉ chứng nhận phần thuộc phạm vi này; các bảng/alias còn lại được giữ trong staging và báo riêng.

Chỉ đến cửa sổ nhập thật, sau khi đã xem kết quả:

```bash
php bin/import-price-groups.php --source-db=TAIKHOAN_staging --apply --fingerprint=HASH_TU_DRY_RUN_NHOM_GIA
```

Phải dùng fingerprint từ chính lệnh này, không dùng hash của importer sản phẩm/danh mục. Kiểm lại toàn nhóm và ảnh, giữ khóa catalog/các hàng đích, ghi danh mục/sản phẩm/nhóm giá/alias/audit price-groups.import trong một transaction. Lỗi rollback toàn bộ; replay không thêm bản ghi/audit trùng. Giữ giới hạn engine 16 MiB tổng metadata/50.000 dòng mỗi bảng mỗi bên, ảnh 10 MiB/file và 2 GiB tổng, tối đa 100 chi tiết lỗi. Exit 0 đạt nhóm này, 2 lỗi dữ liệu, 1 lỗi tham số/hạ tầng/giới hạn.

Không tự nhập khi git pull. Những lệnh import-catalog.php/import-products.php cũ không tự mở rộng sang nhóm giá; có thể chạy importer mới sau chúng nếu metadata đã nhập vẫn khớp. Cần dừng nguồn ghi SQL/FTP/app bên ngoài trong lúc chuyển hệ thống thật.

## Đã kiểm

Fixture tạo bằng exporter Node thật. MySQL/CLI riêng kiểm DIRECT/DISCOUNT/legacy/ARCHIVED, JSON/version nguyên gốc, thiếu sản phẩm/gói/nhóm khách/slot, duplicate/overlap/discount sai, source thay đổi, rollback/audit/replay và không ghi đè target. Sau nhập, helper giá CTV hiện có trả đúng 500.000/495.000/540.000 cho ba fixture. Hai worker đồng thời chỉ một worker thêm 14 bản ghi/alias; không tạo partner/order/ledger. Regression importer sản phẩm và CRUD/schema nhóm giá cũng đã qua. Chưa thử dump vận hành hoặc cPanel thật.

```bash
php tests/price-group-transfer.php
php tests/product-transfer.php
php tests/price-groups.php
node tests/build-price-group-transfer-fixture.mjs /path/to/local/node-repository
```

PHP integration tests cần JINGO_TEST_DSN trỏ server MySQL thử và tự tạo/xóa DB ngẫu nhiên; không dùng DB vận hành. Hosting chạy importer không cần Node. Phần tài khoản CTV, đơn/tồn/công nợ/ledger và đối soát vẫn còn.
