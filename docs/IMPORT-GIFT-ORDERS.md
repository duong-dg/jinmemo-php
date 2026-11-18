# Import đơn quà lịch sử cùng tồn hiện tại

Đợt 142 mở rộng bộ nhập cấu hình bằng `--with-gift-orders`: ghi danh mục/tồn vào `jingo_settings` và đơn độc lập vào `jingo_gift_orders` trong cùng giao dịch. Không gọi lại create/update, không tính giá theo danh mục mới, không trừ hay hoàn tồn khi import.

Đọc trực tiếp export full-v2; không cần nạp SQL nguồn vào DB staging hoặc thêm migration mới. Mặc định chưa có cờ này thì hành vi cũ vẫn giữ: có đơn quà nguồn sẽ chặn nhập tồn.

## Cách dùng khi chuyển dữ liệu thật

Chốt dừng ghi Node, backup và copy ảnh theo quy trình chung. Chạy thử:

```bash
php bin/import-business-settings.php --file=/home/USER/private/export.sql --scope=gifts --with-gift-orders
```

Sau khi duyệt báo cáo, dùng lại file/scope/cờ với `--apply --fingerprint=GIÁ_TRỊ_TỪ_DRY_RUN`. Scope `all` có thể nhập thêm cấu hình thanh toán thủ công cùng giao dịch. Các lệnh này ghi vào bảng vận hành khi apply; không tự chạy trên hosting trong giai đoạn phát triển.

## Dữ liệu được giữ và kiểm

- Giữ ID, mã JM-G, PENDING/SHIPPING/COMPLETED/CANCELLED, thời gian sáu chữ số phần giây, JSON snapshot, fingerprint nguyên bản và version/tồn của danh mục.
- Kiểm requestId trùng ID, mã/trạng thái, liên hệ, nhận tại quầy/giao hàng, dòng không trùng, số lượng/giá lịch sử, phí giao và tổng tiền an toàn. Không so giá/phí lịch sử với giá/phí hiện tại. Fingerprint nguồn được giữ dưới dạng giá trị gốc, kiểm hình thức; không tạo hoặc suy đoán fingerprint mới từ snapshot đã thay địa chỉ quầy.
- Đơn PENDING/SHIPPING phải còn sản phẩm trong danh mục để có thể hủy đúng. Kiểm tổng lượng có thể hoàn từ tất cả đơn mở không làm tràn tồn. Đơn kết thúc được phép tham chiếu sản phẩm cũ đã bỏ.
- Sau import, replay yêu cầu tạo cũ trả lại đơn có sẵn; hủy một đơn mở hoàn tồn một lần theo luồng PHP hiện tại. Đơn đã hủy không được hoàn tồn lại trong lúc import hoặc replay.
- Dùng chung khóa catalog, InnoDB và fingerprint gắn trạng thái đích; lỗi ghi đơn/cấu hình/audit rollback toàn bộ. Hai tiến trình apply cùng fingerprint chỉ một tiến trình được nhập. Cần dry-run mới sau lần apply; chạy lại không tạo thêm đơn/audit.
- Cấu hình, đơn, mã hoặc thời gian đích khác nguồn đều chặn. Có đơn runtime ngoài export cũng chặn. Nếu đã bán/hủy/chỉnh tồn sau chuyển thì không dùng import để khôi phục snapshot cũ.

## Phần vẫn chặn

**Chỉ nhập đơn quà độc lập, chưa có dấu vết thanh toán/hoàn tiền.** Đơn thuộc checkout_members, được trỏ từ JSON refs của mã tổng, có paymentStatus/paidAmount/paymentReference/bundleCode hoặc có tham chiếu sổ thu/hoàn sẽ báo cần adapter thanh toán chung/tài chính. Không tách những đơn đó khỏi mã tổng và không tự giả định chưa thu tiền.

Manifest phải xác nhận đã xuất bảng đơn, settings, checkout_members, checkout_bundles, refunds và payment_references. Thiếu các bảng liên quan, JSON refs không đủ kiểm chứng, hoặc runtime đã có liên kết thanh toán/tài chính đến các đơn này thì dừng. Nếu chỉ một đơn bị chặn, cả scope quà không được apply; không nhập một phần tồn và đơn.

Ảnh và cờ phân hệ vẫn phải đáp ứng điều kiện của [IMPORT-BUSINESS-SETTINGS.md](IMPORT-BUSINESS-SETTINGS.md). Không nhập/phát lại mail, thông báo, provider, hoàn tiền hoặc thao tác ảnh/PDF. Audit lịch sử toàn hệ thống vẫn thuộc kho chờ riêng, không được phát lại thành hành động.

Kiểm local dùng fixture tổng hợp qua helper export Node đã ghim, không chứng nhận dữ liệu production hoặc hoàn tất toàn bộ chuyển dữ liệu; `operationalReady=false` vẫn giữ.

Đợt 144 thêm chế độ riêng `--with-gift-bundles` cho mã tổng chỉ gồm quà, có đối soát hoàn và cấp liên kết thay thế: [IMPORT-GIFT-BUNDLES.md](IMPORT-GIFT-BUNDLES.md). Chế độ --with-gift-orders trong tài liệu này vẫn chỉ nhận đơn độc lập.
