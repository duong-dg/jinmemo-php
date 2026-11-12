# Nhập bài viết từ MySQL transfer v1

Công cụ CLI chỉ nhập bốn bảng bài viết và alias POST/POST_EN. Đọc staging trên cùng máy chủ MySQL với PHP, giữ nguyên ID, JSON nguồn, revision, thời gian UTC microsecond và BIGINT views. Không chạy file SQL dump trong PHP.

## Chuẩn bị trên cPanel

1. Sao lưu database PHP và giữ bản backup PostgreSQL/file upload cũ.
2. Xuất MySQL (.sql) từ bản Node. Kiểm tra file tải đủ và có dòng cuối `TRANSFER COMPLETE`; công cụ này không xác minh dòng cuối của file vì nó đọc database staging đã nhập. Đối chiếu số dòng trong báo cáo với bản export, không nhập một staging đang dở.
3. Tạo database riêng, ví dụ `mylinhtr_transfer`. Trong phpMyAdmin chọn đúng database đó rồi Import SQL nguyên bản để tạo `jm_transfer_*`. Không đổi tiền tố và không import SQL này vào database PHP.
4. Cấp cho user MySQL đang dùng trong `.env` quyền SELECT vào database staging. User cần đủ quyền vận hành các bảng PHP đích, gồm chèn dữ liệu và locking read, để nhập thật. Hai database phải nằm trên cùng server đã cấu hình trong `.env`; không dùng tên database giống nhau.
5. Cập nhật code và chạy `php bin/install.php`. Các bảng blog đích phải trống: posts, post_categories, post_revisions, post_metrics và alias POST/POST_EN. Dữ liệu module khác và alias PAGE/PRODUCT được giữ nguyên. Nếu đã có bài thử hoặc dữ liệu thật, công cụ sẽ dừng; dùng database đích mới hoặc bản khôi phục phù hợp thay vì xóa bài để ép nhập.
6. Tạm dừng chỉnh sửa bài ở hai bản trong lúc chuyển và giữ staging cố định. Ảnh đang dùng phải được chuyển metadata/file WebP theo ID vào PHP trước; sản phẩm liên quan cũng phải tồn tại. Công cụ báo lỗi nếu thiếu, không bỏ ảnh hay cắt danh sách liên quan để ép chạy. relatedRideIds được giữ nhưng chưa có module xe để hiện liên kết.

Đã có công cụ chuyển metadata ảnh: xem [IMPORT-MEDIA.md](IMPORT-MEDIA.md). Nên hoàn tất ảnh trước khi chạy kiểm tra bài viết. Tên bảng trong notImportedTables chỉ nói phạm vi của mỗi lệnh, không nói dữ liệu đó chưa từng được chuyển bởi lệnh khác.

## Kiểm tra trước, rồi nhập

Trong Terminal, vào thư mục code chứa `.env` và `bin`:

```bash
php bin/import-posts.php --source-db=mylinhtr_transfer
```

Lệnh mặc định chỉ đọc, không ghi bài, sửa staging hoặc ghi audit. Báo cáo JSON có mode/ready/applied, counts, fingerprint, errors, warnings và notImportedTables. Không in nội dung bài, người lưu hay dữ liệu khách hàng. `counts.slug_aliases` chỉ đếm POST/POST_EN nên có thể nhỏ hơn tổng alias trong footer export.

Chỉ nhập khi `ready: true`, số dòng đúng với bản xuất và đã xem warnings:

```bash
php bin/import-posts.php --source-db=mylinhtr_transfer --apply
```

`--apply` kiểm tra lại toàn bộ trong cùng transaction với snapshot nguồn; không dựa vào một kết quả chạy thử cũ. Sau khi xong cần có `applied: true`; mở `/cam-nang`, kiểm bài VI/EN, URL cũ, ảnh và lịch sử. Fingerprint mô tả phần dữ liệu blog đọc từ staging, không thay thế việc đối chiếu dump hoàn chỉnh. Nó cũng được ghi trong audit `post.import` với actor `cli-import`.

## Những gì được kiểm tra

- Bảng InnoDB, đủ và đúng danh sách cột theo contract; không bỏ cột nguồn mới mà công cụ chưa biết. Kiểm độ dài/charset và kiểu đích, DATETIME(6), BIGINT cho views.
- ID/slug/version, JSON phù hợp Posts schema, JSON ID/slug khớp cột nguồn, UTF-8 và thời gian hợp lệ. Chỉ xác thực JSON; không dùng kết quả chuẩn hóa để ghi đè nội dung gốc.
- Trùng bài/slug ngôn ngữ/alias, revision hoặc metrics mồ côi, revision vượt phiên bản hiện tại, lượt xem âm/ngoài BIGINT nguồn.
- Ảnh của bài hiện tại có metadata, file WebP, kích thước và số byte phù hợp; sản phẩm hiện tại tồn tại. Tham chiếu ảnh/sản phẩm trong lịch sử bị thiếu được báo warning vì revision vẫn được giữ nguyên, chưa có tính năng khôi phục revision.
- Không ghi đè bảng blog đã có dữ liệu. Ghi bài, lịch sử, chuyên mục, metrics, alias và audit trong một transaction; lỗi thì hoàn tác. Khóa catalog chặn hai tiến trình nhập hoặc admin lưu blog đồng thời trong lúc áp dụng.

Warnings `rideLinksPreservedWithoutRuntime`, `historicalMediaUnresolved`, `historicalProductsUnresolved` nghĩa là giữ các tham chiếu đó, nhưng giao diện/chức năng liên quan chưa sử dụng được đầy đủ. Không tự chép file hoặc tạo sản phẩm.

Giới hạn đợt này: tối đa 16 MB dữ liệu được đọc và 50.000 dòng mỗi bảng; không có merge, cập nhật bài đã nhập, nhập lẻ theo ID, UI upload, công cụ rollback sau commit hoặc nhập đơn/thanh toán/tồn/voucher. Chạy lại sẽ dừng khi bảng blog đã có dữ liệu, không thêm bản trùng. Các bảng chưa hỗ trợ được liệt kê trong notImportedTables và giữ nguyên ở staging.

Mã thoát: 0 = kiểm tra đạt/nhập xong, 2 = có lỗi được nêu trong báo cáo, 1 = lỗi tham số/kết nối/quyền/giới hạn hoặc lỗi thực thi. Nếu nhập thất bại do lỗi hệ thống, kiểm tra database và báo cáo trước khi thao tác tiếp; không dùng tùy chọn bỏ qua lỗi.
