# Trạng thái chuyển đổi tổng thể

Đợt 156 bổ sung [bộ nhập lô nguồn và sổ nhà cung cấp](IMPORT-SUPPLY.md): năm bảng lô/phân bổ/mua theo đơn/mã thao tác/khoản trả BANK-CASH được nhập cùng giao dịch, giữ nguyên giá vốn và công nợ. Cần sản phẩm, nhà cung cấp và đơn native khớp; chưa tự xuất bản đơn vé hoặc chuyển kho mã vé.

Đợt 155 bổ sung [lịch sử đơn gốc](IMPORT-ORDER-HISTORY.md), schema 068: nhập full-v2 khi đơn native đã khớp, admin xem được người thao tác/ghi chú/thời gian gốc. Giữ mã thao tác cũ để chặn thực thi lại ở xử lý đơn, công nợ, giao mã và đính kèm file; không tự suy ra phiên bản hay phát lại thu tiền/gửi thư.

Đợt 154 bổ sung [bộ nhập tồn vé](IMPORT-INVENTORY.md): cấu hình, tổng chỗ theo ngày/suất và phân bổ giữ/trả nguyên bản. Có dry-run, fingerprint, giao dịch nguyên tử, chống ghi đè và kiểm cha sản phẩm/đơn native. Phần này chỉ chạy khi cha khớp; chưa thay cho luồng xuất bản toàn bộ đơn vé, kho mã vé hoặc lô nguồn/công nợ.

Đợt gộp 153 dùng bản Node `9fb617071c212df641ecfbaced08961b8f7eed72`: 70 controller, 270 khai báo API; inventory là danh mục nguồn, chưa chứng nhận hành vi tương đương mọi API. File export full-v2 có 68 bảng nguồn và manifest. Không coi việc giữ đủ bảng trong kho chờ là đã nhập đủ vào bán hàng.

## Đã hoàn thiện thêm trong đợt này

| Phần | Kết quả |
| --- | --- |
| Xe riêng + nhóm ghép thu SePay + lịch sử hoàn | Nhập chung kế hoạch/nhóm/thành viên/receipt/event/refund bằng `import-rides.php --with-paid-pools --with-refunds`; giữ phần đã thu, chia phần chưa thu theo số ghế còn lại. Có kiểm đối soát, rollback, chạy lại và tiếp tục hủy/ghép sau nhập. Cash/POS/bundle/legacy/tuyến con chưa được mở |
| Lịch sử liên hệ | `import-contact-requests.php`: NEW/DONE/TRASH, Việt/Anh, JSON, ID và microseconds; admin xử lý tiếp và khách gửi lại request cũ không tạo trùng. Không gửi mail hoặc tăng bộ đếm hạn mức khi nhập |
| Chính sách công khai Việt/Anh | API `/api/v1/site/policies`, bảng vai trò chính sách và nội dung mặc định đúng bản Node. Installer tạo 7 trang/vai trò, 6 trang công khai, thông tin đơn vị vận hành giữ nháp. Không ghi đè chỉnh sửa hoặc phục hồi trang đã ẩn/xóa; giữ URL cũ và tránh chiếm English URL của chủ website |
| Kênh liên hệ | Trang Liên hệ và admin có Facebook, WhatsApp, Zalo, Messenger; kiểm HTTPS/host/path/quốc gia điện thoại, giữ tương thích dữ liệu 5 trường cũ và hiển thị VI/EN |
| Công nợ CTV đồng thời | Sửa nâng khóa đọc thành khóa ghi gây deadlock khi ghi nợ/trả nợ đồng thời; giữ kiểm transfer_pending, hạn mức và giao dịch nguyên tử |
| Rà toàn bộ chuyển đổi | `check-transfer-coverage.php` đối chiếu file/manifest/quan hệ và liệt kê mọi bảng: có adapter giới hạn, chỉ kho chờ, cần reset hoặc không tự phát lại. Admin Dữ liệu chuyển đổi cũng hiện bản đồ khả năng theo số dòng kho chờ |

## Phần chưa hoàn tất để import toàn bộ một lần

- Xuất bản native đơn vé/giỏ/thu tiền/khóa tra cứu, kho mã vé/tồn/lô nguồn/công nợ nhà cung cấp và công nợ CTV đầy đủ. Các adapter kho chờ không thay cho các cha native này.
- Nhập POS/tài khoản nhân viên/quầy–ca/biên nhận tiền mặt và liên kết ledger; chưa khôi phục mật khẩu từ export.
- Nhập đầy đủ cơ sở/phòng/đơn/voucher/giao dịch lưu trú và khóa tra cứu; mã tổng ghép vé–xe–quà và ảnh/PDF gốc đi kèm.
- Các trang/nội dung/cấu hình/lịch sử ngoài phạm vi adapter hiện có. Trang chính sách mặc định trên bản cài PHP không thay cho việc chuyển nội dung chủ website đã chỉnh bên Node.
- Quy trình đặt lại các khóa/mật khẩu không có trong SQL, đối soát QR/giao dịch treo; kiểm SMTP/provider/cPanel và chốt một hệ thống duy nhất ghi tồn/thu/giữ chỗ.

Các dòng trên là công việc còn phải thực hiện, không phải bước đã hoàn tất hoặc sẽ tự làm khi `git pull`. Chưa có lệnh “apply tất cả” được chứng nhận. `allConverted=false`, `publicationReady=false` và `operationalReady=false` vẫn giữ.

## Kiểm tra file của một lần chuyển lớn

```bash
php bin/check-transfer-coverage.php --file=/home/USER/private/export.sql
```

Lệnh không dùng database, không chạy SQL nguồn và không ghi dữ liệu. Exit 2 phản ánh chưa đủ điều kiện xuất bản toàn hệ thống; xem `sourceVerified`, `sourceComplete`, `pendingSourceTables`, `requiresScopeAudit` và `remainingGroups` trong JSON. `PARTIAL_NATIVE` chỉ xác nhận có adapter theo phạm vi, không xác nhận mọi dòng đã hợp lệ. Mã bảng và số dòng được in; nội dung khách, khóa và JSON gốc không được xuất ra báo cáo.

## Cập nhật PHP

Backup rồi `git pull origin main` và `php bin/install.php`. Installer cập nhật tới schema 068 (lịch sử đơn gốc), rồi khởi tạo chính sách mặc định theo bản Node. Đợt 156 không thêm schema mới. Nội dung thông tin đơn vị vận hành vẫn ở nháp để chủ website điền; các trang hiện hữu/đã chỉnh/đã ẩn không bị viết lại. PDF tiếp tục là file gốc, không đọc hoặc tạo lại nội dung.

Đợt này chưa import production. Kết quả kiểm local không thay cho nghiệm thu dữ liệu/tài khoản/hosting thật.
