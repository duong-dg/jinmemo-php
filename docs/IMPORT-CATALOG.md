# Import danh mục nền từ export Node — đợt 130

Đã thêm importer **loại sản phẩm, điểm đến, nhà cung cấp, quầy** và alias TYPE/DESTINATION/DESTINATION_EN. Đây là một nhóm của importer tổng thể, chưa nhập sản phẩm/gói giá, đơn, tồn vé, công nợ, ledger, khách/tài khoản hoặc settings. Các bảng/alias ngoài phạm vi giữ nguyên trong staging và được báo riêng. Không tự chạy import khi git pull.

Giữ ID, slug, JSON nguồn (gồm English và sourceHash), phiên bản cột và đường dẫn cũ. Không gọi API save để tạo lại danh mục, tăng version, tạo ID hoặc thay nội dung nguồn. JSON được so theo nội dung/kiểu, không coi thứ tự key hoặc khoảng trắng là sửa nội dung; JSON lưu mới dùng dữ liệu staging nguyên gốc. Phiên bản nằm trong JSON có thể cũ hơn version cột của Node; giữ cả hai như nguồn, runtime đọc version cột.

## Chuẩn bị

1. Chưa chạy với dữ liệu thật cho đến khi hoàn tất toàn bộ kế hoạch nhập. Giữ bản backup export và file vật lý gốc.
2. Kiểm file bằng check-transfer.php/--relations theo TRANSFER-FILE-AUDIT.md, tạo **database staging riêng** trên cùng MySQL rồi nạp SQL Node vào staging. Không nạp dump vào database PHP đang chạy và không đổi prefix bằng tìm/thay thế. Công cụ này không thực thi file SQL.
3. Database PHP đã cài schema hiện tại **054**, user trong .env có quyền đọc staging. Staging cần các bảng jm_transfer_product_types, jm_transfer_destinations, jm_transfer_suppliers, jm_transfer_counters, jm_transfer_slug_aliases; bảng trống được chấp nhận, thiếu bảng/cột hoặc có cột lạ sẽ chặn.
4. Nếu điểm đến có ảnh bìa, chuyển metadata ảnh bằng importer ảnh và đặt WebP ID.webp nguyên gốc vào MEDIA_DIR trước. Ảnh phải active, đúng bytes/kích thước, đọc được và đúng hash của lần kiểm; không tạo lại ảnh hoặc ID để ép nhập.

## Kiểm tra trước khi nhập

Trong thư mục PHP trên hosting:

```bash
php bin/import-catalog.php --source-db=TAIKHOAN_staging
```

Đây là **dry-run mặc định**, transaction read-only, không ghi DB hoặc file. Báo cáo JSON có sourceDatabase/targetDatabase để kiểm đúng môi trường; ready/applied, fingerprint, số dòng mới/trùng khớp và mã lỗi theo bảng/thứ tự dòng, không in tên/email/ghi chú/ID khách hàng. Mã lỗi không chứa giá trị dữ liệu.

Kiểm cấu trúc InnoDB/cột/capacity đích, ID/version và schema PHP, ID/slug trong JSON khớp cột nguồn; không bỏ trường không hỗ trợ. Kiểm trùng ID, ID chỉ khác hoa/thường, slug hiện hành/English/alias khác chủ, alias thiếu đích, cây điểm đến thiếu cha/vòng lặp/quá 10 cấp/cha archived nhưng con active. Giữ danh mục đích ngoài nguồn, không xóa chúng; chúng cũng tham gia kiểm cây/slug.

Nếu ID đã có và toàn bộ metadata/version khớp thì đếm matchingRows; khác thì chặn, **không ghi đè**. Tên/slug hợp lệ nhưng khác ID không được tự gộp. Alias ngoài ba kind trên được đếm ở notImportedAliasRows; sourceRows của slug_aliases là toàn bộ dòng nguồn, selectedRows là số dòng thuộc phạm vi. notImportedTables liệt kê những bảng còn nguyên, không đồng nghĩa đã chuyển chức năng của chúng.

Ảnh bìa được kiểm qua metadata đang active và file WebP thật; hash vào fingerprint và đọc lại trước khi ghi. Không copy/ghi đè/chuyển đổi ảnh/PDF. Giới hạn 50.000 dòng mỗi bảng mỗi bên, tổng metadata nguồn+đích 16 MiB, 10 MiB mỗi ảnh và 2 GiB tổng ảnh được kiểm; báo lỗi thay vì cắt bớt. Tối đa 100 chi tiết lỗi. Cần memory_limit/quota phù hợp với metadata của hosting.

## Áp dụng khi đã sẵn sàng

Chỉ áp dụng sau khi đã xem dry-run, hoàn tất kế hoạch nhập và ngừng nguồn ghi đang vận hành trong cửa sổ chuyển hệ thống:

```bash
php bin/import-catalog.php --source-db=TAIKHOAN_staging --apply --fingerprint=HASH_64_KY_TU_TU_DRY_RUN
```

--apply bắt buộc đúng fingerprint SHA-256 của dữ liệu thuộc phạm vi và các ảnh đang được kiểm. Nguồn/file thay đổi phải kiểm lại; các bảng ngoài phạm vi không được chứng thực bằng fingerprint này. Apply luôn kiểm lại metadata đích/collision/quan hệ, không dựa riêng vào hash.

Khóa catalog và các hàng đích khi áp dụng, ghi toàn bộ nhóm/alias/audit trong cùng transaction. Lỗi thì rollback cả nhóm; không có bản nhập dở. Chạy lại dữ liệu đã khớp không thêm bản ghi/alias/audit lần nữa. Không bật mở bán, thay giá, mở ca, trừ tồn, ghi thu hay gửi email. Cần tự dừng các công cụ SQL/FTP/app ngoài luồng PHP khi chuyển dữ liệu thực tế; lock importer không quản lý hệ thống bên ngoài.

Exit 0: nhóm này kiểm đạt (và applied=true nếu yêu cầu áp dụng); 2: lỗi dữ liệu cần sửa/đối chiếu; 1: lỗi tham số/hạ tầng/giới hạn. ready=true của nhóm này **không phải toàn bộ export đã sẵn sàng**, không thay thế kiểm đơn/tồn/công nợ sau nhập. Không có migration SQL mới trong đợt 130.

## Đã kiểm

Fixture được sinh bằng mysqlCreate/mysqlInsert của exporter Node, không dùng dữ liệu thật. MySQL riêng đã kiểm bốn danh mục/alias, ID/JSON/version nguyên gốc, runtime đọc được qua alias, dry-run không ghi, fingerprint thay đổi, cột lạ, trùng ID, orphan/cycle/slug VI/EN, ảnh thiếu/hỏng, metadata khác, rollback audit và apply/replay. Hai worker cùng nhập chỉ một worker thêm 8 bản ghi/alias, worker còn lại không thêm và không ghi audit lặp. CLI mặc định dry-run đã kiểm. Chưa thử hosting cPanel/dump vận hành thật.

```bash
php tests/catalog-transfer.php
node tests/build-catalog-transfer-fixture.mjs /path/to/local/node-repository
```

PHP tests cần JINGO_TEST_DSN trỏ server MySQL thử, tự tạo/xóa các DB ngẫu nhiên riêng. Node chỉ cần để tái sinh fixture, hosting chạy importer không cần Node/TypeScript.
