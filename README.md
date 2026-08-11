# ERPCons E-Invoice

Tích hợp hóa đơn điện tử: định nghĩa entity `invoice`, lưu cấu hình kết nối và
đóng gói mọi lời gọi tới nhà cung cấp dịch vụ hóa đơn sau một plugin interface.

Module này chỉ lo phần *hạ tầng*. Giao diện danh sách, phát hành, hạch toán và
liên kết kho nằm ở `erp_accountant_invoice`.

## Thành phần chính

| Thành phần | Vai trò |
|---|---|
| `Drupal\e_invoice\Entity\Invoice` | Entity `invoice`, 2 bundle: `input_invoices` (đầu vào), `output_invoices` (đầu ra) |
| `e_invoice.handle_invoice` | Điều phối nghiệp vụ: phát hành, thay thế, tra trạng thái, kéo hóa đơn đầu vào, lưu file PDF/XML |
| `e_invoice.get_config` | Chuẩn hoá cấu hình kết nối và tự lấy lại token khi hết hạn |
| `plugin.manager.invoice_providers` | Plugin manager cho các nhà cung cấp |

## Cấu hình

Có hai nguồn cấu hình, chọn bằng trường `field_use_config_settings`:

1. **Cấu hình chung** — form `/admin/config/invoice`, lưu vào `e_invoice.settings`.
2. **Cấu hình theo công ty** — term thuộc vocabulary `config_e_invoice`, gắn vào
   công ty qua `field_config_invoice`. Đây là luồng đang dùng thực tế.

Token được cấp lại tự động khi `invoice_expiration` đã qua hoặc chưa từng được
lấy; hạn mặc định là 10 ngày.

## Thêm nhà cung cấp mới

Tạo plugin trong `src/Plugin/Providers/`, gắn attribute và hiện thực đầy đủ
`InvoiceProvidersInterface`:

```php
#[InvoiceProvidersAttribute(id: "vnpt", label: new TranslatableMarkup("VNPT"))]
class VnptProvider extends PluginBase implements InvoiceProvidersInterface {
  // authenticate, preview, issue, replace, status,
  // modified, accounting, pdf, download
}
```

Quy ước lỗi: ném `\DomainException` kèm thông điệp hiển thị được cho người dùng
khi nhà cung cấp trả lỗi nghiệp vụ. `HandleInvoice` bắt exception này và trả về
`["success" => FALSE, "message" => ...]`; mọi `\Throwable` khác được ghi log và
quy về thông báo lỗi hệ thống chung.

`MisaProvider` là bản tham chiếu đầy đủ.

## Yêu cầu hệ thống

- `ext-intl` — đọc số thành chữ (`NumberFormatter`).
- `ext-zip` — giải nén gói PDF/XML hóa đơn đầu vào.

## Cập nhật

Sau khi kéo code mới, chạy `drush updatedb` để bổ sung các field còn thiếu
(`field_invoice_company`, `field_invoice_origin`, `field_invoice_export`,
`field_invoice_is_xml`) — xem `e_invoice.install`.

Các field instance phụ thuộc vocabulary `company` và node type `preorder` nằm
trong `config/optional`, chỉ được cài khi hai thứ đó tồn tại.
