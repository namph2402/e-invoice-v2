<?php

namespace Drupal\e_invoice\Exception;

/**
 * Nhà cung cấp từ chối vì lỗi tạm thời, gửi lại nguyên yêu cầu là được.
 *
 * Tài liệu MISA liệt kê "InvoiceNumberNotCotinuous" và "InvoiceDuplicated" vào
 * nhóm này: số hóa đơn bị tranh chấp giữa các phiên phát hành song song, dữ
 * liệu gửi lên không có gì sai.
 */
class InvoiceRetryException extends \DomainException {}
