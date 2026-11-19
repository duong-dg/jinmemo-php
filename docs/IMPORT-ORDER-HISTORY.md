# Lịch sử xử lý đơn từ Node

Schema 068 và `bin/import-order-history.php` chuyển lịch sử gốc từ SQL full-v2 vào `jingo_source_order_actions`, liên kết trực tiếp với đơn đang có trong PHP. Admin mở chi tiết đơn sẽ thấy mục **Lịch sử từ hệ thống cũ**, cùng lịch sử xử lý mới của PHP ở mục riêng.

Giữ nguyên ID, người thao tác, tên thao tác, ghi chú, request_hash và thời gian UTC sáu chữ số. Trường request_hash của REVOKE_FILE có thể là UUID file theo mã nguồn Node; giữ nguyên giá trị đó. Không công khai hash, không hiển thị lịch sử cho khách tra cứu, CTV hoặc nhân viên POS.

Nguồn không xuất order_version và action payload đầy đủ. Bộ nhập không suy ra phiên bản từ timestamp hay thứ tự thao tác; không thêm sự kiện giả vào bảng thao tác PHP. Các bước PAY/CREDIT/DELIVER/CANCEL và giao/thu hồi file cũ không được chạy lại. Không gửi thư, ghi thu, ghi công nợ hoặc thay đổi giữ chỗ. Thao tác mới vẫn dùng phiên bản đơn hiện tại của PHP.

## Điều kiện và cách chạy

1. Cập nhật code bằng Git rồi chạy `php bin/install.php` để tạo schema 068. Installer không tự nhập SQL export của khách.
2. SQL phải có manifest EXPORTED cho orders và order_actions, đúng checksum và quan hệ full-v2. Không chạy trực tiếp SQL nguồn bằng phpMyAdmin.
3. Mọi đơn liên quan phải có trong bảng bán hàng PHP và khớp các trường đã xuất, kể cả snapshot/contact/version/trạng thái/thời gian. Chỉ có đơn ở kho chờ chưa đáp ứng điều kiện. Lệnh không tự tạo đơn hoặc cấp khóa tra cứu.
4. Chạy trên database thử nghiệm đã chuẩn bị:

```bash
php bin/import-order-history.php --file=/home/USER/private/export.sql
php bin/import-order-history.php --file=/home/USER/private/export.sql --apply --fingerprint=FINGERPRINT_DRY_RUN
```

Mặc định chỉ đọc. Fingerprint ràng buộc file, trạng thái đơn native (cả khóa hiện hữu) và lịch sử đích. Thiếu/khác cha, trùng ID với thao tác PHP, khác lịch sử hoặc thừa lịch sử nguồn ở đích đều chặn. So sánh JSON theo nội dung, không phụ thuộc thứ tự thuộc tính; ghi chú không bị trim hoặc đổi nội dung. Giới hạn nguồn 50.000 dòng tổng/32 MiB cho hai bảng đang xét, đích 50.000 dòng/64 MiB lịch sử.

Apply khóa và ghi lịch sử cùng audit trong một giao dịch. Lỗi rollback; chạy lại dữ liệu khớp không tạo audit hoặc lịch sử trùng. Sau khi xử lý đơn bằng PHP, bản export cũ sẽ không ghi đè trạng thái mới.

Mã thao tác nguồn đã nhập được dành riêng: xử lý đơn, ghi công nợ, giao mã kho và đính kèm file từ chối dùng lại mã đó với HTTP 409. Việc kiểm mã cũng áp dụng trong giao dịch giỏ hàng. ID được so sánh không phân biệt hoa/thường như bảng thao tác PHP; nội dung nguồn giữ nguyên cách viết.

Đây là bộ nhập lịch sử có đơn cha sẵn, chưa phải bộ chuyển toàn bộ đơn vé–thanh toán–tồn–công nợ. `operationalReady=false` và trạng thái toàn hệ thống chưa hoàn tất vẫn giữ. Chưa nhập dữ liệu thật trên hosting. PDF tiếp tục là file gốc, không phân tích hay tạo lại.

## Kiểm chứng local

Đợt 155: bộ hồi quy 187 bài đã qua sau khi sửa bài HTTP phụ thuộc thời gian và chạy lại bài lỗi; báo cáo local giữ lịch sử lần lỗi trước. Bài giao diện lịch sử mới chạy riêng cũng qua, tổng 188 bài độc lập. Chưa kiểm dữ liệu/tài khoản thật trên cPanel.

`tests/order-history-transfer.php` kiểm file full-v2 từ helper Node đã ghim: dữ liệu gốc, thời gian sáu chữ số, tham chiếu file UUID ở REVOKE_FILE, thiếu/khác cha, schema, ID đụng thao tác PHP, fingerprint, thay đổi khóa cha, rollback, nhập đồng thời, CLI và chạy lại. Bốn luồng xử lý đơn/ghi công nợ/giao mã kho/đính kèm file đều từ chối mã nguồn đã nhập. Thao tác PHP mới vẫn tiếp tục đúng phiên bản; bước nhập không phát sinh thu tiền, công nợ, giữ chỗ, vé hoặc mail.

Kiểm HTTP xác nhận admin đọc lịch sử, khách không thấy lịch sử nội bộ và ID viết hoa vẫn bị chặn. Kiểm giao diện xác nhận ghi chú được hiển thị như văn bản, giữ khoảng trắng/Unicode và không có nút phát lại sự kiện. Bài HTTP tổng hợp được sửa để phép kiểm quota độc lập với thời gian các phần trước chạy; vẫn kiểm ngưỡng 30 request, request tiếp theo bị chặn và cửa sổ hết hạn được đặt lại. Giới hạn của ứng dụng không thay đổi.
