<?php

namespace Drupal\e_invoice;

/**
 * Interface for Invoice providers plugins.
 *
 * Mọi phương thức đều nhận mảng $config đã chuẩn hoá bởi
 * \Drupal\e_invoice\Service\GetConfigInvoice và ném \DomainException kèm thông
 * điệp hiển thị được cho người dùng khi nhà cung cấp trả lỗi nghiệp vụ.
 *
 * Riêng lỗi token hết hạn phải ném
 * \Drupal\e_invoice\Exception\InvoiceTokenException để tầng gọi biết là lấy
 * token mới rồi thử lại được.
 */
interface InvoiceProvidersInterface {

  /**
   * Đăng nhập và lấy bộ token của nhà cung cấp.
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Mảng phẳng gồm các khoá "token", "jwt_token", "subscribers",
   *   "organization". Khoá không áp dụng với nhà cung cấp thì trả chuỗi rỗng.
   *
   * @throws \DomainException
   *   Khi không lấy được token.
   */
  public function authenticate(array $config): array;

  /**
   * Xem trước PDF hóa đơn chưa phát hành.
   *
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Dữ liệu hóa đơn, đánh khoá theo uuid hóa đơn. Chỉ hóa đơn đầu tiên được
   *   xem trước.
   *
   * @return array
   *   Phản hồi của nhà cung cấp, khoá "data" chứa đường dẫn xem trước.
   */
  public function preview(array $config, array $data): array;

  /**
   * Phát hành hóa đơn.
   *
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Danh sách hóa đơn cần phát hành, đánh khoá theo uuid hóa đơn.
   * @param bool $get_file
   *   TRUE để tải kèm file PDF ngay sau khi phát hành.
   *
   * @return array
   *   Kết quả phát hành đánh khoá theo RefID (chính là uuid hóa đơn). Mỗi phần
   *   tử là phản hồi thô của nhà cung cấp, kèm khoá "base64" khi $get_file.
   */
  public function issue(array $config, array $data, bool $get_file = FALSE): array;

  /**
   * Thay thế hóa đơn đã phát hành.
   *
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Dữ liệu hóa đơn mới, đánh khoá theo uuid; mỗi phần tử kèm khoá "replace"
   *   mô tả hóa đơn bị thay thế.
   * @param bool $get_file
   *   TRUE để tải kèm file PDF ngay sau khi phát hành.
   *
   * @return array
   *   Kết quả thay thế, đánh khoá theo RefID.
   */
  public function replace(array $config, array $data, bool $get_file = TRUE): array;

  /**
   * Lấy trạng thái các hóa đơn đã phát hành.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $codes
   *   Danh sách mã giao dịch (TransactionID).
   * @param array $params
   *   Tham số bổ sung, ví dụ "calcu", "withCode".
   *
   * @return array
   *   Trạng thái đánh khoá theo TransactionID.
   */
  public function status(array $config, array $codes, array $params = []): array;

  /**
   * Kéo danh sách hóa đơn đầu vào.
   *
   * Nhà cung cấp tự phân trang cho tới hết, tầng gọi không phải lặp.
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
   * Hạch toán hóa đơn đầu vào.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $data
   *   Gồm "invoice_id" (mảng id hóa đơn), "accountant", "accountant_date",
   *   "ref_no". Bỏ trống "accountant" để huỷ đánh dấu hạch toán.
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
   * @param array $codes
   *   Danh sách mã giao dịch (TransactionID).
   *
   * @return array
   *   File đánh khoá theo TransactionID, khoá "Data" chứa nội dung base64.
   *   Hóa đơn nhà cung cấp chưa sinh xong file thì không có trong kết quả.
   */
  public function pdf(array $config, array $codes): array;

  /**
   * Tải gói ZIP (PDF + XML) của các hóa đơn đầu vào.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $ids
   *   Danh sách id hóa đơn cần tải.
   *
   * @return string
   *   Nội dung nhị phân của file ZIP, chuỗi rỗng khi không tải được.
   */
  public function download(array $config, array $ids): string;

}
