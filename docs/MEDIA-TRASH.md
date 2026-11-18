# Thùng rác và khôi phục ảnh — đợt 129

Sau `git pull origin main`, import **database/054-media-trash.sql** bằng phpMyAdmin hoặc dùng installer theo quy trình cập nhật hiện có. Migration thêm `jingo_media.deleted_at DATETIME(6) NULL`, chạy lại được; ảnh cũ giữ trạng thái đang hoạt động. Không nhập dữ liệu thật hoặc sửa file ảnh.

Admin **Thư viện ảnh** có Xem thùng rác/Về thư viện; upload chỉ hiện ở thư viện. Mỗi ảnh có bước xác nhận đưa vào thùng rác hoặc khôi phục. Lỗi giữ màn hình để quay lại/thử lại; màn hình admin cũ không gửi thao tác sau khi rời mục. Bộ chọn ảnh chỉ lấy ảnh active.

`GET /api/v1/admin/media` trả tối đa 100 ảnh active; `?trash=1` lấy ảnh trong thùng rác, thứ tự created_at/id giảm dần. Chỉ nhận trash=0/1 dạng chuỗi. `POST /api/v1/admin/media/:id/trash` nhận đúng `{trashed: true|false}`, cần phiên admin, Origin/header mutation, không query/field bổ sung. Audit media.trash/media.restore cùng transaction.

Trước khi trash, giữ khóa catalog và hàng media rồi kiểm ID trong JSON của **sản phẩm, bài viết, bản lưu bài viết, điểm đến, trang, settings, cơ sở lưu trú, snapshot đơn vé, đơn quà và bundle**. Có tham chiếu trả 409, kể cả lịch sử; không tự gỡ ảnh khỏi nội dung/đơn. Kiểm bảo thủ theo chuỗi ID không phân biệt hoa/thường, cùng phạm vi nguồn Node.

Ảnh trong thùng rác bị chặn khi validate tham chiếu mới. **Không xóa WebP/JPEG vật lý hoặc thu hồi URL công khai cũ**, giống nguồn; ảnh/share vẫn được phục vụ. Đây là quản lý thư viện, không phải xóa an toàn hay ẩn dữ liệu đã chia sẻ. Restore không viết lại file hoặc đổi ID.

## Import ảnh

Contract bổ sung đúng cột nguồn deleted_at. Importer nhận export mới 9 cột và giữ UTC/microsecond/trạng thái trash, vẫn nhận nguồn cũ 8 cột với deleted_at=NULL. Đích cần schema 054 và DATETIME(6). Không bỏ cột lạ, không ghi đè trạng thái/metadata khác; nguồn cũ thiếu trạng thái nhưng target đã trash sẽ báo mismatch, không tự khôi phục. File vẫn kiểm bytes/hash và giữ nguyên.

Dry-run vẫn mặc định; không tự import khi cập nhật. Đây chỉ là metadata ảnh, chưa phải importer tổng thể danh mục/đơn/tồn/ledger. Không xử lý PDF.

## Kiểm thử

MySQL riêng đã kiểm 10 kho tham chiếu, input, active/trash/restore, audit rollback, file nguyên byte và migration replay. Import test kiểm 8/9 cột, nhập mới giữ deleted_at microsecond, mismatch không ghi đè, CLI dry-run và hai tiến trình import. HTTP kiểm quyền/method/filter, trash/restore, ảnh thường/share và ảnh đang dùng. Node DOM kiểm review/lỗi/quay lại/restore/màn hình cũ. Chưa kiểm layout/cPanel thật.

```bash
php tests/media-trash.php
php tests/media-transfer.php
php -d extension=gd tests/http.php
node tests/media-trash-ui.cjs
node tests/admin-editor-ui.cjs
```

PHP integration tests cần JINGO_TEST_DSN trỏ MySQL thử, tự tạo/xóa DB ngẫu nhiên riêng. Không dùng database vận hành.
