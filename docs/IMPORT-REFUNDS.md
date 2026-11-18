# Import lịch sử hoàn tiền vào bảng PHP

Đợt 143 thêm `bin/import-refunds.php`, đọc full-v2 và ghi bản ghi lịch sử vào **`jingo_refunds` đang dùng**. Màn sổ hoàn và phép tính số còn có thể hoàn của PHP đọc được các bản ghi này sau apply. Không gọi lại thao tác ghi hoàn, không chuyển tiền, không phát mail hoặc gọi provider.

## Điều kiện trước khi nhập

Đơn cha và khoản thực thu gốc phải đã có trong các bảng vận hành PHP, giữ đúng ID/mã và tham chiếu nguồn. Công cụ không tự nhập đơn cha hoặc bịa khoản thu để vượt kiểm tra. Các loại cha hỗ trợ: ORDER, CART, HOTEL, RIDE, GIFT và BUNDLE.

Manifest cần xác nhận đã xuất 11 bảng: refunds, orders, carts, cart_orders, checkout_bundles, checkout_members, stay_bookings, ride_bookings, gift_orders, order_payments, payment_references. File bị sửa, thiếu bảng nguồn hoặc sai quan hệ/cột/số dòng/checksum sẽ bị chặn.

Mã tổng chưa được chuyển cùng các thành phần/khoản thu vẫn phải hoàn thiện bằng adapter khác. Module này **không khôi phục khóa tra cứu mã tổng** đã bị loại khỏi export. Không nhập riêng bản ghi hoàn ở đơn con để thay cho hoàn trên mã gốc.

## Quy trình khi đến lần chuyển thật

Dừng ghi hệ thống nguồn, backup DB, giữ SQL nguyên bản ngoài webroot, cài schema PHP bằng `php bin/install.php`. Không có migration mới ở đợt này. Chạy dry-run sau khi nhập và đối soát các cha/khoản thu:

```bash
php bin/import-refunds.php --file=/home/USER/private/export.sql
```

Duyệt báo cáo `ready`, số dòng và lỗi. Khi sẵn sàng, chạy lại chính file với `--apply --fingerprint=GIÁ_TRỊ_TỪ_DRY_RUN`. Apply ghi ngay vào bảng vận hành; không chạy trong lúc đang phát triển/test dữ liệu thật.

## Những gì được giữ và kiểm

- Giữ ID, root_kind/root_id/root_code, số tiền, ngày đã hoàn, reference_key, người nhận, người trả, loại người nhận, người thao tác, note, fingerprint nguồn và thời gian sáu chữ số phần giây. Không tăng/đổi trạng thái đơn hoặc tính lại giá/tồn.
- Kiểm định dạng, ngày hợp lệ/không tương lai, số nguyên an toàn, hình thức BANK/CASH/GATEWAY, tham chiếu đã chuẩn hóa và reference_key khớp. Giữ nguyên fingerprint cũ; không suy đoán lại request gốc từ dữ liệu đã thay tham chiếu CASH/chữ hoa.
- Đơn cha nguồn phải khớp ID/mã với cha runtime. Tổng thực thu nguồn và runtime phải khớp; đối chiếu từng ID khoản thu vé hoặc tham chiếu sổ thu, số tiền và chủ khoản thu. Xe/quà còn đối chiếu metadata thanh toán; mã tổng đối chiếu refs/amounts/codes và metadata đã thu. Giỏ kiểm đúng danh sách đơn con.
- Kiểm liên kết cha ở cả nguồn và runtime. Vé thuộc giỏ hoặc xe/quà/giỏ thuộc mã tổng không được nhập hoàn riêng. Tổng lịch sử hoàn của từng root không được vượt thực thu; công nợ chưa thu không được coi là tiền đã nhận.
- Chỉ thêm bản ghi chưa có. Cùng ID mà nội dung/thời gian khác sẽ bị chặn. Có bản ghi runtime nằm ngoài export cũng chặn, tránh ghép hai sổ không được đối soát. Không xóa hoặc ghi đè sổ hiện có.
- Dry-run chỉ đọc. Apply dùng khóa catalog, khóa bản ghi, giao dịch InnoDB và audit nguyên tử. Fingerprint gắn file, trạng thái sổ đích và tiền đã thu/đã hoàn; đích thay đổi thì phải dry-run lại. Hai tiến trình cùng fingerprint chỉ một tiến trình được nhập.
- Apply lại cần dry-run mới; bản ghi trùng không được cộng hoàn/audit lần nữa. Request tạo hoàn cũ vẫn dùng fingerprint gốc để nhận diện replay.

Giới hạn: tối đa 100.000 dòng/64 MiB metadata nguồn được chọn, trong đó tối đa 50.000 bản ghi hoàn/32 MiB JSON hoàn; đích tối đa 50.000 bản ghi/64 MiB JSON hoàn. Báo cáo không trả PII, fingerprint từng khách hoặc payload SQL.

## Tình trạng

Đã kiểm local bằng fixture tổng hợp qua helper export Node, sáu nhóm cha, khoản thu/ngày/cap, rollback, replay và hai tiến trình đồng thời. Chưa nhập dữ liệu production. Toàn bộ chuyển dữ liệu vẫn `operationalReady=false`; cần hoàn tất adapter cha/mã tổng và xử lý liên kết tra cứu trước khi mở vận hành.

Xem [quy trình hai bên](EXPORT-IMPORT-COORDINATION.md), [import đơn quà độc lập](IMPORT-GIFT-ORDERS.md) và [kho chờ toàn bộ dữ liệu](FULL-TRANSFER.md).

Đợt 144 hỗ trợ cha mã tổng chỉ gồm quà ở trạng thái chờ đối soát (IMPORT-GIFT-BUNDLES.md). Bộ nhập lịch sử có đường đọc riêng trong transaction; API thu/hoàn thông thường vẫn bị chặn cho đến khi sổ hoàn khớp và quản trị cấp liên kết mới.
