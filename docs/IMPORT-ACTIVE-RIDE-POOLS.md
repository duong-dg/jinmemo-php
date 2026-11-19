# Import nhóm xe đang hoạt động, chưa thu tiền

Đợt 151 thêm `--with-active-pools`, tự bao gồm phạm vi [nhóm đã kết thúc](IMPORT-CLOSED-RIDE-POOLS.md). Import kế hoạch, nhóm và thành viên gốc trong một transaction; giữ nguyên chỗ đang chiếm, giá chia, trạng thái, version và thời gian. Không gọi lại tạo booking, syncPool, ghép nhóm hoặc thông báo trong lúc nhập.

## Cập nhật hosting

Backup rồi chạy trong thư mục PHP:

```bash
git pull origin main
php bin/install.php
```

Đợt này không thêm SQL mới; vẫn cần schema 065 giữ UTC microseconds của nhóm và các schema trước. Installer tự cập nhật, không cần chọn từng SQL qua phpMyAdmin.

## Chạy thử khi chuẩn bị chuyển thật

SQL full-v2 đã kiểm manifest/checksum/quan hệ nằm ngoài webroot; chuẩn bị ảnh/kế hoạch như các phạm vi import xe trước.

```bash
php bin/import-rides.php --file=/home/USER/private/export.sql --with-active-pools
```

Không cần thêm `--with-closed-pools`: flag active tự nhận cả nhóm chưa thu đã hoàn tất/hủy. Nếu cùng nguồn có đơn xe riêng đã thu SePay/đã hoàn, thêm `--with-paid-qr --with-refunds` tương ứng. Các flag này chưa mở nhóm ghép đã thu/hoàn, tiền mặt, POS hoặc mã tổng.

Đến lần chuyển thật mới thêm `--apply --fingerprint=GIÁ_TRỊ_DRY_RUN`, giữ nguyên file và phạm vi flag. Hiện chưa yêu cầu apply trên hosting; toàn bộ chuyển đổi vẫn đang phát triển. Không nhập SQL nguồn trực tiếp vào database PHP. `operationalReady=false` vẫn giữ.

## Trạng thái và chỗ

| Nhóm nguồn | Thành viên chưa hủy | Số khách thực tế |
| --- | --- | --- |
| OPEN | WAITING_MATCH | Từ 1 đến target − 1 |
| LOCKED | MATCHED | Bằng target |
| PICKUP | WAITING_PICKUP | Bằng target |
| PICKED_UP | PICKED_UP | Bằng target |
| RUNNING | RUNNING | Bằng target |

Giữ toàn bộ thành viên đã hủy để đối chiếu lịch sử, nhưng không tính vào chỗ đang chiếm. Không có đơn thành viên, thiếu chỗ ở nhóm đã khóa, thừa khách, sai trạng thái hoặc lệch giá chia đều chặn cả batch. OPEN không có người phải là CANCELLED theo luồng Node; không tự sửa trạng thái để nhập.

Nhóm còn hoạt động cần xe/khu vực còn trong kế hoạch hiện tại và ghế xe không thấp hơn target. Chờ đón/đã đón/đang chạy cần tài xế, điện thoại và biển số hợp lệ, đồng nhất giữa các thành viên chưa hủy. OPEN/LOCKED có thể chưa có tài xế; MATCHED của nhóm không bị áp điều kiện tài xế của đơn xe riêng.

Các điều kiện tuyến đúng hai đầu pool, PAY_LATER/UNPAID, nguồn đầy đủ, Node request proof và giới hạn metadata của phạm vi nhóm đóng tiếp tục áp dụng. Giá kiểm theo total/target gốc và thứ tự created_at/ID; không thay bằng giá bán hiện tại. Nhóm đang ghép có thể chưa phân bổ hết tổng tiền, nhưng giá của từng thành viên phải đúng vị trí chia hiện tại.

Không tự gia hạn giờ nhận, đẩy ngày hoặc xóa nhóm cũ. Nếu giờ nguồn đã qua hạn nhận, quy tắc giờ gốc của PHP vẫn áp dụng; operator xử lý nhóm qua luồng điều phối được phép. Nếu cấu hình hiện tại tắt bán hoặc bỏ size ghép, import không tự bật lại để nhận khách.

## Tiếp tục vận hành sau import

Native PHP đọc chỗ từ chính các thành viên vừa nhập. Khi tuyến/size/giờ còn cho phép, báo giá khách tiếp theo dùng tổng tiền nhóm đã lưu. Nhận khách cuối khóa nhóm đúng target; request cũ chỉ trả mã đã có, không thêm khách. Điều phối tiếp từ LOCKED/PICKUP/PICKED_UP/RUNNING và hủy trước đón dùng luồng PHP hiện tại, giữ kiểm version, tài xế và số chỗ.

Nguồn và PHP phải được chốt quyền ghi khi chuyển thật; không chạy hai hệ thống cùng nhận khách/thu tiền. Sau khi PHP đã nhận khách mới, hủy hay điều phối, nhập lại file cũ sẽ bị chặn bởi snapshot/version/fingerprint khác hoặc có dòng ngoài nguồn, không ghi đè trạng thái mới.

Export không chứa QR attempts của Node: chỉ nhìn trạng thái UNPAID chưa đủ chứng minh không còn QR đang chờ. Cần đối soát các QR/giao dịch treo trước khi chuyển thật; import không dựng lại hoặc tự hủy những yêu cầu thanh toán không có trong file.

Apply kiểm lại dưới khóa catalog và transaction InnoDB, fingerprint gồm phạm vi active cùng trạng thái nhóm/đơn. Lỗi ở nhóm, thành viên hoặc audit rollback toàn bộ. Không tạo QR, thu tiền, ghi ca, gọi provider hay phát mail khi import. Chưa nhập dữ liệu production; nhóm có thu/hoàn, POS/ca quầy/tiền mặt, tuyến con chưa hỗ trợ và mã tổng ghép nhiều phân hệ còn cần chuyển tiếp.

Đợt gộp 153 mở riêng nhóm đã thu SePay bằng --with-paid-pools (tự gồm active/closed/private SePay); có hoàn dùng thêm --with-refunds. Xem IMPORT-PAID-RIDE-POOLS.md. Điều kiện chưa nhận nhóm đã thu ở trên áp dụng cho flag chỉ --with-active-pools.
