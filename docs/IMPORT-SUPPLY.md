# Nhập lô nguồn và công nợ nhà cung cấp

`bin/import-supply.php` nhập nguyên tử năm bảng full-v2:

| Bảng nguồn | Dữ liệu giữ lại |
| --- | --- |
| supply_lots | Lô hữu hạn và nguồn mua theo đơn, giá vốn, số lượng, ngày, ưu tiên, trạng thái bật/lưu trữ, phiên bản, snapshot và thời gian gốc |
| supply_allocations | Phân bổ theo đơn/lô, số lượng, giá vốn và trạng thái đã trả |
| supply_purchases | Các lần mua theo đơn nguyên bản, kể cả lịch sử của đơn đã hủy |
| supply_actions | Mã thao tác và request_hash nguyên bản để gửi lại không phân bổ thêm |
| supplier_payments | Khoản đã trả BANK/CASH, mã giao dịch, ghi chú, request_hash và thời gian gốc |

Không gọi lại nhập lô, tự phân bổ, tạo lần mua hoặc trả tiền. Không thay đổi đơn, khóa tra cứu, tồn bán, mã vé hoặc thư chờ. PDF tiếp tục dùng file gốc; không phân tích hoặc tạo lại.

## Điều kiện

File phải đúng full-v2/manifest/quan hệ và khai báo EXPORTED cho năm bảng này cùng products, suppliers, orders. Các sản phẩm, nhà cung cấp và đơn liên quan phải có trong bảng vận hành PHP, khớp dữ liệu nguồn theo nội dung JSON, phiên bản và thời gian. Đơn chỉ nằm trong kho chờ chưa đáp ứng điều kiện. Bộ nhập không tự xuất bản đơn hoặc tạo nhà cung cấp/sản phẩm.

Giữ nguyên giá vốn và snapshot lịch sử, không tính lại bằng giá hiện tại. Kiểm số lượng phân bổ đang giữ không vượt lô hữu hạn hoặc nhóm khách của đơn; nguồn mua theo đơn phải có lần mua gốc tương ứng. Phân bổ đã trả phải khớp đơn CANCELLED. Tổng mua gồm toàn bộ giá trị lô hữu hạn và lịch sử mua theo đơn, kể cả lô lưu trữ/đơn hủy; khoản đã trả không được vượt tổng mua và các tổng tiền phải nằm trong phạm vi xử lý chính xác của PHP.

Allocation legacy thiếu variant, giá vốn khác lô gốc, dữ liệu ngoài giới hạn runtime hoặc thiếu lịch sử mua cần đối soát trước. Không tạo lần mua bù để vượt lỗi. Không áp lại khoảng ngày hiện tại của lô lên lịch sử phân bổ cũ vì người quản trị có thể đã chỉnh khoảng ngày sau khi bán.

Mã giao dịch được kiểm theo collation của cột reference ở MySQL đích; hai mã chỉ khác hoa/thường hoặc dấu có thể đụng nhau trên hosting. Trường hợp đụng bị chặn, không tự đổi mã giao dịch. Schema phải đủ dung lượng, đúng InnoDB, khóa chính và khóa duy nhất reference.

## Chạy trên bản thử nghiệm

```bash
php bin/import-supply.php --file=/home/USER/private/export.sql
php bin/import-supply.php --file=/home/USER/private/export.sql --apply --fingerprint=FINGERPRINT_DRY_RUN
```

Mặc định chỉ kiểm tra và cấp fingerprint khi `ready=true`. Fingerprint ràng buộc file nguồn, các cha native và toàn bộ trạng thái năm bảng đích. Khác hàng đang có, thiếu cha hoặc thừa hàng runtime đều chặn. Không có force/overwrite; lỗi apply rollback toàn bộ. Chạy lại dữ liệu khớp không thêm phân bổ, lần mua, khoản trả hoặc audit.

Giới hạn 50.000 dòng/32 MiB tổng cho tám bảng nguồn đang xét và 50.000 dòng/64 MiB cho năm bảng đích. Báo cáo chỉ in số lượng/mã lỗi, không xuất hash yêu cầu, snapshot hoặc dữ liệu khách.

Khi tới cutover đã đối soát, dừng các hệ thống đang ghi đơn/nguồn/thu trả tiền trước khi nhập. Sau khi PHP xử lý đơn hoặc trả thêm tiền, không nhập đè export cũ. Bộ nhập chưa gồm kho mã vé, file vé, đầy đủ đơn vé–thanh toán hoặc công nợ CTV; `operationalReady=false` và trạng thái chuyển toàn hệ thống chưa hoàn tất vẫn giữ. Chưa nhập dữ liệu thật trên hosting.

## Đã kiểm

Đợt 156: 34 bài trong bộ kiểm chuyển dữ liệu đều qua; 7 bài nghiệp vụ lô/phân bổ/kho mã/công nợ/sao kê/lợi nhuận/lịch sử nguồn cũng qua. Fixture được tạo lại và đối chiếu `--check` với Node đã ghim. Kết quả local chưa thay cho nghiệm thu dữ liệu thật trên hosting.

Fixture `supply-v2-node.sql` được tạo bằng helper export và schema yêu cầu của Node đã ghim. Kiểm nhập lô hữu hạn/mua theo đơn, phân bổ giữ/trả, BANK/CASH và công nợ; gửi lại chính yêu cầu Node không tạo thêm dữ liệu. Có kiểm cha/schema/fingerprint/mã giao dịch, rollback, hai tiến trình nhập, CLI, hủy đơn và trả tiền tiếp bằng PHP. Hủy đơn sau nhập trả phân bổ nhưng giữ lần mua và công nợ lịch sử.
