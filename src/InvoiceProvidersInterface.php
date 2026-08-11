<?php

namespace Drupal\e_invoice;

/**
 * Interface for Invoice providers plugins.
 *
 * Mọi phương thức đều nhận mảng $config đã chuẩn hoá bởi
 * \Drupal\e_invoice\Service\GetConfigInvoice và ném \DomainException kèm thông
 * điệp hiển thị được cho người dùng khi nhà cung cấp trả lỗi nghiệp vụ.
 */
interface InvoiceProvidersInterface {

  /**
   * Đăng nhập và lấy bộ token của nhà cung cấp.
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Mảng gồm token, jwt_token và (tuỳ nhà cung cấp) subscribers,
   *   organization.
   *
   * @throws \DomainException
   *   Khi không lấy được token.
   */
  public function authenticate(array $config): array;

  /**
   * Xem trước PDF hóa đơn chưa phát hành.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $data
   *   Dữ liệu hóa đơn.
   *
   * @return array
   *   Phản hồi của nhà cung cấp, thường chứa đường dẫn xem trước.
   */
  public function preview(array $config, array $data);

  /**
   * Phát hành hóa đơn.
   *
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Danh sách hóa đơn cần phát hành.
   * @param bool $get_file
   *   TRUE khi phát hành một hóa đơn và cần tải kèm file PDF.
   *
   * @return array
   *   Kết quả phát hành: một hóa đơn khi $get_file, ngược lại là danh sách.
   */
  public function issue(array $config, array $data, bool $get_file): array;

  /**
   * Thay thế hóa đơn đã phát hành.
   *
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Dữ liệu hóa đơn mới, kèm khoá "replace" mô tả hóa đơn bị thay thế.
   *
   * @return array
   *   Thông tin hóa đơn thay thế.
   */
  public function replace(array $config, array $data): array;

  /**
   * Lấy trạng thái hóa đơn đã phát hành theo mã giao dịch.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $code
   *   Mã giao dịch (TransactionID).
   * @param array $params
   *   Tham số bổ sung, ví dụ "calcu", "withCode".
   *
   * @return array
   *   Phản hồi trạng thái của nhà cung cấp.
   */
  public function status(array $config, string $code, array $params);

  /**
   * Kéo danh sách hóa đơn đầu vào.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $params
   *   Bộ lọc "from", "to", "skip".
   *
   * @return array
   *   Danh sách hóa đơn đã ánh xạ sang tên field của entity invoice.
   */
  public function modified(array $config, array $params): array;

  /**
   * Hạch toán một hóa đơn đầu vào.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $data
   *   Gồm "accountant", "accountant_date", "invoice_id", "ref_no".
   *
   * @return array
   *   Phản hồi của nhà cung cấp.
   */
  public function accounting(array $config, array $data): array;

  /**
   * Tải PDF hóa đơn đầu ra theo mã giao dịch.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $code
   *   Mã giao dịch (TransactionID).
   * @param string $type
   *   "download" để ném lỗi khi thất bại, "save" để trả mảng rỗng.
   *
   * @return array
   *   Dữ liệu file, khoá "Data" chứa nội dung base64.
   */
  public function pdf(array $config, string $code, string $type = "download"): array;

  /**
   * Tải gói ZIP (PDF + XML) của các hóa đơn đầu vào.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $data
   *   Danh sách id hóa đơn cần tải.
   *
   * @return mixed
   *   Nội dung nhị phân của file ZIP.
   */
  public function download(array $config, array $data): mixed;

}
