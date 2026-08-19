<?php

namespace Drupal\e_invoice\Plugin\Providers;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\e_invoice\Exception\InvoiceRetryException;
use Drupal\e_invoice\Exception\InvoiceTokenException;
use Drupal\e_invoice\InvoiceProvidersAttribute;
use Drupal\e_invoice\InvoiceProvidersInterface;
use Drupal\e_invoice\Service\GetNumberToWords;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tích hợp hóa đơn điện tử MISA meInvoice.
 *
 * Provider nói chuyện với ba nhóm API khác nhau của MISA, mỗi nhóm một quy ước
 * riêng nên không gộp chung được:
 * - "/api/integration/*" trên invoice_host: phát hành, thay thế, xem trước,
 *   tra trạng thái, tải PDF hóa đơn đầu ra. Bọc phản hồi bằng khoá viết thường
 *   ("success", "errorCode", "data") và nhét JSON lồng trong chuỗi.
 * - "/api2/*" trên invoice_host: validateUser và jwttoken, dùng khoá viết hoa
 *   ("Success", "Message", "Data").
 * - "/inbot/api/*" trên invoice_appurl: hóa đơn đầu vào, cũng dùng khoá viết
 *   hoa và yêu cầu thêm header ClientId.
 *
 * @see https://www.misa.vn/154997/tai-lieu-open-api-tich-hop-hoa-don-dien-tu-misa-meinvoice-dau-vao
 */
#[InvoiceProvidersAttribute(
  id: "misa",
  label: new TranslatableMarkup("Misa"),
)]
class MisaProvider extends PluginBase implements InvoiceProvidersInterface, ContainerFactoryPluginInterface {

  /**
   * Số bản ghi tối đa MISA trả về mỗi lần gọi API danh sách.
   */
  private const PAGE_SIZE = 100;

  /**
   * Chặn trên số trang khi kéo hóa đơn đầu vào, phòng khi MISA trả sai Total.
   */
  private const MAX_PAGES = 200;

  /**
   * Số mã tối đa gửi kèm một lần gọi tra trạng thái hoặc tải file.
   */
  private const BATCH_SIZE = 50;

  /**
   * Số lần hỏi lại khi MISA chưa sinh xong file.
   */
  private const FILE_ATTEMPTS = 6;

  /**
   * Số lần phát hành lại khi MISA báo lỗi có thể thử lại được.
   */
  private const PUBLISH_ATTEMPTS = 3;

  /**
   * Khoảng nghỉ (giây).
   */
  private const TIME_DELAY = 2;

  /**
   * Timeout (giây) cho các lệnh nặng: phát hành, kéo danh sách, tải file.
   */
  private const LONG_TIMEOUT = 120;

  /**
   * Mã lỗi MISA trả về khi token hết hạn hoặc không hợp lệ.
   */
  private const TOKEN_ERRORS = [
    "tokenexpiredcode",
    "invalidtokencode",
    "tokenexpired",
    "invalidtoken",
    "unauthorized",
  ];

  /**
   * Mã lỗi tài liệu nói rõ là cứ phát hành lại, không phải lỗi dữ liệu.
   */
  private const RETRY_ERRORS = [
    "invoicenumbernotcotinuous",
    "invoiceduplicated",
  ];

  /**
   * Các mức thuế suất MISA nhận thẳng, còn lại phải khai dưới dạng "KHAC:x%".
   */
  private const STANDARD_VAT_RATES = [0.0, 5.0, 8.0, 10.0];

  /**
   * Tên thuế suất đặc biệt: không chịu thuế và không kê khai nộp thuế.
   */
  private const SPECIAL_VAT_RATES = ["KCT", "KKKNT"];

  /**
   * Kiểu ký mặc định: 2 = ký HSM, hiển thị chữ ký trên bản thể hiện.
   *
   * Tài liệu quy định kiểu ký phải khớp loại hóa đơn: hóa đơn từ máy tính tiền
   * dùng 5 (hoặc 6 khi ký bất đồng bộ), không dùng 2.
   *
   * @see self::SIGN_TYPE_MACHINE
   */
  private const SIGN_TYPE_DEFAULT = 2;

  /**
   * Kiểu ký cho hóa đơn từ máy tính tiền: 5 = MTT, không hiển thị chữ ký.
   */
  private const SIGN_TYPE_MACHINE = 5;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected UuidInterface $uuid,
    protected ClientInterface $client,
    protected LoggerInterface $logger,
    protected GetNumberToWords $getNumberToWords,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get("uuid"),
      $container->get("http_client"),
      $container->get("logger.channel.e_invoice"),
      $container->get("e_invoice.get_number_to_words"),
    );
  }

  /**
   * Xác nhận tài khoản kết nối.
   */
  public function authenticate(array $config): array {
    $result = [
      "token" => "",
      "jwt_token" => "",
      "subscribers" => "",
      "organization" => "",
    ];

    // Token của nhóm API phát hành (hóa đơn đầu ra).
    if (!empty($config["invoice_host"])) {
      $token = $this->integration(
        $this->request($config["invoice_host"], "/api/integration/auth/token", [], [
          "appid" => $config["invoice_appid"],
          "taxcode" => $config["invoice_taxcode"],
          "username" => $config["invoice_username"],
          "password" => $config["invoice_password"],
        ]),
        "Không lấy được token phát hành hóa đơn"
      );

      $result["token"] = (string) ($this->field($token, "data") ?? "");

      if ($result["token"] === "") {
        throw new \DomainException("MISA không trả về token phát hành hóa đơn");
      }
    }

    // Chuỗi 4 bước của nhóm API hóa đơn đầu vào. Không khai báo appurl nghĩa là
    // công ty này chỉ phát hành, bỏ qua để khỏi gọi thừa.
    if (empty($config["invoice_appurl"])) {
      return $result;
    }

    // Bước 1: validateUser để lấy secure token.
    $secure = $this->api2(
      $this->request($config["invoice_host"], "/api2/validateUser", $this->authHeaders($config), [
        "PassWord" => $config["invoice_password"],
      ]),
      "Không lấy được secure token"
    );

    // Bước 2: đổi secure token lấy JWT.
    $jwt = $this->api2(
      $this->request(
        $config["invoice_host"],
        "/api2/auth/jwttoken",
        $this->authHeaders($config) + [
          "securetoken" => $this->extractSecureToken((string) ($secure["Data"] ?? "")),
        ],
        []
      ),
      "Không lấy được JWT token"
    );

    $result["jwt_token"] = (string) ($jwt["Data"]["AccessToken"] ?? "");

    if ($result["jwt_token"] === "") {
      throw new \DomainException("MISA không trả về AccessToken");
    }

    // Bước 3: tra subscriber theo mã số thuế. Tài liệu yêu cầu cả ClientId lẫn
    // Bearer token ở bước này.
    $headers = [
      "ClientId" => (string) ($config["invoice_client"] ?? ""),
      "Authorization" => "Bearer " . $result["jwt_token"],
    ];

    $subscribers = $this->api2(
      $this->request(
        $config["invoice_appurl"],
        "/inbot/api/subscribers/code/" . rawurlencode((string) $config["invoice_taxcode"]),
        $headers,
        NULL,
        "GET"
      ),
      "Không lấy được subscriber"
    );

    $result["subscribers"] = (string) ($subscribers["Data"]["Id"] ?? "");

    if ($result["subscribers"] === "") {
      throw new \DomainException("MISA không trả về SubscriberId");
    }

    // Bước 4: lấy đơn vị đầu tiên, dùng để lọc hóa đơn theo chi nhánh.
    $organizations = $this->api2(
      $this->request(
        $config["invoice_appurl"],
        "/inbot/api/{$result["subscribers"]}/organizations",
        $headers,
        NULL,
        "GET"
      ),
      "Không lấy được organization"
    );

    $organization = reset($organizations["Data"]) ?: [];
    $result["organization"] = (string) ($organization["Id"] ?? "");

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function preview(array $config, array $data): array {
    $invoices = $this->buildInvoices($config["invoice_template"] ?? [], $data);

    if (empty($invoices)) {
      throw new \DomainException("Không có dữ liệu hóa đơn để xem trước");
    }

    return $this->integration(
      $this->request(
        $config["invoice_host"],
        "/api/integration/invoice/unpublishview",
        $this->bearer($config),
        reset($invoices),
        "POST",
        self::LONG_TIMEOUT
      ),
      "Không xem trước được hóa đơn"
    );
  }

  /**
   * {@inheritdoc}
   */
  public function issue(array $config, array $data, bool $get_file = FALSE): array {
    return $this->publish($config, $data, "create", $get_file);
  }

  /**
   * {@inheritdoc}
   */
  public function replace(array $config, array $data, bool $get_file = TRUE): array {
    return $this->publish($config, $data, "replace", $get_file);
  }

  /**
   * {@inheritdoc}
   */
  public function status(array $config, array $codes, array $params = []): array {
    $codes = array_values(array_unique(array_filter($codes)));

    if (empty($codes)) {
      throw new \DomainException("Không tìm thấy mã giao dịch");
    }

    $query = http_build_query([
      // 1 = tra theo TransactionID, 2 = tra theo RefID.
      "inputType" => "1",
      "invoiceCalcu" => $params["calcu"] ?? "false",
      "invoiceWithCode" => $params["withCode"] ?? "true",
    ]);

    $result = [];

    // Tài liệu giới hạn 50 mã mỗi lần gọi.
    foreach (array_chunk($codes, self::BATCH_SIZE) as $chunk) {
      $response = $this->request(
        $config["invoice_host"],
        "/api/integration/invoice/status?" . $query,
        $this->bearer($config),
        $chunk
      );

      // Endpoint này khi thì trả thẳng danh sách, khi thì bọc trong phong bì
      // "success"/"data" như các endpoint integration khác.
      if (is_array($response) && (isset($response["success"]) || isset($response["Success"]))) {
        $response = $this->decodeNested(
          $this->field(
            $this->integration($response, "Không tra được trạng thái hóa đơn"),
            "data"
          )
        );
      }

      $result += $this->keyBy(is_array($response) ? $response : [], "TransactionID");
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function pdf(array $config, array $codes): array {
    $codes = array_values(array_unique(array_filter($codes)));

    if (empty($codes)) {
      throw new \DomainException("Không tìm thấy mã giao dịch");
    }

    $query = http_build_query([
      "invoiceWithCode" => "true",
      "invoiceCalcu" => "true",
      "downloadDataType" => "pdf",
    ]);

    // MISA sinh PDF không đồng bộ: ngay sau khi phát hành, lần gọi đầu có thể
    // chưa có file. Hỏi lại vài lần thay vì nghỉ cứng một khoảng đoán chừng.
    $files = [];

    $pending = $codes;

    for ($attempt = 1; $attempt <= self::FILE_ATTEMPTS; $attempt++) {
      // Tài liệu giới hạn 50 mã giao dịch mỗi lần gọi.
      foreach (array_chunk($pending, self::BATCH_SIZE) as $chunk) {
        $response = $this->integration(
          $this->request(
            $config["invoice_host"],
            "/api/integration/invoice/download?" . $query,
            $this->bearer($config),
            $chunk,
            "POST",
            self::LONG_TIMEOUT
          ),
          "Không tải được PDF hóa đơn"
        );

        foreach ($this->decodeNested($this->field($response, "data")) as $file) {
          if (!empty($file["TransactionID"]) && !empty($file["Data"])) {
            $files[$file["TransactionID"]] = $file;
          }
        }
      }

      // Lần thử sau chỉ hỏi những hóa đơn còn thiếu file.
      $pending = array_values(array_diff($codes, array_keys($files)));

      if (empty($pending)) {
        break;
      }

      if ($attempt < self::FILE_ATTEMPTS) {
        sleep(self::TIME_DELAY);
      }
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function modified(array $config, array $params): array {
    $data = [];
    $skip = (int) ($params["skip"] ?? 0);

    for ($page = 0; $page < self::MAX_PAGES; $page++) {
      $query = http_build_query([
        "from" => $params["from"] ?? "",
        "to" => $params["to"] ?? "",
        "take" => self::PAGE_SIZE,
        "skip" => $skip,
        "IsFilterInvDate" => "true",
      ]);

      $response = $this->api2(
        $this->request(
          $config["invoice_appurl"],
          $this->inbotPath($config, "invoices/v2/modified") . "?" . $query,
          $this->inbotHeaders($config),
          NULL,
          "GET",
          self::LONG_TIMEOUT
        ),
        "Không lấy được danh sách hóa đơn đầu vào"
      );

      $items = $response["Data"]["Data"] ?? [];

      foreach ($items as $item) {
        $data[] = $this->mapInputInvoice($item);
      }

      $count = count($items);
      $skip += $count;
      $total = (int) ($response["Data"]["Total"] ?? 0);

      if ($count < self::PAGE_SIZE || ($total > 0 && $skip >= $total)) {
        break;
      }

      sleep(self::TIME_DELAY);
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function accounting(array $config, array $data): array {
    $invoice_id = $data["invoice_id"] ?? [];

    if (empty($invoice_id)) {
      throw new \DomainException("Không tìm thấy hóa đơn cần hạch toán");
    }

    $list_invoice_id = array_values($invoice_id);

    $payload = [
      "InvoiceId" => count($list_invoice_id) > 1 ? $list_invoice_id : reset($list_invoice_id)
    ];

    $payload += [
      "Accountant" => $data["accountant"] ?? NULL,
      "AccountingDate" => $data["accountant_date"] ?? NULL,
      "RefNo" => $data["ref_no"] ?? NULL,
    ];

    return $this->api2(
      $this->request(
        $config["invoice_appurl"],
        $this->inbotPath($config, "invoices/invoiceaccountingdateV2"),
        $this->inbotHeaders($config),
        $payload
      ),
      "Hạch toán hóa đơn thất bại"
    );
  }

  /**
   * {@inheritdoc}
   */
  public function payment(array $config, array $data): array {
    $invoice_id = (string) ($data["invoice_id"] ?? "");

    if ($invoice_id === "") {
      throw new \DomainException("Không tìm thấy hóa đơn cần cập nhật thanh toán");
    }

    // MISA chỉ nhận một hóa đơn mỗi lần, tầng nghiệp vụ tự lặp danh sách.
    $payload = [
      "InvoiceId" => $invoice_id,
      "PaymentDate" => $data["payment_date"] ?? NULL,
      "PaymentPair" => $data["payment_pair"] ?? NULL,
      "TotalAmountPayment" => $data["total_amount_payment"] ?? NULL,
      "TotalAmountNotPayment" => $data["total_amount_not_payment"] ?? NULL,
      "NumberPaymentNext" => $data["number_payment_next"] ?? NULL,
      "AmountPayment" => $data["amount_payment"] ?? NULL,
    ];

    return $this->api2(
      $this->request(
        $config["invoice_appurl"],
        $this->inbotPath($config, "invoices/invoicepayment"),
        $this->inbotHeaders($config),
        $payload
      ),
      "Cập nhật thông tin thanh toán thất bại"
    );
  }

  /**
   * {@inheritdoc}
   */
  public function download(array $config, array $ids, string $type): string {
    $ids = array_values(array_unique(array_filter($ids)));

    if (empty($ids)) {
      throw new \DomainException("Không tìm thấy hóa đơn cần tải");
    }

    $key = $this->api2(
      $this->request(
        $config["invoice_appurl"],
        $this->inbotPath($config, "download/zip"),
        $this->inbotHeaders($config),
        [
          "FileType" => $type,
          "LstInvID" => $ids,
        ]
      ),
      "Không tạo được khoá tải file"
    );

    $download_key = (string) ($key["Data"]["Key"] ?? "");

    if ($download_key === "") {
      throw new \DomainException("MISA không trả về khoá tải file");
    }

    sleep(10);

    $path = $this->inbotPath($config, "download/{$download_key}/download");

    // Gói ZIP được nén bất đồng bộ, hỏi lại tới khi có file thay vì nghỉ cứng.
    for ($attempt = 1; $attempt <= self::FILE_ATTEMPTS; $attempt++) {
      $response = $this->request(
        $config["invoice_appurl"],
        $path,
        $this->inbotHeaders($config),
        NULL,
        "GET",
        self::LONG_TIMEOUT
      );

      $binary = $this->toBinary($response);

      if ($binary !== "") {
        return $binary;
      }

      if ($attempt < self::FILE_ATTEMPTS) {
        sleep(self::TIME_DELAY);
      }
    }

    return "";
  }

  /**
   * Phát hành hoặc thay thế hóa đơn, hai việc dùng chung một endpoint.
   *
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Dữ liệu hóa đơn, đánh khoá theo uuid.
   * @param string $type
   *   "create" hoặc "replace".
   * @param bool $get_file
   *   TRUE để tải kèm PDF sau khi phát hành.
   *
   * @return array
   *   Kết quả đánh khoá theo RefID.
   */
  private function publish(array $config, array $data, string $type, bool $get_file): array {
    $invoices = $this->buildInvoices($config["invoice_template"] ?? [], $data, $type);

    if (empty($invoices)) {
      throw new \DomainException("Không có dữ liệu hóa đơn để phát hành");
    }

    $payload = [
      "SignType" => $this->signType($config, $invoices),
      // array_values vì $invoices đánh khoá theo uuid, để nguyên thì
      // json_encode sinh ra object chứ không phải mảng.
      "InvoiceData" => array_values($invoices),
      "PublishInvoiceData" => NULL,
    ];

    $response = NULL;

    // "InvoiceNumberNotCotinuous" và "InvoiceDuplicated" là lỗi tranh chấp số
    // hóa đơn, tài liệu nói rõ cứ gửi lại chứ không phải sửa dữ liệu.
    for ($attempt = 1; $attempt <= self::PUBLISH_ATTEMPTS; $attempt++) {
      try {
        $response = $this->integration(
          $this->request(
            $config["invoice_host"],
            "/api/integration/invoice",
            $this->bearer($config),
            $payload,
            "POST",
            self::LONG_TIMEOUT
          ),
          "Phát hành hóa đơn thất bại"
        );

        break;
      }
      catch (InvoiceRetryException $e) {
        if ($attempt >= self::PUBLISH_ATTEMPTS) {
          throw new \DomainException($e->getMessage(), 0, $e);
        }

        $this->logger->warning("Phát hành lại hóa đơn (lần @attempt): @message", [
          "@attempt" => $attempt + 1,
          "@message" => $e->getMessage(),
        ]);

        sleep(self::TIME_DELAY);
      }
    }

    // Ký đồng bộ thì kết quả nằm ở publishInvoiceResult; ký bất đồng bộ
    // (SignType 3, 6) mới chỉ tạo XML nên phải đọc createInvoiceResult.
    $results = $this->keyBy(
      $this->decodeNested($this->field($response, "publishInvoiceResult"))
        ?: $this->decodeNested($this->field($response, "createInvoiceResult")),
      "RefID"
    );

    if (empty($results)) {
      throw new \DomainException($this->publishErrors($response));
    }

    if (!$get_file) {
      return $results;
    }

    // Gọi Download một lần cho cả lô thay vì mỗi hóa đơn một lần.
    $transactions = [];

    foreach ($results as $ref => $result) {
      if (empty($result["ErrorCode"]) && !empty($result["TransactionID"])) {
        $transactions[$result["TransactionID"]] = $ref;
      }
    }

    if (empty($transactions)) {
      return $results;
    }

    try {
      foreach ($this->pdf($config, array_keys($transactions)) as $transaction => $file) {
        $ref = $transactions[$transaction] ?? NULL;

        if ($ref !== NULL) {
          $results[$ref]["base64"] = $file["Data"];
        }
      }
    }
    catch (\DomainException $e) {
      // Hóa đơn đã phát hành thành công rồi, thiếu PDF không được phép làm
      // hỏng cả lệnh; tải lại sau bằng nút tải PDF.
      $this->logger->warning(
        "Đã phát hành hóa đơn nhưng chưa tải được PDF: @message",
        ["@message" => $e->getMessage()]
      );
    }

    return $results;
  }

  /**
   * Chọn kiểu ký phù hợp với loại hóa đơn đang phát hành.
   *
   * @param array $config
   *   Cấu hình kết nối, có thể ép kiểu ký qua khoá "invoice_sign_type".
   * @param array $invoices
   *   Payload hóa đơn đã dựng.
   *
   * @return int
   *   Giá trị SignType gửi cho MISA.
   */
  private function signType(array $config, array $invoices): int {
    if (!empty($config["invoice_sign_type"])) {
      return (int) $config["invoice_sign_type"];
    }

    // Tài liệu ràng buộc kiểu ký theo loại hóa đơn: khai là hóa đơn từ máy tính
    // tiền mà vẫn ký kiểu 2 thì MISA từ chối hoặc phát hành sai loại.
    $first = reset($invoices) ?: [];

    return !empty($first["IsInvoiceCalculatingMachine"])
      ? self::SIGN_TYPE_MACHINE
      : self::SIGN_TYPE_DEFAULT;
  }

  /**
   * Gom thông điệp lỗi khi MISA không trả về hóa đơn nào.
   *
   * @param mixed $response
   *   Phản hồi của endpoint phát hành.
   *
   * @return string
   *   Thông điệp hiển thị được cho người dùng.
   */
  private function publishErrors(mixed $response): string {
    $messages = [];

    foreach ($this->decodeNested($this->field((array) $response, "errors")) as $error) {
      if (is_string($error)) {
        $messages[] = $error;
        continue;
      }

      if (is_array($error)) {
        $messages[] = (string) (
          $error["DescriptionErrorCode"]
          ?? $error["ErrorCode"]
          ?? $error["Message"]
          ?? ""
        );
      }
    }

    $messages = array_filter($messages);

    return $messages
      ? implode("; ", $messages)
      : "MISA không trả về kết quả phát hành";
  }

  /**
   * Dựng payload hóa đơn theo cấu trúc MISA.
   *
   * @param array $template
   *   Mẫu số / ký hiệu hóa đơn đang chọn.
   * @param array $data
   *   Dữ liệu hóa đơn, đánh khoá theo uuid.
   * @param string $type
   *   "create" hoặc "replace".
   *
   * @return array
   *   Payload đánh khoá theo uuid.
   */
  private function buildInvoices(array $template, array $data, string $type = "create"): array {
    $invoices = [];

    foreach ($data as $key => $item) {
      if (!is_array($item)) {
        continue;
      }

      $total_amount = (float) ($item["invoice_total_amount"] ?? 0);
      $lines = $this->buildProducts($item["products"] ?? []);

      $invoices[$key] = [
        "RefID" => $item["invoice_uuid"] ?? $this->uuid->generate(),
        "InvTemplateNo" => $template["pattern"] ?? NULL,
        "InvSeries" => $template["serial"] ?? "",
        "InvoiceName" => $item["invoice_title"] ?? "",
        "IsInvoiceCalculatingMachine" => (bool) ($item["invoice_machine"] ?? TRUE),
        "InvDate" => $item["invoice_date"] ?? date("Y-m-d"),
        "CurrencyCode" => $item["invoice_currency_code"] ?? "VND",
        "ExchangeRate" => 1,
        "PaymentMethodName" => $item["invoice_payment"] ?? NULL,
        "BuyerLegalName" => ($item["invoice_buyer_legal"] ?? "") ?: "Khách vãng lai",
        "BuyerCode" => $item["invoice_buyer_code"] ?? NULL,
        "BuyerFullName" => ($item["invoice_buyer_name"] ?? "") ?: "NGƯỜI MUA KHÔNG LẤY HÓA ĐƠN",
        "BuyerTaxCode" => $item["invoice_buyer_taxcode"] ?? NULL,
        "BuyerAddress" => (string) ($item["invoice_buyer_address"] ?? ""),
        "BuyerPhoneNumber" => $item["invoice_buyer_phone"] ?? NULL,
        "BuyerEmail" => $item["invoice_buyer_email"] ?? NULL,
        "ContactName" => $item["invoice_buyer_name"] ?? NULL,
        "TotalSaleAmountOC" => (float) ($item["invoice_amount"] ?? 0),
        "TotalSaleAmount" => (float) ($item["invoice_amount"] ?? 0),
        "TotalDiscountAmountOC" => (float) ($item["invoice_discount_amount"] ?? 0),
        "TotalDiscountAmount" => (float) ($item["invoice_discount_amount"] ?? 0),
        "TotalAmountWithoutVATOC" => (float) ($item["invoice_amount_without_vat"] ?? 0),
        "TotalAmountWithoutVAT" => (float) ($item["invoice_amount_without_vat"] ?? 0),
        "TotalVATAmountOC" => (float) ($item["invoice_vat_amount"] ?? 0),
        "TotalVATAmount" => (float) ($item["invoice_vat_amount"] ?? 0),
        "TotalAmountOC" => $total_amount,
        "TotalAmount" => $total_amount,
        "TotalAmountInWords" => $this->getNumberToWords->handle($total_amount),
        "IsTaxReduction43" => FALSE,
        "OriginalInvoiceDetail" => $lines["products"],
        "TaxRateInfo" => array_values($lines["tax_info"]),
        "OptionUserDefined" => [
          "MainCurrency" => "VND",
          "AmountDecimalDigits" => "0",
          "AmountOCDecimalDigits" => "0",
          "UnitPriceOCDecimalDigits" => "0",
          "UnitPriceDecimalDigits" => "0",
          "QuantityDecimalDigits" => "2",
          "CoefficientDecimalDigits" => "0",
          "ExchangRateDecimalDigits" => NULL,
        ],
      ];

      if ($type === "replace") {
        if (empty($item["replace"])) {
          throw new \DomainException("Thiếu thông tin hóa đơn bị thay thế");
        }

        $invoices[$key] += $this->buildReplace($item["replace"]);
      }
    }

    return $invoices;
  }

  /**
   * Dựng dòng hàng hoá kèm bảng tổng hợp theo thuế suất.
   *
   * @param array $products
   *   Danh sách dòng hàng của hóa đơn.
   *
   * @return array
   *   Gồm "products" (dòng chi tiết) và "tax_info" (tổng hợp theo thuế suất).
   */
  private function buildProducts(array $products): array {
    $line = 1;
    $result = [];
    $tax_info = [];

    foreach ($products as $product) {
      $type = (int) ($product["type"] ?? 1);
      $vat_rate_name = $this->vatRateName($product["vat_rate"] ?? 0, $product["name"] ?? "");

      $amount_without_vat = (float) ($product["amount_without_vat"] ?? 0);
      $vat_amount = (float) ($product["vat_amount"] ?? 0);

      if (!isset($tax_info[$vat_rate_name])) {
        $tax_info[$vat_rate_name] = [
          "VATRateName" => $vat_rate_name,
          "AmountWithoutVATOC" => 0,
          "VATAmountOC" => 0,
        ];
      }

      $tax_info[$vat_rate_name]["AmountWithoutVATOC"] += $amount_without_vat;
      $tax_info[$vat_rate_name]["VATAmountOC"] += $vat_amount;

      $result[] = [
        "ItemType" => $type,
        "LineNumber" => $line,
        // Dòng ghi chú / khuyến mại không đánh số thứ tự trên bản in.
        "SortOrder" => in_array($type, [1, 2], TRUE) ? $line : NULL,
        "ItemCode" => $product["code"] ?? NULL,
        "ItemName" => $product["name"] ?? NULL,
        "UnitName" => $product["unit"] ?? NULL,
        "Quantity" => (float) ($product["quantity"] ?? 0),
        "UnitPrice" => (float) ($product["price"] ?? 0),
        "DiscountRate" => (float) ($product["discount_rate"] ?? 0),
        "DiscountAmountOC" => (float) ($product["discount_amount"] ?? 0),
        "DiscountAmount" => (float) ($product["discount_amount"] ?? 0),
        "AmountOC" => (float) ($product["amount"] ?? 0),
        "Amount" => (float) ($product["amount"] ?? 0),
        "AmountWithoutVATOC" => $amount_without_vat,
        "AmountWithoutVAT" => $amount_without_vat,
        "VATRateName" => $vat_rate_name,
        "VATAmountOC" => $vat_amount,
        "VATAmount" => $vat_amount,
      ];

      $line++;
    }

    return [
      "products" => $result,
      "tax_info" => $tax_info,
    ];
  }

  /**
   * Đổi thuế suất của một dòng hàng sang tên thuế suất MISA chấp nhận.
   *
   * Tài liệu chỉ nhận "KCT", "KKKNT", "0%", "5%", "8%", "10%" và "KHAC:x%".
   *
   * @param mixed $rate
   *   Thuế suất thô, số hoặc tên thuế suất đặc biệt.
   * @param string $name
   *   Tên hàng hoá, chỉ dùng để báo lỗi cho dễ tìm.
   *
   * @return string
   *   Tên thuế suất gửi cho MISA.
   */
  private function vatRateName(mixed $rate, string $name): string {
    if (is_string($rate)) {
      $special = strtoupper(trim($rate));

      if (in_array($special, self::SPECIAL_VAT_RATES, TRUE)) {
        return $special;
      }
    }

    $rate = (float) $rate;

    if (in_array($rate, self::STANDARD_VAT_RATES, TRUE)) {
      return $rate . "%";
    }

    // "KHAC:-1%" không phải thuế suất hợp lệ; báo ngay tại chỗ thay vì để MISA
    // trả về InvoiceDetail_VATRateName không rõ dòng nào sai.
    if ($rate < 0) {
      throw new \DomainException(sprintf(
        'Thuế suất không hợp lệ (%s) ở dòng hàng "%s". Dùng KCT hoặc KKKNT cho hàng không chịu thuế.',
        $rate,
        $name
      ));
    }

    return "KHAC:" . $rate . "%";
  }

  /**
   * Khối thông tin hóa đơn gốc khi phát hành hóa đơn thay thế.
   *
   * @param array $replace
   *   Thông tin hóa đơn bị thay thế.
   *
   * @return array
   *   Các trường Org* của MISA.
   */
  private function buildReplace(array $replace): array {
    return [
      // 1 = hóa đơn thay thế (2 = hóa đơn điều chỉnh).
      "ReferenceType" => 1,
      // 1 = hóa đơn theo Nghị định 123 (3 = theo Nghị định 51).
      "OrgInvoiceType" => 1,
      "OrgInvDate" => $replace["invoice_date"] ?? date("Y-m-d"),
      "OrgInvNo" => $replace["invoice_no"] ?? NULL,
      "OrgInvTemplateNo" => $replace["invoice_template_no"] ?? NULL,
      "OrgInvSeries" => $replace["invoice_template_series"] ?? NULL,
      // Lý do thay thế, in trên bản thể hiện của hóa đơn mới.
      "InvoiceNote" => $replace["invoice_note"] ?? NULL,
    ];
  }

  /**
   * Ánh xạ một hóa đơn đầu vào của MISA sang tên field của entity invoice.
   *
   * @param array $item
   *   Hóa đơn thô từ API.
   *
   * @return array
   *   Mảng field => giá trị.
   */
  private function mapInputInvoice(array $item): array {
    $products = [];

    foreach ($item["Items"] ?? [] as $product) {
      $products[] = [
        "item_name" => $product["ItemName"] ?? "",
        "item_code" => $product["ItemCode"] ?? "",
        "item_unit" => $product["UnitName"] ?? "",
        "item_quantity" => $product["Quantity"] ?? 0,
        "item_price" => $product["UnitPrice"] ?? 0,
        "item_amount" => $product["Amount"] ?? 0,
        "item_discount_rate" => $product["DiscountRate"] ?? 0,
        "item_discount_amount" => $product["DiscountAmount"] ?? 0,
        "item_amount_without_vat" => $product["AmountWithoutVat"] ?? 0,
        "item_vat_rate" => $product["VatRate"] ?? 0,
        "item_vat_amount" => $product["VatAmount"] ?? 0,
        "item_total_amount" => $product["Amount"] ?? 0,
        "item_type" => $product["Nature"] ?? 0,
      ];
    }

    // InvoiceRelateds khi thì là một object, khi thì là danh sách.
    $related = $item["InvoiceRelateds"] ?? [];
    if (!is_array($related)) {
      $related = [];
    }
    elseif (isset($related["InvoiceId"])) {
      $related = [$related];
    }
    $related = reset($related) ?: [];

    return [
      "field_invoice_name" => $item["InvoiceName"] ?? $item["TitleInvoiceText"] ?? "Hóa đơn đầu vào",
      "field_invoice_id" => $item["InvoiceId"] ?? "",
      "field_invoice_no" => $item["InvoiceNo"] ?? "",
      "field_invoice_pattern" => $item["TemplateNo"] ?? "",
      "field_invoice_serial" => ($item["TemplateNo"] ?? "") . ($item["Series"] ?? ""),
      "field_invoice_status" => $this->mapStatus($item["StatusInvoice"] ?? NULL),
      "field_invoice_status_custorm" => $item["Status"] ?? NULL,
      "field_invoice_seller_name" => $item["SellerName"] ?? "",
      "field_invoice_seller_taxcode" => $item["SellerTaxCode"] ?? "",
      "field_invoice_seller_address" => $item["SellerAddress"] ?? "",
      "field_invoice_seller_phone" => $item["SellerPhoneNumber"] ?? "",
      "field_invoice_buyer_name" => $item["BuyerName"] ?? "",
      "field_invoice_buyer_taxcode" => $item["BuyerTaxCode"] ?? "",
      "field_invoice_buyer_address" => $item["BuyerAddress"] ?? "",
      "field_invoice_mccqt" => $item["MCCQT"] ?? "",
      "field_invoice_mccqt_text" => $item["MCCQTText"] ?? "",
      "field_amount_payment_status" => !empty($item["AmountPayment"]) ? $item["AmountPayment"] ?? 0 : 0,
      "field_total_amount_payment" =>  !empty($item["TotalAmountPayment"]) ? $item["TotalAmountPayment"] ?? 0 : 0,
      "field_total_amount_not_payment" =>  !empty($item["TotalAmountNotPayment"]) ? $item["TotalAmountNotPayment"] ?? 0 : 0,
      "field_invoice_payment_due_date" => $this->formatDate($item["PaymentDueDate"] ?? NULL),
      "field_invoice_payment" => match ($item["PaymentMethod"] ?? "") {
        "TM" => "inv_tm",
        "CK" => "inv_ck",
        default => "inv_tm_ck",
      },
      "field_invoice_date" => $this->formatDate($item["InvoiceDate"] ?? NULL),
      "field_invoice_amount" => $item["Amount"] ?? $item["TotalSaleAmount"] ?? 0,
      "field_invoice_discount_amount" => $item["TotalDiscountAmount"] ?? 0,
      "field_invoice_amount_without_vat" => $item["TotalAmountWithoutVat"] ?? 0,
      "field_invoice_vat_amount" => $item["TotalVATAmount"] ?? 0,
      "field_invoice_vat_rate" => $item["VatRate"] ?? 0,
      "field_invoice_total_amount" => $item["TotalAmount"] ?? 0,
      "field_invoice_license_plate" => $item["LicensePlate"] ?? "",
      "field_invoice_relateds" => $related["InvoiceId"] ?? "",
      "field_invoice_items" => $products,
      "field_invoice_refno" => $item["RefNo"] ?? "",
      "field_invoice_accountant" => $item["Accountant"] ?? "",
      "field_invoice_accounting_date" => $this->formatDate($item["AccountingDate"] ?? NULL),
    ];
  }

  /**
   * Ép trạng thái MISA về khoảng giá trị field_invoice_status cho phép.
   *
   * @param mixed $status
   *   Giá trị StatusInvoice của MISA.
   *
   * @return int
   *   0-7, trong đó 7 là "HĐ chưa xác định".
   */
  private function mapStatus(mixed $status): int {
    if (!is_numeric($status)) {
      return 7;
    }

    $status = (int) $status;
    return ($status >= 0 && $status <= 7) ? $status : 7;
  }

  /**
   * Đổi chuỗi ngày của MISA sang Y-m-d.
   *
   * @param mixed $value
   *   Chuỗi ngày thô.
   *
   * @return string|null
   *   Ngày theo Y-m-d, NULL khi không đọc được.
   */
  private function formatDate(mixed $value): ?string {
    if (empty($value) || !is_string($value)) {
      return NULL;
    }

    try {
      return (new \DateTime($value))->format("Y-m-d");
    }
    catch (\Throwable) {
      // Bắt \Throwable chứ không phải \Exception: PHP 8.3 ném
      // DateMalformedStringException và một số môi trường (Xdebug) làm hỏng
      // chuỗi xử lý ngoại lệ đó thành lỗi nghiêm trọng.
      return NULL;
    }
  }

  /**
   * Gọi API MISA.
   *
   * @param string $base
   *   Base URL lấy từ cấu hình.
   * @param string $path
   *   Đường dẫn endpoint, có thể kèm query string.
   * @param array $headers
   *   Header bổ sung.
   * @param array|null $payload
   *   Body JSON; NULL nghĩa là không gửi body (dùng cho GET).
   * @param string $method
   *   Phương thức HTTP.
   * @param int $timeout
   *   Timeout tính bằng giây.
   *
   * @return mixed
   *   Mảng đã giải mã JSON, hoặc chuỗi nhị phân với phản hồi dạng file.
   *
   * @throws \DomainException
   *   Khi không kết nối được hoặc MISA trả mã lỗi HTTP.
   * @throws InvoiceTokenException
   *   Khi MISA từ chối vì token.
   */
  private function request(
    string $base,
    string $path,
    array $headers = [],
    ?array $payload = NULL,
    string $method = "POST",
    int $timeout = 30,
  ): mixed {
    if (trim($base) === "") {
      throw new \DomainException("Chưa cấu hình địa chỉ máy chủ MISA");
    }

    $options = [
      "headers" => ["Content-Type" => "application/json"] + $headers,
      "timeout" => $timeout,
      "http_errors" => FALSE,
    ];

    if ($payload !== NULL) {
      $options["json"] = $payload;
    }

    $url = rtrim($base, "/") . "/" . ltrim($path, "/");

    try {
      $response = $this->client->request($method, $url, $options);
    }
    catch (GuzzleException $e) {
      throw new \DomainException("Không kết nối được MISA: " . $e->getMessage(), 0, $e);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    $type = $response->getHeaderLine("Content-Type");

    if (str_contains($type, "zip") || str_contains($type, "octet-stream")) {
      return $body;
    }

    $decoded = json_decode($body, TRUE);

    if ($status === 401 || $status === 403) {
      throw new InvoiceTokenException("MISA từ chối token (HTTP {$status})");
    }

    if ($status >= 400) {
      $message = is_array($decoded)
        ? (string) ($decoded["Message"] ?? $decoded["descriptionErrorCode"] ?? $decoded["errorCode"] ?? "")
        : "";

      $this->logger->error("MISA @method @url trả về HTTP @status: @body", [
        "@method" => $method,
        "@url" => $url,
        "@status" => $status,
        "@body" => mb_substr($body, 0, 1000),
      ]);

      throw new \DomainException(
        $message !== "" ? $message : "MISA trả về lỗi HTTP {$status}"
      );
    }

    return $decoded === NULL && $body !== "" ? $body : $decoded;
  }

  /**
   * Bóc phong bì của nhóm API "/api/integration" (khoá viết thường).
   *
   * @param mixed $response
   *   Phản hồi đã giải mã.
   * @param string $fallback
   *   Thông điệp dùng khi MISA không nói rõ lỗi.
   *
   * @return array
   *   Phản hồi hợp lệ.
   */
  private function integration(mixed $response, string $fallback = "MISA từ chối yêu cầu"): array {
    if (!is_array($response)) {
      throw new \DomainException($fallback);
    }

    // Nhóm API này không nhất quán hoa/thường: /auth/token trả
    // "Success"/"Data"/"ErrorCode" còn /invoice trả "success"/"data"/
    // "errorCode". Đọc cả hai kiểu thay vì đoán theo từng endpoint.
    if (empty($response["success"]) && empty($response["Success"])) {
      $code = (string) ($response["errorCode"] ?? $response["ErrorCode"] ?? "");
      $this->assertNotTokenError($code);

      $message = (string) (
        $response["descriptionErrorCode"]
        ?? $response["message"]
        ?? (is_string($response["Errors"] ?? NULL) ? $response["Errors"] : NULL)
        ?? $code
      );

      throw new \DomainException($message !== "" ? $message : $fallback);
    }

    return $response;
  }

  /**
   * Đọc một khoá của phong bì integration bất kể viết hoa hay thường.
   *
   * @param array $response
   *   Phản hồi đã bóc phong bì.
   * @param string $key
   *   Tên khoá dạng viết thường, ví dụ "data".
   *
   * @return mixed
   *   Giá trị tương ứng, NULL khi không có.
   */
  private function field(array $response, string $key): mixed {
    return $response[$key] ?? $response[ucfirst($key)] ?? NULL;
  }

  /**
   * Bóc phong bì của nhóm API "/api2" và "/inbot" (khoá viết hoa).
   *
   * @param mixed $response
   *   Phản hồi đã giải mã.
   * @param string $fallback
   *   Thông điệp dùng khi MISA không nói rõ lỗi.
   *
   * @return array
   *   Phản hồi hợp lệ.
   */
  private function api2(mixed $response, string $fallback = "MISA từ chối yêu cầu"): array {
    if (!is_array($response)) {
      throw new \DomainException($fallback);
    }

    if (empty($response["Success"])) {
      $code = (string) ($response["ErrorCode"] ?? $response["Code"] ?? "");
      $this->assertNotTokenError($code);

      $message = (string) ($response["Message"] ?? $code);
      throw new \DomainException($message !== "" ? $message : $fallback);
    }

    return $response;
  }

  /**
   * Đổi mã lỗi khắc phục được của MISA thành ngoại lệ riêng.
   *
   * Lỗi token thì tầng nghiệp vụ xin token mới rồi gọi lại, còn lỗi tranh chấp
   * số hóa đơn thì phát hành lại nguyên yêu cầu.
   *
   * @param string $code
   *   Mã lỗi MISA trả về.
   */
  private function assertNotTokenError(string $code): void {
    if ($code === "") {
      return;
    }

    $code = strtolower($code);

    if (in_array($code, self::TOKEN_ERRORS, TRUE)) {
      throw new InvoiceTokenException("Token MISA đã hết hạn ({$code})");
    }

    if (in_array($code, self::RETRY_ERRORS, TRUE)) {
      throw new InvoiceRetryException("MISA báo lỗi tạm thời ({$code})");
    }
  }

  /**
   * Giải mã phần JSON mà MISA lồng dưới dạng chuỗi.
   *
   * @param mixed $value
   *   Giá trị thô, có thể là mảng sẵn hoặc chuỗi JSON.
   *
   * @return array
   *   Mảng đã giải mã, rỗng khi không đọc được.
   */
  private function decodeNested(mixed $value): array {
    if (is_array($value)) {
      return $value;
    }

    if (!is_string($value) || $value === "") {
      return [];
    }

    $decoded = json_decode($value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Đánh khoá danh sách kết quả theo một trường.
   *
   * @param array $items
   *   Danh sách kết quả.
   * @param string $key
   *   Tên trường dùng làm khoá.
   *
   * @return array
   *   Danh sách đã đánh khoá, bỏ qua phần tử thiếu khoá.
   */
  private function keyBy(array $items, string $key): array {
    $result = [];

    foreach ($items as $item) {
      if (is_array($item) && !empty($item[$key])) {
        $result[(string) $item[$key]] = $item;
      }
    }

    return $result;
  }

  /**
   * Đổi phản hồi tải file sang chuỗi nhị phân.
   *
   * MISA trả khi thì nhị phân thẳng, khi thì mảng JSON các byte.
   *
   * @param mixed $response
   *   Phản hồi của endpoint tải file.
   *
   * @return string
   *   Nội dung nhị phân, chuỗi rỗng khi chưa có file.
   */
  private function toBinary(mixed $response): string {
    if (is_string($response)) {
      return $response;
    }

    if (is_array($response) && $response !== [] && array_is_list($response)) {
      $bytes = array_filter($response, "is_int");

      if (count($bytes) === count($response)) {
        return pack("C*", ...$response);
      }
    }

    return "";
  }

  /**
   * Header xác thực của nhóm API "/api2".
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Header AppID, CompanyTaxCode, UserName.
   */
  private function authHeaders(array $config): array {
    return [
      "AppID" => (string) ($config["invoice_appid"] ?? ""),
      "CompanyTaxCode" => (string) ($config["invoice_taxcode"] ?? ""),
      "UserName" => (string) ($config["invoice_username"] ?? ""),
    ];
  }

  /**
   * Header Bearer của nhóm API "/api/integration".
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Header Authorization.
   */
  private function bearer(array $config): array {
    if (empty($config["invoice_token"])) {
      throw new InvoiceTokenException("Chưa có token phát hành hóa đơn");
    }

    return ["Authorization" => "Bearer " . $config["invoice_token"]];
  }

  /**
   * Header của nhóm API "/inbot".
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Header ClientId và Authorization.
   */
  private function inbotHeaders(array $config): array {
    if (empty($config["invoice_jwt_token"])) {
      throw new InvoiceTokenException("Chưa có JWT token hóa đơn đầu vào");
    }

    return [
      "ClientId" => (string) ($config["invoice_client"] ?? ""),
      "Authorization" => "Bearer " . $config["invoice_jwt_token"],
    ];
  }

  /**
   * Dựng đường dẫn "/inbot" có subscriber và organization.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $suffix
   *   Phần đuôi của endpoint.
   *
   * @return string
   *   Đường dẫn đầy đủ.
   */
  private function inbotPath(array $config, string $suffix): string {
    $subscribers = (string) ($config["invoice_subscribers"] ?? "");
    $organization = (string) ($config["invoice_organization"] ?? "");

    if ($subscribers === "" || $organization === "") {
      throw new InvoiceTokenException("Chưa có subscriber/organization của MISA");
    }

    return "/inbot/api/{$subscribers}/{$organization}/" . ltrim($suffix, "/");
  }

  /**
   * Tách secure token khỏi chuỗi "sessionId;token" của MISA.
   *
   * @param string $value
   *   Giá trị Data của validateUser.
   *
   * @return string
   *   Phần token.
   */
  private function extractSecureToken(string $value): string {
    $position = strpos($value, ";");
    return $position === FALSE ? $value : substr($value, $position + 1);
  }

}
