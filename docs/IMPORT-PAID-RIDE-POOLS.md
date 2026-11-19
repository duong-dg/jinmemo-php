# Graph xe riêng/nhóm ghép đã thu SePay và hoàn tiền

Trong đợt gộp 153, `--with-paid-pools` tự mở cả nhóm active/closed và receipt SePay, cùng xe riêng. Có lịch sử hoàn thì thêm `--with-refunds` để nhập cùng transaction:

```bash
php bin/import-rides.php --file=/home/USER/private/export.sql --with-paid-pools --with-refunds
```

Đây là dry-run, chưa ghi dữ liệu. Khi đến lần chuyển thật mới giữ nguyên file/phạm vi và thêm `--apply --fingerprint=GIÁ_TRỊ_DRY_RUN`. Hiện chưa thực hiện lần import production. Điều kiện ảnh/kế hoạch, schema 063–065, full-v2/manifest, row limits và nguồn/đích của các adapter xe vẫn áp dụng; đợt này không thêm SQL cho xe.

Một graph gồm kế hoạch, toàn bộ pool/thành viên kể cả đã hủy, trạng thái thu, tham chiếu `RIDE_PAYMENT`, sự kiện SePay và liên kết sự kiện gốc. Mỗi dòng đã thu cần đúng một receipt + event + link, cùng số tiền, reference và ID; event PAID/LIVE, không attempt/order giả hoặc manual resolution. Thiếu một phần hoặc có cash/POS/bundle/giao dịch chưa đối soát thì dừng cả batch.

Thành viên chưa hủy đã thu giữ nguyên total/paidAmount. Tính số ghế và tiền đã trả từ chính những dòng này; từng phần chưa thu phải khớp splitShare của `pool.total − paidTotal` trên `pool.target − paidSeats`, theo thứ tự nguồn. Thành viên đã hủy không chiếm chỗ hoặc tham gia phần tiền phân bổ hiện tại, nhưng receipt/refund lịch sử vẫn được giữ. Nhóm kín đã thu hết phải khớp tổng tiền; không chấp nhận tiền/ghế còn lại âm hoặc còn tiền nhưng không còn ghế để đối soát.

Giữ proof Node nguyên gốc và các kiểm snapshot của phạm vi shared. Trường hợp không khôi phục được fingerprint từ snapshot/tập giá chia hợp lệ vẫn chặn, không tự thay hash hay suy đoán thêm payload. Việc export không có QR attempts và một số khóa gốc vẫn cần đối soát trước khi chuyển thật.

Lịch sử hoàn dùng chung validator/cap với sổ hoàn, ghi trong cùng transaction với receipt/parents; không gọi ngân hàng hoặc ghi thu chi ca. CLI/native lookup/refund preview/report đọc đúng số gốc. Đã kiểm fixture gộp 22 đơn/8 nhóm/5 khoản thu/3 khoản hoàn: gross 284, refunded 111, net 173; số liệu là dữ liệu mẫu, không phải tiền production.

Sau nhập, hủy thành viên chưa thu và ghép người mới dùng số ghế/tiền còn lại, không đổi phần đã thu. Khi PHP đã thay đổi graph, file cũ bị chặn nhập lại. Không tạo QR, thu tiền, gửi mail hoặc phát notification trong import. Xem [trạng thái tổng thể](CONVERSION-STATUS.md) cho phần còn thiếu ngoài graph xe.
