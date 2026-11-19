# Import đơn xe riêng cùng lịch sử hoàn tiền

Đợt 148 bổ sung `--with-refunds` cho lệnh import xe đã thu SePay. Kế hoạch, đơn xe, khoản thu, sự kiện và các dòng hoàn lịch sử được ghi trong **cùng một transaction**. PHP đọc ngay đúng số đã hoàn và số còn có thể hoàn; không có bước công bố đơn đã thu trước rồi mới bổ sung sổ hoàn.

## Cập nhật hosting

Backup rồi chạy trong thư mục PHP:

```bash
git pull origin main
php bin/install.php
```

Đợt này không thêm file SQL mới; dùng schema hoàn tiền hiện có và schema 064 của đợt trước. Installer cập nhật các schema còn thiếu, không cần chọn từng file SQL trong phpMyAdmin.

## Chạy thử khi chuẩn bị chuyển thật

SQL full-v2 đã kiểm manifest/checksum/quan hệ nằm ngoài webroot. Chuẩn bị kế hoạch/ảnh và các điều kiện của [xe đã thu SePay](IMPORT-PAID-RIDES.md), rồi chạy dry-run:

```bash
php bin/import-rides.php --file=/home/USER/private/export.sql --with-paid-qr --with-refunds
```

`--with-refunds` yêu cầu `--with-paid-qr`; lệnh mặc định và lệnh chỉ có `--with-paid-qr` vẫn chặn nguồn có lịch sử hoàn. Đến lần chuyển thật, cùng file và cả hai flag mới thêm `--apply --fingerprint=GIÁ_TRỊ_DRY_RUN`. Hiện chưa yêu cầu chạy apply trên hosting; toàn bộ chuyển đổi vẫn đang phát triển. Không import SQL nguồn trực tiếp vào database PHP.

Báo cáo thêm `refundRows`, `matchingRefunds`, `insertedRefunds`; không trả thông tin người nhận, request fingerprint hoặc mã giao dịch hoàn. `operationalReady=false` vẫn giữ.

## Phạm vi và điều kiện

- Nhận các dòng `refunds.root_kind=RIDE` có ID/mã cha khớp đơn WEB/PRIVATE độc lập đã thu SePay trong cùng file. Không nhận hoàn cho xe ghép, POS, mã tổng hoặc đơn tiền mặt chưa có adapter khoản thu.
- Hỗ trợ hoàn một phần, nhiều lần và hoàn hết; tổng lịch sử của mỗi đơn không vượt khoản thu gốc. Giữ nguyên ID, root, amount, refunded_on, reference_key, JSON, actor, request fingerprint và created_at sáu chữ số phần giây.
- Dùng chung validator/schema với `import-refunds.php`: phương thức BANK/CASH/GATEWAY hợp lệ, ngày không ở tương lai, tham chiếu chuẩn hóa, UUID, payeeType CUSTOMER, đủ nội dung lịch sử. CASH ở đây là **hình thức hoàn đã được ghi nhận**, không phải nhập biên nhận thu tiền mặt hoặc tự ghi thu/chi ca quầy.
- Manifest phải xác nhận xuất bảng refunds. Thiếu bảng không xem là chưa từng hoàn. Cha chưa có khoản thu đúng, root ID/mã/kind lệch, trùng tham chiếu hoặc hoàn quá số tiền đều chặn cả batch; không bỏ dòng để nhập phần còn lại.
- Các dòng hoàn của phân hệ khác không được nhập bởi flag này; giữ nguyên ở nguồn/kho chờ để adapter tương ứng xử lý. Tham chiếu trùng với dòng hoàn xe vẫn chặn. Dùng `import-refunds.php` cho toàn bộ sổ hoàn khi tất cả cha/receipt đã sẵn sàng.

## Đích và khả năng chạy lại

Đối chiếu mọi dòng hoàn liên quan theo ID, reference_key và mã/ID đơn. Dòng có mã xe khớp nhưng ID hoặc kind sai cũng chặn. Các dòng đích khác nguồn, phát sinh thêm sau dry-run hoặc trùng ID/tham chiếu ở root khác đều không được ghi đè. Lịch sử của phân hệ khác không bị sửa/xóa.

Fingerprint gắn nguồn, phạm vi hai flag, kế hoạch/đơn/receipt/events và trạng thái sổ hoàn đích. Apply khóa catalog và kiểm lại trong transaction; lỗi ở ledger, refund hoặc audit rollback toàn bộ graph. Chạy lại nguồn khớp không nhân đôi dòng hoàn. Giữ giới hạn nguồn 50.000 dòng/32 MiB của import xe; kiểm đích tối đa 50.000 dòng hoàn/64 MiB JSON.

Gửi lại request hoàn Node nguyên gốc trả về dòng đã có, không tạo thêm hoàn hoặc audit. Luồng hoàn mới của PHP vẫn giới hạn theo số tiền còn lại; báo cáo thu–hoàn đọc được gross/refunded/net từ dữ liệu vừa nhập. Import không gọi provider, chuyển tiền, tạo QR, gửi mail, tăng version ca hay thay trạng thái đơn.

Đã kiểm bằng fixture tổng hợp theo schema request Node đã ghim và helper export, chưa dùng dữ liệu production hoặc xác nhận ngân hàng/cPanel. Xe ghép, ca/quầy/tiền mặt, POS và mã tổng ghép nhiều phân hệ vẫn còn cần chuyển tiếp.
