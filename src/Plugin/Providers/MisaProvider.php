<?php

namespace Drupal\e_invoice\Plugin\Providers;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\e_invoice\Service\GetNumberToWords;
use Drupal\e_invoice\InvoiceProvidersAttribute;
use Drupal\e_invoice\InvoiceProvidersInterface;

/**
 * Provider for invoicing integration using Misa.
 */
#[InvoiceProvidersAttribute(
  id: "misa",
  label: new TranslatableMarkup("Misa"),
)]

class MisaProvider extends PluginBase implements InvoiceProvidersInterface, ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected UuidInterface $uuid,
    protected GetNumberToWords $getNumberToWords,
    protected ClientInterface $client,
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
      $container->get("e_invoice.get_number_to_words"),
      $container->get("http_client"),
    );
  }

  /**
   * Call cUrl.
   */
  private function callApi(
    string $url,
    array $headers,
    array $payload = [],
    string $method = "POST",
    int $timeout = 30,
  ): mixed {
    try {
      $response = $this->client->request($method, $url, [
        "headers" => $headers,
        "json" => $payload,
        "timeout" => $timeout,
      ]);

      $body = $response->getBody()->getContents();
      $contentType = $response->getHeaderLine('Content-Type');

      if (str_contains($contentType, 'application/zip')) {
        return $body;
      }

      return json_decode($body, TRUE);
    }
    catch (RequestException $e) {
      throw new \RuntimeException("Connection error: " . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Get authenticate.
   */
  public function authenticate(array $config): array {
    $token = $this->token($config);

    if (!$token["success"]) {
      throw new \DomainException("Unable to receive the token");
    }

    $secure = $this->secureToken($config);

    if (!$secure["Success"]) {
      throw new \DomainException("Unable to receive the Securet token");
    }

    $jwt = $this->jwtToken($config, $secure["Data"]);

    if (!$jwt["Success"]) {
      throw new \DomainException("Unable to receive the JWT token");
    }

    $result = [
      "token" => $token["data"] ?? "",
      "jwt_token" => $jwt["Data"]["AccessToken"] ?? "",
    ];

    if (!empty($config["invoice_appurl"])) {
      $subscribers = $this->subscribers($config);

      if (!$subscribers["Success"]) {
        throw new \DomainException("Cannot get subscriber");
      }

      $organization = $this->organization(
        $config,
        $jwt["Data"],
        $subscribers["Data"]["Id"]
      );

      if (!$organization["Success"]) {
        throw new \DomainException("Cannot get organization");
      }

      $result += [
        "subscribers" => $subscribers["Data"]["Id"],
        "organization" => reset($organization["Data"]),
      ];
    }

    return $result;
  }

  /**
   * Save file pdf.
   */
  public function pdf(array $config, string $code, string $type = "download"): array {
    $end_point = "/api/integration/invoice/Download";

    if (empty($code)) {
      if ($type === "download") {
        throw new \DomainException("Not found transaction ID");
      }
      else {
        return [];
      }
    }

    $query = http_build_query([
      "invoiceWithCode" => "true",
      "invoiceCalcu" => "true",
      "downloadDataType" => "pdf",
    ]);

    if ($type === "save") {
      sleep(5);
    }

    $response = $this->callApi(
      $config["invoice_host"] . $end_point . "?" . $query,
      [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer {$config["invoice_token"]}",
      ],
      [$code],
      "POST",
      200
    );

    if (!$response["success"]) {
      if ($type === "download") {
        throw new \DomainException($response["errorCode"]);
      }
      else {
        return [];
      }
    }

    $arr_data = json_decode($response["data"], TRUE);
    return reset($arr_data);
  }

  /**
   * Issue invoice.
   */
  public function issue(array $config, array $data, bool $get_file): array {
    $end_point = "/api/integration/invoice";
    $dataInv = $this->getData($config["invoice_template"], $data);

    $response = $this->callApi(
      $config["invoice_host"] . $end_point,
      [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer {$config["invoice_token"]}",
      ],
      [
        "SignType" => 2,
        "InvoiceData" => $dataInv,
        "PublishInvoiceData" => NULL,
      ]
    );

    if (!$response["success"]) {
      throw new \DomainException($response["errorCode"]);
    }

    $data_json = json_decode($response["publishInvoiceResult"], TRUE);

    if ($get_file) {
      $invoice = reset($data_json);

      if (!empty($invoice["ErrorCode"])) {
        throw new \DomainException($invoice["DescriptionErrorCode"]);
      }
      $file_data = $this->pdf($config, $invoice["TransactionID"], "save");
      if (!empty($file_data)) {
        $invoice["base64"] = $file_data["Data"];
      }

      return $invoice;
    }

    return $data_json;
  }

  /**
   * Replace invoice.
   */
  public function replace(array $config, array $data): array {
    $end_point = "/api/integration/invoice";
    $dataInv = $this->getData($config["invoice_template"], $data, "replace");

    $response = $this->callApi(
      $config["invoice_host"] . $end_point,
      [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer {$config["invoice_token"]}",
      ],
      [
        "SignType" => 2,
        "InvoiceData" => $dataInv,
        "PublishInvoiceData" => NULL,
      ]
    );

    if (!$response["success"]) {
      throw new \DomainException($response["errorCode"]);
    }

    $data_json = json_decode($response["publishInvoiceResult"], TRUE);
    $invoice = reset($data_json);

    if (!empty($invoice["ErrorCode"])) {
      throw new \DomainException($invoice["DescriptionErrorCode"]);
    }

    $file_data = $this->pdf($config, $invoice["TransactionID"], "save");
    if (!empty($file_data)) {
      $invoice["base64"] = $file_data["Data"];
    }

    return $invoice;
  }

  /**
   * Input invoice.
   */
  public function modified(array $config, array $params): array {
    $data = [];
    $subscribers = $config["invoice_subscribers"];
    $organization = $config["invoice_organization"];

    $end_point = "/inbot/api/{$subscribers}/{$organization}/invoices/v2/modified";

    $query = http_build_query([
      "take" => "100",
      "from" => $params["from"] ?? "",
      "to" => $params["to"] ?? "",
      "skip" => $params["skip"] ?? "0",
      "IsFilterInvDate" => "true",
    ]);

    $response = $this->callApi(
      $config["invoice_appurl"] . $end_point . "?" . $query,
      [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer {$config["invoice_jwt_token"]}",
        "ClientId" => "{$config["invoice_client"]}",
      ],
      [],
      "GET"
    );

    if (!$response["Success"]) {
      throw new \DomainException("Error retrieving input invoices");
    }

    foreach ($response["Data"]["Data"] as $item) {
      $product = [];
      $invoice_date = new \DateTime($item["InvoiceDate"]);
      $accounting_date = isset($item['AccountingDate'])
        ? new \DateTime($item['AccountingDate'])
        : NULL;

      foreach ($item["Items"] as $p) {
        $product[] = [
          "item_name" => $p["ItemName"] ?? "",
          "item_code" => $p["ItemCode"] ?? "",
          "item_unit" => $p["UnitName"] ?? "",
          "item_quantity" => $p["Quantity"] ?? 0,
          "item_price" => $p["UnitPrice"] ?? 0,
          "item_amount" => $p["Amount"] ?? 0,
          "item_discount_rate" => $p["DiscountRate"] ?? 0,
          "item_discount_amount" => $p["DiscountAmount"] ?? 0,
          "item_amount_without_vat" => $p["AmountWithoutVat"] ?? 0,
          "item_vat_rate" => $p["VatRate"] ?? 0,
          "item_vat_amount" => $p["VatAmount"] ?? 0,
          "item_total_amount" => $p["Amount"] ?? 0,
          "item_type" => $p["Nature"] ?? 0
        ];
      }

      $data[] = [
        "field_invoice_name" => $item["InvoiceName"] ?? $item["TitleInvoiceText"],
        "field_invoice_id" => $item["InvoiceId"] ?? "",
        "field_invoice_no" => $item["InvoiceNo"] ?? "",
        "field_invoice_status" => $item["StatusInvoice"] ?? 1,
        "field_invoice_seller_name" => $item["SellerName"] ?? "",
        "field_invoice_seller_taxcode" => $item["SellerTaxCode"] ?? "",
        "field_invoice_seller_address" => $item["SellerAddress"] ?? "",
        "field_invoice_buyer_name" => $item["BuyerName"] ?? "",
        "field_invoice_buyer_taxcode" => $item["BuyerTaxCode"] ?? "",
        "field_invoice_buyer_address" => $item["BuyerAddress"] ?? "",
        "field_invoice_mccqt" => $item["MCCQT"] ?? "",
        "field_invoice_date" => $invoice_date->format("Y-m-d"),
        "field_invoice_amount" => $item["Amount"] ?? 0,
        "field_invoice_discount_amount" => $item["TotalDiscountAmount"] ?? 0,
        "field_invoice_amount_without_vat" => $item["TotalAmountWithoutVat"] ?? 0,
        "field_invoice_vat_amount" => $item["TotalVATAmount"] ?? 0,
        "field_invoice_vat_rate" => $item["VatRate"] ?? 0,
        "field_invoice_payment" => match ($item['PaymentMethod'] ?? '') {
          'TM' => 'inv_tm',
          'CK' => 'inv_ck',
          default => 'inv_tm_ck',
        },
        "field_invoice_total_amount" => $item["TotalAmount"] ?? 0,
        "field_invoice_accountant" => $item["Accountant"] ?? "",
        "field_invoice_refno" => $item["RefNo"] ?? "",
        "field_invoice_relateds" => $item["InvoiceRelateds"]["InvoiceId"] ?? "",
        "field_invoice_items" => $product,
        "field_invoice_accounting_date" => $accounting_date ? $accounting_date->format("Y-m-d") : NULL,
      ];
    }

    return $data;
  }

  /**
   * Accounting an invoice.
   */
  public function accounting(array $config, array $data): array {
    $subscribers = $config["invoice_subscribers"];
    $organization = $config["invoice_organization"];

    $end_point = "/inbot/api/{$subscribers}/{$organization}/invoices/invoiceaccountingdateV2";

    $response = $this->callApi(
      $config["invoice_appurl"] . $end_point,
      [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer {$config["invoice_jwt_token"]}",
        "ClientId" => "{$config["invoice_client"]}",
      ],
      [
        "Accountant" => $data["accountant"],
        "AccountingDate" => $data["accountant_date"],
        "InvoiceId" => $data["invoice_id"],
        "RefNo" => $data["ref_no"],
      ],
    );

    if (!$response["Success"]) {
      throw new \DomainException($response["Message"]);
    }

    return $response;

  }

  /**
   * Download PDF.
   */
  public function download(array $config, array $data): mixed {
    $subscribers = $config["invoice_subscribers"];
    $organization = $config["invoice_organization"];
    $jwt_token = $config["invoice_jwt_token"];
    $client = $config["invoice_client"];

    $key = $this->keyDownload($config, $data);
    if (!$key["Success"]) {
      throw new \DomainException("Download key error");
    }

    $end_point = "/inbot/api/{$subscribers}/{$organization}/download/{$key["Data"]["Key"]}/download";

    sleep(10);

    return $this->callApi(
      $config["invoice_appurl"] . $end_point,
      [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer {$jwt_token}",
        "ClientId" => "{$client}",
      ],
      [],
      "GET"
    );
  }

  /**
   * Preview pdf.
   */
  public function preview(array $config, array $data): array {
    $end_point = "/api/integration/invoice/unpublishview";
    $dataInv = $this->getData($config["invoice_template"], $data);

    $response = $this->callApi(
      $config["invoice_host"] . $end_point,
      [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer {$config["invoice_token"]}",
      ],
      reset($dataInv),
      "POST",
      200
    );

    if (!$response["success"]) {
      throw new \DomainException($response["errorCode"]);
    }

    return $response;
  }

  /**
   * Lấy trạng thái hóa đơn đã phát hành.
   */
  public function status(array $config, string $code, array $params) {
    $endPoint = "/api/integration/invoice/status";

    $query = http_build_query([
      "inputType" => "1",
      "invoiceCalcu" => $params["calcu"] ?? "false",
      "invoiceWithCode" => $params["withCode"] ?? "true",
    ]);

    return $this->callApi(
      $config["invoice_host"] . $endPoint . "?" . $query,
      [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer {$config["invoice_token"]}",
      ],
      [
        $code,
      ],
    );
  }

  /**
   * {@inheritDoc}
   */
  private function getData(array $template, array $data, string $type = "create") {
    $data_invoice = [];

    foreach ($data as $key => $item) {
      $invoice_total_amount = $item["invoice_total_amount"] ?? 0;
      $invoice_total_amount_words = $this->getNumberToWords->handle($invoice_total_amount);
      $buildData = $this->buildProducts($item["products"]);

      $data_invoice[$key] = [
        "RefID" => $item["invoice_uuid"] ?? $this->uuid->generate(),
        "InvSeries" => $template["serial"] ?? "",
        "InvoiceName" => $item["invoice_title"] ?? "",
        "IsInvoiceCalculatingMachine" => TRUE,
        "InvDate" => date("Y-m-d"),
        "CurrencyCode" => $item["invoice_currency_code"] ?? "VND",
        "ExchangeRate" => 1,
        "PaymentMethodName" => $item["invoice_payment"] ?? NULL,
        "BuyerLegalName" => $item["invoice_buyer_legal"] ?? "Khách vãn lai",
        "BuyerCode" => $item["invoice_buyer_code"] ?? NULL,
        "BuyerFullName" => $item["invoice_buyer_name"] ?? "NGƯỜI MUA KHÔNG LẤY HÓA ĐƠN",
        "BuyerTaxCode" => $item["invoice_buyer_taxcode"] ?? NULL,
        "BuyerAddress" => $item["invoice_buyer_address"] ?? NULL,
        "BuyerPhoneNumber" => $item["invoice_buyer_phone"] ?? NULL,
        "BuyerEmail" => $item["invoice_buyer_email"] ?? NULL,
        "ContactName" => $item["invoice_buyer_name"] ?? NULL,
        "TotalSaleAmountOC" => $item["invoice_amount"] ?? 0,
        "TotalSaleAmount" => $item["invoice_amount"] ?? 0,
        "TotalDiscountAmountOC" => $item["invoice_discount_amount"] ?? 0,
        "TotalDiscountAmount" => $item["invoice_discount_amount"] ?? 0,
        "TotalAmountWithoutVATOC" => $item["invoice_amount_without_vat"] ?? 0,
        "TotalAmountWithoutVAT" => $item["invoice_amount_without_vat"] ?? 0,
        "TotalVATAmountOC" => $item["invoice_vat_amount"] ?? 0,
        "TotalVATAmount" => $item["invoice_vat_amount"] ?? 0,
        "TotalAmountOC" => $invoice_total_amount,
        "TotalAmount" => $invoice_total_amount,
        "TotalAmountInWords" => $invoice_total_amount_words,
        "IsTaxReduction43" => FALSE,
        "OriginalInvoiceDetail" => $buildData["product"],
        "TaxRateInfo" => array_values($buildData["tax_info"]),
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
  
      if ($type == "replace") {
        $data_invoice[$key] += $this->buildReplace($data["replace"]);
      }
    }

    return $data_invoice;
  }

  /**
   * {@inheritDoc}
   */
  private function buildProducts(array $products): array {
    $line = 1;
    $result = [];
    $taxRateInfo = [];

    foreach ($products as $p) {
      $type = (int) ($p["type"] ?? 1);
      $sort = in_array($type, [1, 2], TRUE) ? $line : NULL;

      $vatRate = (float) ($p['vat_rate'] ?? 0);
      $vatRateName = in_array($vatRate, [0.0, 5.0, 8.0, 10.0], TRUE)
        ? $vatRate . '%'
        : 'KHAC:' . $vatRate . '%';

      if (!isset($taxRateInfo[$vatRateName])) {
        $taxRateInfo[$vatRateName] = [
          'VATRateName' => $vatRateName,
          'AmountWithoutVATOC' => 0,
          'VATAmountOC' => 0,
        ];
      }

      $taxRateInfo[$vatRateName]['AmountWithoutVATOC']
        += (float) ($p['amount_without_vat'] ?? 0);

      $taxRateInfo[$vatRateName]['VATAmountOC']
        += (float) ($p['vat_amount'] ?? 0);

      $result[] = [
        "ItemType" => $type,
        "LineNumber" => $line,
        "SortOrder" => $sort,
        "ItemCode" => $p["code"] ?? NULL,
        "ItemName" => $p["name"] ?? NULL,
        "UnitName" => $p["unit"] ?? NULL,
        "Quantity" => (float) ($p["quantity"] ?? 0),
        "UnitPrice" => (float) ($p["price"] ?? 0),
        "DiscountRate" => (int) ($p["discount"] ?? 0),
        "DiscountAmountOC" => (float) ($p["discount_amount"] ?? 0),
        "DiscountAmount" => (float) ($p["discount_amount"] ?? 0),
        "AmountOC" => (float) ($p["amount"] ?? 0),
        "Amount" => (float) ($p["amount"] ?? 0),
        "AmountWithoutVATOC" => (float) ($p["amount_without_vat"] ?? 0),
        "VATRateName" => $vatRateName,
        "VATAmountOC" => (float) ($p["vat_amount"] ?? 0),
        "VATAmount" => (float) ($p["vat_amount"] ?? 0),
      ];

      $line++;
    }

    return [
      "product" => $result,
      "tax_info" => $taxRateInfo,
    ];
  }

  /**
   * {@inheritDoc}
   */
  private function buildReplace(array $replace): array {
    return [
      "ReferenceType" => 1,
      "OrgInvoiceType" => 1,
      "OrgInvDate" => date("Y-m-d"),
      "OrgInvNo" => $replace["invoice_no"] ?? NULL,
      "OrgInvTemplateNo" => $replace["invoice_template_no"] ?? NULL,
      "OrgInvSeries" => $replace["invoice_template_series"] ?? NULL,
    ];
  }

  /**
   * {@inheritDoc}
   */
  private function token(array $config): array {
    return $this->callApi(
      $config["invoice_host"] . "/api/integration/auth/token",
      [
        "Content-Type" => "application/json",
      ],
      [
        "appid" => $config["invoice_appid"],
        "taxcode" => $config["invoice_taxcode"],
        "username" => $config["invoice_username"],
        "password" => $config["invoice_password"],
      ]
    );
  }

  /**
   * {@inheritDoc}
   */
  private function secureToken(array $config): array {
    return $this->callApi(
      $config["invoice_host"] . "/api2/validateuser",
      [
        "Content-Type" => "application/json",
        "appid" => $config["invoice_appid"],
        "companytaxcode" => $config["invoice_taxcode"],
        "username" => $config["invoice_username"],
      ],
      [
        "password" => $config["invoice_password"],
      ]
    );
  }

  /**
   * {@inheritDoc}
   */
  private function jwtToken(array $config, string $token): array {
    return $this->callApi(
      $config["invoice_host"] . "/api2/auth/jwttoken",
      [
        "Content-Type" => "application/json",
        "appid" => $config["invoice_appid"],
        "companytaxcode" => $config["invoice_taxcode"],
        "username" => $config["invoice_username"],
        "securetoken" => substr(
          $token,
          strpos($token, ";") + 1
        ),
      ],
      []
    );
  }

  /**
   * {@inheritDoc}
   */
  private function subscribers(array $config): array {
    return $this->callApi(
      $config["invoice_appurl"] . "/inbot/api/subscribers/code/" . $config["invoice_taxcode"],
      [
        "Content-Type" => "application/json",
        "ClientId" => $config["invoice_client"],
      ],
      [],
      "GET",
    );
  }

  /**
   * {@inheritDoc}
   */
  private function organization(array $config, array $jwt, string $subscribers): array {
    return $this->callApi(
      $config["invoice_appurl"] . "/inbot/api/{$subscribers}/organizations",
      [
        "Content-Type" => "application/json",
        "ClientId" => $config["invoice_client"],
        "Authorization" => "Bearer {$jwt["AccessToken"]}",
      ],
      [],
      "GET"
    );
  }

  /**
   * {@inheritDoc}
   */
  private function keyDownload(array $config, array $id): array {
    $subscribers = $config["invoice_subscribers"];
    $organization = $config["invoice_organization"];

    $end_point = "/inbot/api/{$subscribers}/{$organization}/download/zip";

    return $this->callApi(
      $config["invoice_appurl"] . $end_point,
      [
        "Content-Type" => "application/json",
        "ClientId" => "{$config["invoice_client"]}",
        "Authorization" => "Bearer {$config["invoice_jwt_token"]}",
      ],
      [
        "FileType" => 1,
        "LstInvID" => $id,
      ]
    );
  }

}
