<?php

namespace Drupal\e_invoice\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Utility\Token;
use Drupal\e_invoice\Exception\InvoiceTokenException;
use Drupal\e_invoice\InvoiceInterface;
use Drupal\e_invoice\InvoiceProvidersInterface;
use Drupal\e_invoice\InvoiceProvidersPluginManager;
use Drupal\file\FileRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Điều phối các thao tác hóa đơn giữa entity invoice và nhà cung cấp.
 *
 * Mọi phương thức công khai đều trả mảng có khoá "success"; lỗi nghiệp vụ nằm ở
 * "message" thay vì ném ngoại lệ, để controller hiển thị thẳng cho người dùng.
 */
class HandleInvoice {

  /**
   * Bundle của hóa đơn đầu vào.
   */
  private const BUNDLE_IN = "input_invoices";

  /**
   * Bundle của hóa đơn đầu ra.
   */
  private const BUNDLE_OUT = "output_invoices";

  /**
   * Trạng thái "HĐ đã bị thay thế".
   */
  private const STATUS_REPLACED = 4;

  /**
   * Trạng thái "HĐ đã bị xóa bỏ/hủy bỏ".
   */
  private const STATUS_CANCELLED = 6;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected InvoiceProvidersPluginManager $providers,
    protected GetConfigInvoice $configService,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected Connection $database,
    protected LoggerInterface $logger,
    protected Token $token,
    protected TimeInterface $time,
  ) {}

  /**
   * Xem trước PDF hóa đơn chưa phát hành.
   *
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Dữ liệu hóa đơn, đánh khoá theo uuid.
   *
   * @return array
   *   Phản hồi nhà cung cấp, khoá "data" là đường dẫn xem trước.
   */
  public function previewInvoice(array $config, array $data): array {
    return $this->guard(function () use ($config, $data) {
      $result = $this->call(
        $config,
        fn (InvoiceProvidersInterface $provider, array $config) => $provider->preview($config, $data)
      );

      if (empty($result["data"])) {
        return [
          "success" => FALSE,
          "message" => "Nhà cung cấp không trả về bản xem trước",
        ];
      }

      return array_merge($result, ["success" => TRUE]);
    });
  }

  /**
   * Phát hành hóa đơn đầu ra.
   *
   * @param array $invoices
   *   Entity hóa đơn, đánh khoá theo uuid.
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Dữ liệu phát hành, đánh khoá theo uuid.
   * @param bool $get_file
   *   TRUE để tải kèm PDF ngay sau khi phát hành.
   *
   * @return array
   *   Gồm "success", "issued" (entity đã phát hành) và "errors".
   */
  public function issueInvoice(array $invoices, array $config, array $data, bool $get_file = FALSE): array {
    return $this->guard(function () use ($invoices, $config, $data, $get_file) {
      $results = $this->call(
        $config,
        fn (InvoiceProvidersInterface $provider, array $config) => $provider->issue($config, $data, $get_file)
      );

      return $this->applyPublishResults($invoices, $results, [
        "field_invoice_status" => 1,
      ]);
    });
  }

  /**
   * Phát hành hóa đơn thay thế.
   *
   * @param array $invoices
   *   Entity hóa đơn thay thế, đánh khoá theo uuid.
   * @param array $config
   *   Cấu hình kết nối, kèm khoá "invoice_template".
   * @param array $data
   *   Dữ liệu phát hành, đánh khoá theo uuid.
   * @param bool $get_file
   *   TRUE để tải kèm PDF ngay sau khi phát hành.
   *
   * @return array
   *   Gồm "success", "issued" và "errors".
   */
  public function replaceInvoice(array $invoices, array $config, array $data, bool $get_file = TRUE): array {
    return $this->guard(function () use ($invoices, $config, $data, $get_file) {
      $originals = [];

      // Gắn thông tin hóa đơn gốc trước khi gọi API: thiếu một hóa đơn gốc hợp
      // lệ là hỏng cả lô, dừng sớm hơn là phát hành nửa vời.
      foreach ($data as $uuid => $item) {
        $invoice = $invoices[$uuid] ?? NULL;

        if (!$invoice instanceof InvoiceInterface) {
          return [
            "success" => FALSE,
            "message" => "Không tìm thấy hóa đơn cần thay thế",
          ];
        }

        /** @var InvoiceInterface|null $original */
        $original = $invoice->get("field_invoice_id_related")->entity;

        if (!$original instanceof InvoiceInterface) {
          return [
            "success" => FALSE,
            "message" => "The replaced invoice is invalid.",
          ];
        }

        $status = (int) $original->get("field_invoice_status")->value;

        if (in_array($status, [self::STATUS_REPLACED, self::STATUS_CANCELLED], TRUE)) {
          return [
            "success" => FALSE,
            "message" => "The replaced invoice is invalid.",
          ];
        }

        $data[$uuid]["replace"] = $this->buildOriginalReference($original);
        $originals[$uuid] = $original;
      }

      $results = $this->call(
        $config,
        fn (InvoiceProvidersInterface $provider, array $config) => $provider->replace($config, $data, $get_file)
      );

      $outcome = $this->applyPublishResults($invoices, $results, [
        "field_invoice_status" => 2,
      ]);

      // Chỉ đánh dấu hóa đơn gốc là đã bị thay thế khi bản thay thế phát hành
      // thành công.
      foreach ($outcome["issued"] as $uuid => $invoice) {
        if (isset($originals[$uuid])) {
          $this->updateInvoice($originals[$uuid], [
            "field_invoice_status" => self::STATUS_REPLACED,
          ]);
        }
      }

      return $outcome;
    });
  }

  /**
   * Cập nhật trạng thái các hóa đơn đã phát hành.
   *
   * @param array $invoices
   *   Entity hóa đơn đầu ra.
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $params
   *   Tham số bổ sung "calcu", "withCode".
   *
   * @return array
   *   Kết quả xử lý.
   */
  public function statusInvoice(array $invoices, array $config, array $params = []): array {
    return $this->guard(function () use ($invoices, $config, $params) {
      $by_transaction = $this->indexByTransaction($invoices);

      if (empty($by_transaction)) {
        return [
          "success" => FALSE,
          "message" => "Not found transaction ID.",
        ];
      }

      $results = $this->call(
        $config,
        fn (InvoiceProvidersInterface $provider, array $config) => $provider->status(
          $config,
          array_keys($by_transaction),
          $params
        )
      );

      $updated = 0;

      foreach ($results as $transaction => $result) {
        $invoice = $by_transaction[$transaction] ?? NULL;

        if (!$invoice instanceof InvoiceInterface) {
          continue;
        }

        $fields = [];

        if (isset($result["InvoiceCode"])) {
          $fields["field_invoice_mccqt"] = $result["InvoiceCode"];
        }

        if (!empty($result["InvNo"])) {
          $fields["field_invoice_no"] = $result["InvNo"];
        }

        if (isset($result["SendTaxStatus"])) {
          $cqt = (int) $result["SendTaxStatus"];
          $fields["field_invoice_status_cqt"] = ($cqt >= 0 && $cqt <= 4) ? $cqt : 0;
        }

        // Trạng thái hóa đơn bên MISA đánh số khác hệ thống này, phải ánh xạ
        // thì mới biết hóa đơn đã bị hủy hay bị thay thế.
        if (isset($result["EInvoiceStatus"])) {
          $status = $this->mapEInvoiceStatus((int) $result["EInvoiceStatus"]);

          if ($status !== NULL) {
            $fields["field_invoice_status"] = $status;
          }
        }

        if (!empty($result["PublishedTime"])) {
          $published = $this->toUtc($result["PublishedTime"]);

          if ($published !== NULL) {
            $fields["field_invoice_date"] = $published;
          }
        }

        if ($fields !== []) {
          $this->updateInvoice($invoice, $fields);
          $updated++;
        }
      }

      if ($updated === 0) {
        return [
          "success" => FALSE,
          "message" => "Invoice status failed",
        ];
      }

      return ["success" => TRUE];
    });
  }

  /**
   * Tải và lưu PDF của hóa đơn đầu ra.
   *
   * @param array $invoices
   *   Entity hóa đơn đầu ra.
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Kết quả xử lý.
   */
  public function pdfOutputInvoice(array $invoices, array $config): array {
    return $this->guard(function () use ($invoices, $config) {
      $by_transaction = $this->indexByTransaction($invoices);

      if (empty($by_transaction)) {
        return [
          "success" => FALSE,
          "message" => "Not found transaction ID.",
        ];
      }

      $files = $this->call(
        $config,
        fn (InvoiceProvidersInterface $provider, array $config) => $provider->pdf(
          $config,
          array_keys($by_transaction)
        )
      );

      $saved = 0;

      foreach ($files as $transaction => $file) {
        $invoice = $by_transaction[$transaction] ?? NULL;

        if ($invoice instanceof InvoiceInterface
          && $this->attachPdf($invoice, $file["Data"] ?? "", $transaction)) {
          $saved++;
        }
      }

      if ($saved === 0) {
        return [
          "success" => FALSE,
          "message" => "Invoice pdf failed",
        ];
      }

      return [
        "success" => TRUE,
        "message" => $saved < count($by_transaction)
          ? "Một số hóa đơn chưa có file PDF, thử lại sau ít phút."
          : NULL,
      ];
    });
  }

  /**
   * Kéo hóa đơn đầu vào từ nhà cung cấp và tạo entity.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $params
   *   Bộ lọc "from", "to", "skip".
   * @param array $fields
   *   Field gán thêm cho mọi hóa đơn tạo mới, ví dụ công ty.
   *
   * @return array
   *   Gồm "success" và "data" (entity vừa tạo).
   */
  public function modifiedInvoice(array $config, array $params, array $fields = []): array {
    return $this->guard(function () use ($config, $params, $fields) {
      $result = $this->call(
        $config,
        fn (InvoiceProvidersInterface $provider, array $config) => $provider->modified($config, $params)
      );

      $existing = $this->findExistingInvoiceIds(array_column($result, "field_invoice_id"));
      $storage = $this->entityTypeManager->getStorage("invoice");
      $data = [];

      foreach ($result as $item) {
        $invoice_id = (string) ($item["field_invoice_id"] ?? "");

        if ($invoice_id === "" || isset($existing[$invoice_id])) {
          continue;
        }

        $existing[$invoice_id] = TRUE;
        $data[] = $this->createInputInvoice($storage, $item, $fields);
      }

      return [
        "success" => TRUE,
        "data" => $data,
      ];
    });
  }

  /**
   * Hạch toán hóa đơn đầu vào.
   *
   * @param array $invoices
   *   Entity hóa đơn đầu vào.
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $params
   *   Gồm "accountant", "accountant_date", "ref_no".
   *
   * @return array
   *   Kết quả xử lý.
   */
  public function accountingInvoice(array $invoices, array $config, array $params = []): array {
    return $this->guard(function () use ($invoices, $config, $params) {
      $by_id = [];

      foreach ($invoices as $invoice) {
        $invoice_id = $invoice->get("field_invoice_id")->value;

        if (!empty($invoice_id)) {
          $by_id[$invoice_id] = $invoice;
        }
      }

      if (empty($by_id)) {
        return [
          "success" => FALSE,
          "message" => "Không tìm thấy mã hóa đơn để hạch toán",
        ];
      }

      $params["invoice_id"] = array_keys($by_id);

      $this->call(
        $config,
        fn (InvoiceProvidersInterface $provider, array $config) => $provider->accounting($config, $params)
      );

      foreach ($by_id as $invoice) {
        $this->updateInvoice($invoice, [
          "field_invoice_accountant" => $params["accountant"] ?? NULL,
          "field_invoice_accounting_date" => $params["accountant_date"] ?? NULL,
          "field_invoice_refno" => $params["ref_no"] ?? NULL,
        ]);
      }

      return ["success" => TRUE];
    });
  }

  /**
   * Cập nhật thông tin thanh toán hóa đơn đầu vào.
   *
   * @param array $invoices
   *   Entity hóa đơn đầu vào.
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $params
   *   Gồm "payment_date", "payment_pair", "total_amount_payment",
   *   "total_amount_not_payment", "number_payment_next", "amount_payment".
   *
   * @return array
   *   Kết quả xử lý.
   */
  public function paymentInvoice(array $invoices, array $config, array $params = []): array {
    return $this->guard(function () use ($invoices, $config, $params) {
      $by_id = [];

      foreach ($invoices as $invoice) {
        $invoice_id = $invoice->get("field_invoice_id")->value;

        if (!empty($invoice_id)) {
          $by_id[$invoice_id] = $invoice;
        }
      }

      if (empty($by_id)) {
        return [
          "success" => FALSE,
          "message" => "Không tìm thấy mã hóa đơn để cập nhật thanh toán",
        ];
      }

      foreach ($by_id as $invoice_id => $invoice) {
        $data = $params;
        $data["invoice_id"] = $invoice_id;

        if (!isset($data["total_amount_not_payment"])) {
          $total = (float) ($invoice->get("field_invoice_total_amount")->value ?? 0);
          $paid = (float) ($data["total_amount_payment"] ?? 0);
          $data["total_amount_not_payment"] = max($total - $paid, 0);
        }

        $this->call(
          $config,
          fn (InvoiceProvidersInterface $provider, array $config) => $provider->payment($config, $data)
        );

        $this->updateInvoice($invoice, [
          "field_invoice_payment_due_date" => $data["payment_date"] ?? NULL,
          "field_total_amount_payment" => $data["total_amount_payment"] ?? NULL,
          "field_total_amount_not_payment" => $data["total_amount_not_payment"],
          "field_amount_payment_status" => $data["amount_payment"] ?? NULL,
        ]);
      }

      return ["success" => TRUE];
    });
  }

  /**
   * Tải file PDF và XML của hóa đơn đầu vào.
   *
   * @param array $invoices
   *   Entity hóa đơn đầu vào.
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Kết quả xử lý.
   */
  public function fileInputInvoice(array $invoices, array $config, string $type): array {
    return $this->guard(function () use ($invoices, $config, $type) {
      $saved = 0;
      $total = 0;

      // Gói ZIP không cho biết file nào của hóa đơn nào, nên tải riêng từng hóa
      // đơn để gắn file đúng chỗ.
      foreach ($invoices as $invoice) {
        $invoice_id = $invoice->get("field_invoice_id")->value;

        if (empty($invoice_id)) {
          continue;
        }

        $total++;

        $binary = $this->call(
          $config,
          fn (InvoiceProvidersInterface $provider, array $config) => $provider->download(
            $config,
            [$invoice_id],
            $type
          )
        );

        if ($binary === "") {
          continue;
        }

        if ($this->attachZip($invoice, $binary)) {
          $saved++;
        }
      }

      if ($total === 0) {
        return [
          "success" => FALSE,
          "message" => "Không tìm thấy mã hóa đơn để tải file",
        ];
      }

      if ($saved === 0) {
        return [
          "success" => FALSE,
          "message" => "Unable to download PDF file.",
        ];
      }

      return ["success" => TRUE];
    });
  }

  /**
   * Ghi kết quả phát hành lên entity hóa đơn.
   *
   * @param array $invoices
   *   Entity hóa đơn, đánh khoá theo uuid.
   * @param array $results
   *   Kết quả nhà cung cấp, đánh khoá theo RefID (chính là uuid).
   * @param array $extra
   *   Field gán thêm cho hóa đơn phát hành thành công.
   *
   * @return array
   *   Gồm "success", "issued" và "errors".
   */
  private function applyPublishResults(array $invoices, array $results, array $extra): array {
    $issued = [];
    $errors = [];

    if (empty($results)) {
      return [
        "success" => FALSE,
        "message" => "Nhà cung cấp không trả về kết quả phát hành",
      ];
    }

    foreach ($results as $ref => $result) {
      $invoice = $invoices[$ref] ?? NULL;

      if (!$invoice instanceof InvoiceInterface) {
        $errors[] = "Không khớp được kết quả phát hành với hóa đơn ({$ref})";
        continue;
      }

      if (!empty($result["ErrorCode"])) {
        $errors[] = sprintf(
          "%s: %s",
          $invoice->label(),
          $result["DescriptionErrorCode"] ?? $result["ErrorCode"]
        );
        continue;
      }

      $template_no = (string) ($result["InvTemplateNo"] ?? "");
      $series = (string) ($result["InvSeries"] ?? "");

      $fields = $extra + [
        "field_invoice_issue" => 1,
        "field_invoice_no" => $result["InvNo"] ?? NULL,
        "field_invoice_refno" => $result["RefID"] ?? NULL,
        "field_invoice_pattern" => $template_no,
        // Ký hiệu hiển thị gồm mẫu số ghép ký hiệu, khớp với hóa đơn nhập từ
        // file XML để chỗ nào cũng đọc được như nhau.
        "field_invoice_serial" => $template_no . $series,
        "field_invoice_transaction" => $result["TransactionID"] ?? NULL,
        "field_invoice_mccqt" => $result["InvCode"] ?? NULL,
        "field_invoice_date" => $this->toUtc($result["PublishedTime"] ?? "now") ?? $this->toUtc("now"),
      ];

      if (!empty($result["base64"]) && !empty($result["TransactionID"])) {
        $file = $this->savePdf(
          $result["base64"],
          $result["TransactionID"] . ".pdf",
          self::BUNDLE_OUT
        );

        if ($file !== NULL) {
          $fields["field_invoice_pdf"] = [
            "target_id" => $file,
            "display" => 1,
          ];
        }
      }

      $this->updateInvoice($invoice, $fields);
      $issued[$ref] = $invoice;
    }

    return [
      "success" => $issued !== [],
      "issued" => $issued,
      "errors" => $errors,
      "message" => $issued === [] ? (reset($errors) ?: "Invoice issuance failed") : NULL,
    ];
  }

  /**
   * Mô tả hóa đơn gốc để gửi kèm khi phát hành hóa đơn thay thế.
   *
   * @param InvoiceInterface $original
   *   Hóa đơn bị thay thế.
   *
   * @return array
   *   Số, mẫu số, ký hiệu và ngày của hóa đơn gốc.
   */
  private function buildOriginalReference(InvoiceInterface $original): array {
    $pattern = (string) $original->get("field_invoice_pattern")->value;
    $serial = (string) $original->get("field_invoice_serial")->value;

    // field_invoice_serial lưu mẫu số ghép ký hiệu ("1C25TAA"), MISA lại cần
    // hai phần tách rời.
    if ($pattern !== "" && str_starts_with($serial, $pattern)) {
      $serial = substr($serial, strlen($pattern));
    }

    $date = $original->get("field_invoice_date")->value;

    return [
      "invoice_no" => $original->get("field_invoice_no")->value ?? "",
      "invoice_template_no" => $pattern !== "" ? $pattern : "1",
      "invoice_template_series" => $serial,
      "invoice_date" => $date ? substr($date, 0, 10) : date("Y-m-d"),
    ];
  }

  /**
   * Ánh xạ EInvoiceStatus của MISA sang field_invoice_status của hệ thống.
   *
   * Hai bên đánh số khác nhau nên không chép thẳng được.
   *
   * @param int $status
   *   Giá trị EInvoiceStatus.
   *
   * @return int|null
   *   Giá trị field_invoice_status, NULL khi MISA trả trạng thái lạ.
   */
  private function mapEInvoiceStatus(int $status): ?int {
    return match ($status) {
      // Hóa đơn gốc.
      1 => 1,
      // Hóa đơn đã bị xóa bỏ/hủy bỏ.
      2 => self::STATUS_CANCELLED,
      // Bản thân nó là hóa đơn thay thế.
      3 => 2,
      // Bản thân nó là hóa đơn điều chỉnh.
      5 => 3,
      // Đã bị hóa đơn khác thay thế.
      7 => self::STATUS_REPLACED,
      // Đã bị hóa đơn khác điều chỉnh.
      8 => 5,
      default => NULL,
    };
  }

  /**
   * Đánh khoá hóa đơn theo mã giao dịch.
   *
   * @param array $invoices
   *   Entity hóa đơn đầu ra.
   *
   * @return array
   *   Entity đánh khoá theo TransactionID.
   */
  private function indexByTransaction(array $invoices): array {
    $result = [];

    foreach ($invoices as $invoice) {
      $transaction = $invoice->get("field_invoice_transaction")->value;

      if (!empty($transaction)) {
        $result[$transaction] = $invoice;
      }
    }

    return $result;
  }

  /**
   * Gọi nhà cung cấp, tự lấy token mới và thử lại một lần khi token hết hạn.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param callable $callback
   *   Hàm nhận (provider, config) và thực hiện lệnh.
   *
   * @return mixed
   *   Kết quả của $callback.
   */
  private function call(array $config, callable $callback): mixed {
    try {
      return $callback($this->getProvider($config), $config);
    }
    catch (InvoiceTokenException $e) {
      $refreshed = $this->configService->refresh($config);

      if ($refreshed === NULL) {
        throw new \DomainException($e->getMessage(), 0, $e);
      }

      $this->logger->info("Đã lấy lại token hóa đơn và gọi lại nhà cung cấp.");

      return $callback($this->getProvider($refreshed), $refreshed);
    }
  }

  /**
   * Bọc lỗi thành mảng kết quả để controller hiển thị.
   *
   * @param callable $callback
   *   Hàm thực hiện nghiệp vụ.
   *
   * @return array
   *   Kết quả, hoặc mảng lỗi khi có ngoại lệ.
   */
  private function guard(callable $callback): array {
    try {
      return $callback();
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error("Invoice system error: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Lấy plugin nhà cung cấp theo cấu hình.
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return InvoiceProvidersInterface
   *   Plugin nhà cung cấp.
   */
  private function getProvider(array $config): InvoiceProvidersInterface {
    $provider_id = $config["invoice_provider"] ?? "";

    if ($provider_id === "") {
      throw new \DomainException("Invoice provider not yet configured");
    }

    if (!$this->providers->hasDefinition($provider_id)) {
      throw new \DomainException("The invoice provider {$provider_id} does not exist");
    }

    /** @var InvoiceProvidersInterface $provider */
    $provider = $this->providers->createInstance($provider_id);

    return $provider;
  }

  /**
   * Gán giá trị rồi lưu hóa đơn.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn cần cập nhật.
   * @param array $fields
   *   Field và giá trị mới; field không có trên bundle sẽ được bỏ qua.
   */
  private function updateInvoice(InvoiceInterface $invoice, array $fields): void {
    foreach ($fields as $name => $value) {
      if ($invoice->hasField($name)) {
        $invoice->set($name, $value);
      }
    }

    $invoice->save();
  }

  /**
   * Tạo hóa đơn đầu vào từ dữ liệu đã ánh xạ.
   *
   * @param EntityStorageInterface $storage
   *   Storage của entity invoice.
   * @param array $data
   *   Dữ liệu đã ánh xạ sang tên field.
   * @param array $fields
   *   Field gán thêm.
   *
   * @return InvoiceInterface
   *   Hóa đơn vừa tạo.
   */
  private function createInputInvoice(EntityStorageInterface $storage, array $data, array $fields): InvoiceInterface {
    $label = $data["field_invoice_name"] ?? "Hóa đơn đầu vào";
    unset($data["field_invoice_name"]);

    $values = array_replace($data, $fields);
    $definitions = $this->entityFieldManager->getFieldDefinitions("invoice", self::BUNDLE_IN);

    $values = array_filter(
      $values,
      static fn (string $name) => isset($definitions[$name]),
      ARRAY_FILTER_USE_KEY
    );

    /** @var InvoiceInterface $invoice */
    $invoice = $storage->create($values + [
      "label" => $label,
      "bundle" => self::BUNDLE_IN,
    ]);

    $invoice->save();

    return $invoice;
  }

  /**
   * Tìm những mã hóa đơn đã có trong hệ thống.
   *
   * @param array $invoice_ids
   *   Mã hóa đơn của nhà cung cấp.
   *
   * @return array
   *   Mảng có khoá là mã hóa đơn đã tồn tại.
   */
  private function findExistingInvoiceIds(array $invoice_ids): array {
    $invoice_ids = array_values(array_unique(array_filter($invoice_ids)));

    if (empty($invoice_ids)) {
      return [];
    }

    $found = $this->database
      ->select("invoice__field_invoice_id", "f")
      ->fields("f", ["field_invoice_id_value"])
      ->condition("f.bundle", self::BUNDLE_IN)
      ->condition("f.field_invoice_id_value", $invoice_ids, "IN")
      ->execute()
      ->fetchCol();

    return array_fill_keys($found, TRUE);
  }

  /**
   * Lưu PDF base64 và gắn vào hóa đơn.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn cần gắn file.
   * @param string $base64
   *   Nội dung PDF mã hoá base64.
   * @param string $transaction
   *   Mã giao dịch, dùng đặt tên file.
   *
   * @return bool
   *   TRUE khi lưu được file.
   */
  private function attachPdf(InvoiceInterface $invoice, string $base64, string $transaction): bool {
    $file = $this->savePdf($base64, $transaction . ".pdf", $invoice->bundle());

    if ($file === NULL) {
      return FALSE;
    }

    $this->updateInvoice($invoice, [
      "field_invoice_pdf" => [
        "target_id" => $file,
        "display" => 1,
      ],
    ]);

    return TRUE;
  }

  /**
   * Giải mã và lưu file PDF.
   *
   * @param string $base64
   *   Nội dung PDF mã hoá base64.
   * @param string $filename
   *   Tên file cần lưu.
   * @param string $bundle
   *   Bundle của hóa đơn, quyết định thư mục lưu file.
   *
   * @return int|null
   *   File id, hoặc NULL khi nội dung không hợp lệ.
   */
  private function savePdf(string $base64, string $filename, string $bundle): ?int {
    try {
      $binary = base64_decode($base64, TRUE);

      if ($binary === FALSE || !str_starts_with($binary, "%PDF")) {
        throw new \DomainException("Nội dung PDF không hợp lệ");
      }

      $file = $this->saveFileByField(
        $bundle,
        "field_invoice_pdf",
        $filename,
        $binary,
        FileExists::Replace
      );

      return (int) $file->id();
    }
    catch (\Throwable $e) {
      $this->logger->error("Cannot save invoice PDF: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);

      return NULL;
    }
  }

  /**
   * Bóc gói ZIP của nhà cung cấp và gắn PDF, XML vào hóa đơn.
   *
   * @param InvoiceInterface $invoice
   *   Hóa đơn cần gắn file.
   * @param string $binary
   *   Nội dung nhị phân của gói ZIP.
   *
   * @return bool
   *   TRUE khi gắn được ít nhất file PDF.
   */
  private function attachZip(InvoiceInterface $invoice, string $binary): bool {
    $fields = [
      "pdf" => "field_invoice_pdf",
      "xml" => "field_invoice_xml",
    ];

    $directory = "temporary://invoices";

    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new \DomainException("Cannot prepare temporary directory");
    }

    $path = $this->fileSystem->realpath($directory) . "/invoice-" . $invoice->id() . ".zip";
    file_put_contents($path, $binary);

    $zip = new \ZipArchive();
    $saved = [];

    try {
      if ($zip->open($path) !== TRUE) {
        throw new \DomainException("Cannot open zip");
      }

      for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_contains($name, "..")) {
          continue;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!isset($fields[$extension]) || isset($saved[$extension])) {
          continue;
        }

        $content = $zip->getFromIndex($index);

        if ($content === FALSE) {
          continue;
        }

        $saved[$extension] = $this->saveFileByField(
          $invoice->bundle(),
          $fields[$extension],
          basename($name),
          $content,
          FileExists::Rename
        );
      }

      $zip->close();
    }
    finally {
      $this->fileSystem->unlink($path);
    }

    $values = [];

    foreach ($saved as $extension => $file) {
      $values[$fields[$extension]] = [
        "target_id" => $file->id(),
        "display" => 1,
      ];
    }

    $this->updateInvoice($invoice, $values);

    return TRUE;
  }

  /**
   * Lưu file vào đúng thư mục cấu hình của field.
   *
   * @param string $bundle
   *   Bundle của entity invoice.
   * @param string $field_name
   *   Tên field file.
   * @param string $filename
   *   Tên file.
   * @param string $content
   *   Nội dung file.
   * @param FileExists $file_exists
   *   Cách xử lý khi file đã tồn tại.
   *
   * @return \Drupal\file\FileInterface
   *   File đã lưu.
   */
  private function saveFileByField(
    string $bundle,
    string $field_name,
    string $filename,
    string $content,
    FileExists $file_exists,
  ) {
    $definitions = $this->entityFieldManager->getFieldDefinitions("invoice", $bundle);

    if (!isset($definitions[$field_name])) {
      throw new \DomainException("Field {$field_name} does not exist on {$bundle}");
    }

    $definition = $definitions[$field_name];
    $scheme = $definition->getFieldStorageDefinition()->getSetting("uri_scheme");

    if (!$scheme) {
      throw new \DomainException("Missing uri_scheme");
    }

    $directory = $this->token->replace(
      $definition->getSetting("file_directory") ?? "",
      ["date" => $this->time->getRequestTime()]
    );

    $destination = $scheme . "://" . trim($directory, "/");

    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new \DomainException("Cannot prepare directory");
    }

    $file = $this->fileRepository->writeData(
      $content,
      $destination . "/" . $filename,
      $file_exists
    );

    if (!$file) {
      throw new \DomainException("Cannot save file");
    }

    $file->setPermanent();
    $file->save();

    return $file;
  }

  /**
   * Đổi chuỗi thời gian sang định dạng UTC mà field datetime chấp nhận.
   *
   * @param string $value
   *   Chuỗi thời gian.
   *
   * @return string|null
   *   Chuỗi "Y-m-d\TH:i:s" theo UTC, NULL khi không đọc được.
   */
  private function toUtc(string $value): ?string {
    if (trim($value) === "") {
      return NULL;
    }

    try {
      $date = new \DateTime($value);
      $date->setTimezone(new \DateTimeZone("UTC"));

      return $date->format("Y-m-d\TH:i:s");
    }
    catch (\Throwable) {
      // \Throwable chứ không phải \Exception, xem ghi chú ở
      // MisaProvider::formatDate().
      return NULL;
    }
  }

}
