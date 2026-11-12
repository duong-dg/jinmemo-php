# Nhập ảnh từ MySQL transfer v1

Lệnh này nhập metadata ảnh trong `jm_transfer_media`, giữ nguyên các trường id/filename/alt/width/height/bytes/created_by/created_at. File ảnh vật lý không nằm trong SQL. Công cụ không chép, ghi đè, nén lại, đổi tên hoặc tạo ID mới cho ảnh.

## Chuẩn bị

1. Sao lưu database PHP và thư mục ảnh hiện tại. Cập nhật code, chạy `php bin/install.php` để áp dụng `016-media-precision.sql`; migration đổi created_at sang DATETIME(6), giữ nguyên giá trị thời gian cũ và thêm phần microsecond bằng 0 nếu trước đó chưa có.
2. Import file SQL export đầy đủ vào database staging riêng như hướng dẫn [IMPORT-POSTS.md](IMPORT-POSTS.md). Dùng cùng user MySQL đã được cấp SELECT vào staging; user cần đủ quyền vận hành các bảng PHP đích, gồm chèn dữ liệu và locking read. Kiểm file có `TRANSFER COMPLETE` và đối chiếu số dòng, vì công cụ chỉ đọc staging đã nhập, không tự kiểm tra tính đầy đủ của file dump.
3. Từ thư mục media của bản Node/backup, chép các file **`ID.webp` gốc** vào MEDIA_DIR của PHP, mặc định `storage/media`. Giữ nguyên tên UUID và bytes. `filename` trong SQL là tên upload ban đầu, không phải tên file lưu trên ổ đĩa. Không chép ảnh vào thư mục public và không chép file `.tickets` bằng luồng này.
4. Không ghi đè các file PHP đã có khi chép ảnh. Nếu có ID trùng mà nội dung chưa rõ, dừng để đối chiếu backup. Hoàn tất chép file và tạm dừng upload/chỉnh ảnh trong lúc chạy. Công cụ kiểm số byte, kích thước và WebP, rồi tính SHA-256 cho fingerprint; SQL media không có hash gốc nên đây không phải chứng minh nội dung giống tuyệt đối bản backup khi không có checksum nguồn.

## Chạy kiểm tra

Trong Terminal, vào thư mục chứa `.env`:

```bash
php bin/import-media.php --source-db=mylinhtr_transfer
```

Mặc định chỉ đọc. Báo cáo JSON có ready/applied, counts, fingerprint, errors và notImportedTables; không in tên ảnh/alt/người upload hoặc nội dung khách hàng. `sourceRows` là số ảnh nguồn, `newRows` là ảnh mới đã kiểm đạt, `matchingRows` là ID đã có và metadata giống nguồn, `checkedFileBytes` là tổng byte file đã kiểm đạt. Khi có lỗi, không dùng counts để kết luận đã nhập đủ.

Công cụ dừng nếu thiếu file, file hỏng header/khác WebP, sai số byte/kích thước, metadata quá dài, ID không hợp lệ/trùng, cột nguồn khác contract, kiểu đích không giữ được dữ liệu, hoặc ID đích có metadata khác. Không tự cắt chuỗi hay sửa metadata để ép nhập. Chỉ nhận file thường nằm trong MEDIA_DIR, không nhận symlink hoặc đường dẫn từ tên upload.

## Nhập metadata

Khi ready=true, số dòng khớp export và ảnh đã chép ổn định:

```bash
php bin/import-media.php --source-db=mylinhtr_transfer --apply
```

Apply kiểm lại bằng snapshot staging, khóa catalog và metadata đích, kiểm lại hash file trước khi chèn. Chỉ thêm ID mới; giữ ảnh PHP không liên quan và bỏ qua ID có đủ metadata giống nhau. Khác bất kỳ trường nào thì dừng toàn bộ. Ghi metadata mới và audit cùng transaction; lỗi thì rollback metadata, không xóa/sửa các file đã chép trước đó.

Chạy lại với nguồn/file không đổi sẽ báo matchingRows và insertedRows=0, không tạo thêm audit. Hai tiến trình cùng nhập chỉ chèn mỗi ID một lần. Fingerprint gồm metadata và SHA-256 file đã kiểm; audit `media.import` lưu fingerprint khi có dòng mới. Các bảng trong notImportedTables nằm ngoài phạm vi lệnh này, không phải báo cáo tình trạng đã nhập của toàn hệ thống.

Sau khi nhập, mở Thư viện ảnh và kiểm ảnh trên website. Danh sách thư viện hiện lấy 100 ảnh mới nhất; ảnh cũ vẫn tải được theo ID và được giữ trong bài đã có ảnh bìa. Sau đó chạy kiểm tra/nhập bài viết theo IMPORT-POSTS.md. Nếu còn sản phẩm liên quan chưa chuyển, bộ nhập bài viết tiếp tục báo lỗi đó.

Giới hạn: 16 MB metadata đọc cộng nguồn/đích, 50.000 dòng mỗi bảng, 10 MB mỗi file và 25 triệu pixel. Chưa có chọn nhập theo nhóm/ID, cập nhật metadata đã nhập, tự lấy file từ server cũ hoặc nhập file vé riêng tư. Cần giữ backup để khôi phục sau một lần nhập thành công; tool không có lệnh xóa/rollback sau commit.

Mã thoát: 0 = kiểm tra đạt/nhập xong, 2 = lỗi trong báo cáo, 1 = lỗi tham số/kết nối/quyền/giới hạn hoặc thực thi. Đã kiểm trên PHP 8.2/MariaDB 10.4 local riêng, chưa chạy dữ liệu thật trên hosting.
