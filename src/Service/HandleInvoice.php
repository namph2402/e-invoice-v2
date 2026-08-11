<?php

namespace Drupal\e_invoice\Service;

use Psr\Log\LoggerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\FileRepositoryInterface;
use Drupal\e_invoice\InvoiceProvidersPluginManager;
use Drupal\e_invoice\InvoiceInterface;

/**
 * Invoice get config invoice.
 */
class HandleInvoice {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected InvoiceProvidersPluginManager $providers,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected LoggerInterface $logger,
    protected Token $token,
  ) {}

  /**
   * Lấy token.
   */
  public function getToken(array $config): array {
    try {
      $provider = $this->getProvider($config);
      $data = $provider->authenticate($config);

      return [
        "success" => TRUE,
        "data" => $data,
      ];
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Phát hành hóa đơn.
   */
  public function issueInvoice(array $entity, array $config, array $data, array $params = []): array {
    try {
      $isError = FALSE;
      $provider = $this->getProvider($config);
      $results = $provider->issue($config, $data);

      foreach ($results as $result) {

        if (!empty($result["ErrorCode"])) {
          $isError = TRUE;
          continue;
        }

        $date = new \DateTime('now', new \DateTimeZone('UTC'));

        $fields = [
          "field_invoice_issue" => 1,
          "field_invoice_status" => 1,
          "field_invoice_no" => $result["InvNo"],
          "field_invoice_refno" => $result["RefID"],
          "field_invoice_pattern" => $result["InvTemplateNo"],
          "field_invoice_serial" => $result["InvSeries"],
          "field_invoice_transaction" => $result["TransactionID"],
          "field_invoice_mccqt" => $result["InvCode"],
          "field_invoice_date" => $date->format('Y-m-d\TH:i:s'),
        ];

        $fields += $params;
        $this->updateOutInvoice($entity[$result["RefID"]], $fields);
      }

      if ($isError) {
        return [
          "success" => FALSE,
          "message" => "Invoice issuance failed",
        ];
      }

      return [
        "success" => TRUE,
      ];
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Thay thế hóa đơn.
   */
  public function replaceInvoice(array $entity, array $config, array $data, array $params = []): array {
    try {
      $isError = FALSE;
      $list_old_invoice = [];
      $provider = $this->getProvider($config);

      foreach ($data as &$item) {
        $invoice = $entity[$item["invoice_uuid"]];

        /** @var InvoiceInterface $old_invoice */
        $old_invoice = $invoice->get("field_invoice_id_related")->entity;

        if (!$old_invoice || $old_invoice->get("field_invoice_status")->value == 5) {
          return [
            "success" => FALSE,
            "message" => "The replaced invoice is invalid.",
          ];
        }

        $item["replace"] = [
          "invoice_no" => $old_invoice->get("field_invoice_no")->value ?? "",
          "invoice_template_no" => $old_invoice->get("field_invoice_pattern")->value ?? "1",
          "invoice_template_series" => $old_invoice->get("field_invoice_serial")->value
            ? substr($old_invoice->get("field_invoice_serial")->value, 1)
            : "",
        ];

        $list_old_invoice[$item["invoice_uuid"]] = $old_invoice;
      }

      $results = $provider->replace($config, $data);

      foreach ($results as $result) {

        if (!empty($result["ErrorCode"])) {
          $isError = TRUE;
          continue;
        }

        $fields = [
          "field_invoice_issue" => 1,
          "field_invoice_no" => $result["InvNo"],
          "field_invoice_refno" => $result["RefID"],
          "field_invoice_pattern" => $result["InvTemplateNo"],
          "field_invoice_serial" => $result["InvSeries"],
          "field_invoice_transaction" => $result["TransactionID"],
          "field_invoice_mccqt" => $result["InvCode"],
        ];

        $fields += $params;

        $this->updateOutInvoice($entity[$result["RefID"]], $fields);
        $this->updateOutInvoice($list_old_invoice[$result["RefID"]], ["field_invoice_status" => 4]);
      }

      if ($isError) {
        return [
          "success" => FALSE,
          "message" => "Replace issuance failed",
        ];
      }

      return [
        "success" => TRUE,
      ];
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Trạng thái hóa đơn.
   */
  public function statusInvoice(array $data, array $params = []): array {
    try {
      $isError = FALSE;
      $tranID = $list_invoice = [];
      $config = $data["config"];

      $provider = $this->getProvider($config);

      foreach ($data["invoices"] as $invoice) {
        if ($tran = $invoice->get("field_invoice_transaction")->value) {
          $tranID[] = $tran;
          $list_invoice[$tran] = $invoice;
        }
      }

      if (empty($tranID)) {
        return [
          "success" => FALSE,
          "message" => "Not found transaction ID.",
        ];
      }

      $results = $provider->status($config, $tranID, $params);

      foreach ($results as $result) {
        if (empty($result["TransactionID"])) {
          $isError = TRUE;
          continue;
        }

        $date = new \DateTime($result["PublishedTime"]);
        $date->setTimezone(new \DateTimeZone('UTC'));

        $fields = [
          "field_invoice_mccqt" => $result["InvoiceCode"],
          "field_invoice_status_cqt" => $result["SendTaxStatus"],
          "field_invoice_date" => $date->format('Y-m-d\TH:i:s'),
        ];

        $this->updateOutInvoice($list_invoice[$result["TransactionID"]], $fields);
      }

      if ($isError) {
        return [
          "success" => FALSE,
          "message" => "Invoice status failed",
        ];
      }

      return [
        "success" => TRUE,
      ];
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Tải file PDF hóa đơn đầu ra.
   */
  public function pdfOutputInvoice(array $data): array {
    try {
      $isError = FALSE;
      $tranID = $list_invoice = [];
      $config = $data["config"];

      foreach ($data["invoices"] as $invoice) {
        if ($tran = $invoice->get("field_invoice_transaction")->value) {
          $tranID[] = $tran;
          $list_invoice[$tran] = $invoice;
        }
      }

      $provider = $this->getProvider($config);
      $results = $provider->pdf($config, $tranID);

      foreach ($results as $result) {
        if (empty($result["TransactionID"]) || empty($result["Data"])) {
          $isError = TRUE;
          continue;
        }

        $pdf = $this->savePdf($result);
        if ($pdf["success"]) {
          $fields["field_invoice_pdf"] = [
            "target_id" => $pdf["data"]['fid'],
            "display" => 1,
          ];

          $this->updateOutInvoice($list_invoice[$result["TransactionID"]], $fields);
        }
      }

      if ($isError) {
        return [
          "success" => FALSE,
          "message" => "Invoice pdf failed",
        ];
      }

      return [
        "success" => TRUE,
      ];
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Kéo hóa đơn đầu vào.
   */
  public function modifiedInvoice(array $config, array $params, array $fields = []): array {
    try {
      $data = [];
      $storage = $this->entityTypeManager->getStorage("invoice");
      $provider = $this->getProvider($config);
      $result = $provider->modified($config, $params);
      $field_invoice_ids = $this->checkInvoiceIssues($params);

      foreach ($result as $item) {
        if (in_array($item["field_invoice_id"], $field_invoice_ids, TRUE)) {
          continue;
        }

        $invoice = $this->createInInvoice($storage, $item, $fields);
        $data[] = $invoice;
      }

      return [
        "success" => TRUE,
        "data" => $data,
      ];
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Hạch toán hóa đơn.
   */
  public function accountingInvoice(array $data, array $params): array {
    try {
      $invoiceID = [];
      $config = $data["config"];

      $provider = $this->getProvider($config);

      foreach ($data["invoices"] as $invoice) {
        if ($invoice_id = $invoice->get("field_invoice_id")->value) {
          $invoiceID[] = $invoice_id;
          $list_invoice[$invoice_id] = $invoice;
        }
      }

      $params["invoice_id"] = $invoiceID;

      $result = $provider->accounting($config, $params);

      $this->updateInInvoice($invoice, $params);

      return [
        "success" => TRUE,
      ];
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Tải file hóa đơn đầu vào.
   */
  public function fileInputInvoice(array $data): array {
    try {
      $config = $data['config'];
      $provider = $this->getProvider($config);
      
      $fields = [
        "pdf" => "field_invoice_pdf",
        "xml" => "field_invoice_xml",
      ];

      $invoice = reset($data["invoices"]);

      if ($invoice_id = $invoice->get("field_invoice_id")->value) {
        $invoiceID[] = $invoice_id;
      }

      $binary = $provider->dowload($config, $invoiceID);

      if (empty($binary)) {
        return [
          "success" => FALSE,
          "message" => "Unable to download PDF file.",
        ];
      }

      $file = $this->savePdfZip($binary, $fields);

      if (empty($file["pdf"])) {
        return [
          "success" => FALSE,
          "message" => "Unable to save PDF file.",
        ];
      }

      $invoice->set($fields["pdf"], [
        "target_id" => $file["pdf"]->id(),
        "display" => 1,
      ]);

      if (!empty($file["xml"])) {
        $invoice->set($fields["xml"], [
          "target_id" => $file["xml"]->id(),
          "display" => 1,
        ]);
      }

      $invoice->save();

      return [
        "success" => TRUE,
      ];
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Xem trước PDF phát hành.
   */
  public function previewInvoice(array $config, array $data): mixed {
    try {
      $provider = $this->getProvider($config);
      $result = $provider->preview($config, $data);

      if (!empty($result["errorCode"])) {
        return [
          "success" => FALSE,
          "message" => $result["descriptionErrorCode"],
        ];
      }

      return $result;
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Lấy danh sách provider.
   */
  private function getProvider(array $config): object {
    $provider_id = $config["invoice_provider"];

    if (empty($provider_id)) {
      throw new \DomainException("Invoice provider not yet configured");
    }

    if (!$this->providers->hasDefinition($provider_id)) {
      throw new \DomainException("The invoice provider {$provider_id} does not exist");
    }

    return $this->providers->createInstance($provider_id);
  }

  /**
   * Cập nhật hóa đơn đầu ra.
   */
  private function updateOutInvoice(InvoiceInterface $entity, array $fields) {
    if (empty($entity)) {
      throw new \DomainException("The entity invoice does not exist");
    }

    foreach ($fields as $key => $value) {
      $entity->set($key, $value);
    }
    $entity->save();
    return $entity;
  }

  /**
   * Lưu hóa đơn đầu vào.
   */
  private function createInInvoice(EntityStorageInterface $storage, array $data, array $fields) {
    $data["label"] = $data["field_invoice_name"] ?? "Hóa đơn đầu vào";
    $data["bundle"] = "input_invoices";
    $data = array_replace($data, $fields);
    $invoice = $storage->create($data);
    $invoice->save();
    return $invoice;
  }

  /**
   * Cập nhật hóa đơn đầu vào.
   */
  private function updateInInvoice(InvoiceInterface $invoice, array $data) {
    $invoice->set("field_invoice_accountant", $data["accountant"]);
    $invoice->set("field_invoice_accounting_date", $data["accountant_date"]);
    $invoice->set("field_invoice_refno", $data["ref_no"]);
    $invoice->save();
  }

  /**
   * Lưu file PDF (base64).
   */
  private function savePdf(array $data) {
    try {
      $pdf_binary = base64_decode($data["Data"], TRUE);

      if ($pdf_binary === FALSE) {
        throw new \DomainException("Invalid base64");
      }

      if (strpos($pdf_binary, "%PDF") !== 0) {
        throw new \DomainException("Invalid PDF content");
      }

      $file = $this->saveFileByField(
        "invoice",
        "input_invoices",
        "field_invoice_pdf",
        $data["TransactionID"] . ".pdf",
        $pdf_binary,
        FileSystemInterface::EXISTS_REPLACE
      );

      return [
        "success" => TRUE,
        "data" => [
          "fid" => $file->id(),
          "filename" => $file->getFilename(),
          "uri" => $file->getFileUri(),
        ],
      ];
    }
    catch (\Throwable $e) {
      \Drupal::logger("erpcons")->error($e->getMessage());

      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
  }

  /**
   * Lưu file PDF (zip).
   */
  private function savePdfZip(string $data, array $fields) {
    $result = [
      "pdf" => NULL,
      "xml" => NULL,
    ];

    $tmpDir = "temporary://invoices";
    $zipPath = "{$tmpDir}/invoices.zip";

    $this->fileSystem->prepareDirectory(
      $tmpDir,
      FileSystemInterface::CREATE_DIRECTORY
    );

    file_put_contents($this->fileSystem->realpath($zipPath), $data);

    $zip = new \ZipArchive();

    if ($zip->open($this->fileSystem->realpath($zipPath)) !== TRUE) {
      throw new \DomainException("Cannot open zip");
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = $zip->getNameIndex($i);

      if (str_contains($name, "..")) {
        continue;
      }

      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

      if (!isset($fields[$ext])) {
        continue;
      }

      $content = $zip->getFromIndex($i);

      if ($content === FALSE) {
        continue;
      }

      $file = $this->saveFileByField(
        "invoice",
        "input_invoices",
        $fields[$ext],
        basename($name),
        $content,
        FileSystemInterface::EXISTS_RENAME
      );

      $result[$ext] = $file;
    }

    $zip->close();

    return $result;
  }

  /**
   * Lấy cấu hình trường PDF.
   */
  private function saveFileByField(
    string $entityType,
    string $bundle,
    string $fieldName,
    string $filename,
    string $content,
    int $replaceMode = FileSystemInterface::EXISTS_RENAME,
  ) {
    /** @var \Drupal\field\Entity\FieldConfig $fieldConfig */
    $fieldConfig = $this->entityFieldManager
      ->getFieldDefinitions($entityType, $bundle)[$fieldName];

    $settings = $fieldConfig->getSettings();

    $scheme = $fieldConfig
      ->getFieldStorageDefinition()
      ->getSetting("uri_scheme");

    if (!$scheme) {
      throw new \DomainException("Missing uri_scheme");
    }

    $directory = $this->token->replace(
      $settings["file_directory"] ?? "",
      ["date" => \Drupal::time()->getRequestTime()]
    );

    $destination = $scheme . "://" . trim($directory, "/");

    if (
      !$this->fileSystem->prepareDirectory(
        $destination,
        FileSystemInterface::CREATE_DIRECTORY
      )
    ) {
      throw new \DomainException("Cannot prepare directory");
    }

    $uri = $destination . "/" . $filename;

    $file = $this->fileRepository->writeData(
      $content,
      $uri,
      $replaceMode
    );

    if (!$file) {
      throw new \DomainException("Cannot save file");
    }

    $file->setPermanent();
    $file->save();

    return $file;
  }

  /**
   * Lấy các hóa đơn đã phát hành trước đó.
   */
  private function checkInvoiceIssues(array $params) {
    $field_invoice_querry = \Drupal::database()
      ->select("invoice__field_invoice_id", "f");

    $field_invoice_querry->join('invoice__field_invoice_date', 'd', 'f.entity_id = d.entity_id');

    $field_invoice_querry->fields("f", ["field_invoice_id_value"])
      ->condition("f.bundle", "input_invoices");

    if (!empty($params["from"])) {
      $field_invoice_querry->condition(
        'd.field_invoice_date_value',
        $params['from'],
        '>='
      );
    }

    if (!empty($params["to"])) {
      $field_invoice_querry->condition(
        'd.field_invoice_date_value',
        $params['to'],
        '<='
      );
    }

    return $field_invoice_querry->execute()->fetchCol();
  }

}
