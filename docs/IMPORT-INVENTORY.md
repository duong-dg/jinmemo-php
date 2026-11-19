# Nhập tồn vé và phân bổ từ full-v2

`bin/import-inventory.php` nhập ba bảng `inventory_configs`, `inventory_buckets`, `inventory_allocations`. Giữ tổng chỗ, ngưỡng báo thấp, phiên bản, số khách, ngày/suất và trạng thái trả chỗ của từng đơn. Không chạy lại đặt chỗ, tính giá, thu tiền hoặc gửi thư.

## Điều kiện

Sản phẩm và đơn liên quan phải có trong bảng bán hàng PHP và khớp dữ liệu nguồn (JSON so sánh theo nội dung, không phụ thuộc thứ tự thuộc tính). Các dòng trong kho chờ không đáp ứng điều kiện này. Lệnh không tạo đơn, không tự cấp khóa tra cứu và không thay đổi khóa hiện hữu. Vì luồng xuất bản toàn bộ đơn vé vẫn chưa hoàn tất, bộ nhập này không thay cho việc chuyển toàn hệ thống.

File phải là full-v2 hợp lệ với manifest khai báo EXPORTED cho cả năm bảng sản phẩm, đơn và ba bảng tồn. Thiếu cha, khác trạng thái/phiên bản/snapshot, khác phân bổ đã tồn tại hoặc thừa dòng tồn runtime đều bị chặn. Allocation legacy thiếu variant chưa được hỗ trợ. Phạm vi hiện tại không gồm kho mã vé, lô nguồn hoặc công nợ nhà cung cấp.

Khi bật giới hạn, tổng phân bổ chưa trả không được vượt cả tồn chung theo ngày và tồn riêng theo suất. Các phân bổ vẫn được giữ khi cấu hình không giới hạn. Allocation đã trả phải thuộc đơn CANCELLED; số khách và sản phẩm/gói/ngày/suất phải khớp snapshot đơn.

## Chạy trên bản thử nghiệm

```bash
php bin/import-inventory.php --file=/home/USER/private/export.sql
php bin/import-inventory.php --file=/home/USER/private/export.sql --apply --fingerprint=GIA_TRI_TU_DRY_RUN
```

Mặc định chỉ kiểm tra, không ghi. Chỉ apply khi `ready=true`; fingerprint ràng buộc file nguồn, cha native và trạng thái ba bảng đích. Dữ liệu thay đổi sau dry-run sẽ chặn apply. Apply ghi ba bảng và audit trong cùng giao dịch; chạy lại dữ liệu khớp không tăng phiên bản hoặc tạo giữ chỗ mới. Sau khi PHP xử lý đơn/tồn, không nhập đè lại bản export cũ.

Đây là công cụ CLI, chưa phải nút nhập toàn bộ trong admin. Chưa chạy dữ liệu thật trên hosting. Chỉ dùng một hệ thống ghi đơn/tồn khi thực hiện cutover đã đối soát.

## Kiểm chứng local

Đợt 154: 32 bài trong `tests/run-suite.mjs --scope=transfer` đều qua trên MariaDB dùng riêng cho test; `tests/inventory.php` cũng qua. Bài mới `tests/inventory-transfer.php` kiểm thiếu/khác cha, schema không đủ, khóa tra cứu thay đổi sau dry-run, dữ liệu nguồn sai, fingerprint, rollback, hai tiến trình nhập đồng thời, CLI, chạy lại, tồn chung/suất và trả chỗ bằng chức năng PHP sau nhập. Báo cáo không xuất snapshot hoặc khóa của khách. Kết quả này chưa chứng nhận mọi module đã chuyển đủ hoặc khả năng chạy trên hosting thật.
