<?php

namespace Drupal\e_invoice\Exception;

/**
 * Nhà cung cấp từ chối vì token hết hạn hoặc không hợp lệ.
 *
 * Tách riêng khỏi \DomainException để tầng nghiệp vụ biết đây là lỗi có thể
 * khắc phục: lấy token mới rồi gọi lại đúng một lần.
 *
 * @see \Drupal\e_invoice\Service\HandleInvoice::retryOnExpiredToken()
 */
class InvoiceTokenException extends \DomainException {}
