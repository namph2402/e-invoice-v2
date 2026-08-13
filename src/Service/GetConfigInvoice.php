<?php

namespace Drupal\e_invoice\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\e_invoice\InvoiceProvidersPluginManager;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Lấy cấu hình hóa đơn trong taxonomy và giữ cho bộ token còn hiệu lực.
 */
class GetConfigInvoice {

  use StringTranslationTrait;

  /**
   * Thời hạn sử dụng của token sau khi lấy mới.
   */
  protected const TOKEN_LIFETIME = "+10 days";

  /**
   * Các field được ghi lại sau khi lấy token, map theo key của cấu hình.
   */
  protected const TOKEN_FIELDS = [
    "field_inv_token" => "invoice_token",
    "field_inv_jwt_token" => "invoice_jwt_token",
    "field_inv_subscribers" => "invoice_subscribers",
    "field_inv_organization" => "invoice_organization",
    "field_inv_expiration" => "invoice_expiration",
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected InvoiceProvidersPluginManager $providers,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {}

  /**
   * Lấy cấu hình, tự động làm mới token khi hết hạn hoặc chưa có.
   *
   * @param TermInterface $config_entity
   *   Term cấu hình hóa đơn của công ty.
   *
   * @return array
   *   Cấu hình đã chuẩn hoá.
   */
  public function handle(TermInterface $config_entity): array {
    $config = $this->buildConfig($config_entity);

    if (!$this->isExpired($config)) {
      return $config;
    }

    $refreshed = $this->refreshToken($config_entity, $config);

    if ($refreshed === NULL) {
      return $config;
    }

    $this->saveConfigEntity($config_entity);

    return $refreshed;
  }

  /**
   * Lấy token mới cho cấu hình đang dùng dở giữa chừng.
   *
   * Dùng khi MISA báo token hết hạn sớm hơn mốc lưu trong term: tải lại term
   * theo id đã nhúng sẵn trong mảng cấu hình rồi xin bộ token khác.
   *
   * @param array $config
   *   Cấu hình đang dùng, phải có khoá "invoice_config_id".
   *
   * @return array|null
   *   Cấu hình đã cập nhật token, hoặc NULL khi không làm mới được.
   */
  public function refresh(array $config): ?array {
    $term_id = $config["invoice_config_id"] ?? NULL;

    if (empty($term_id)) {
      return NULL;
    }

    /** @var \Drupal\taxonomy\TermInterface|null $config_entity */
    $config_entity = $this->entityTypeManager
      ->getStorage("taxonomy_term")
      ->load($term_id);

    if (!$config_entity instanceof TermInterface) {
      return NULL;
    }

    $refreshed = $this->refreshToken($config_entity);

    if ($refreshed === NULL) {
      return NULL;
    }

    $this->saveConfigEntity($config_entity);

    return $refreshed;
  }

  /**
   * Đọc cấu hình hóa đơn từ taxonomy term.
   *
   * @param \Drupal\taxonomy\TermInterface $config_entity
   *   Term cấu hình hóa đơn của công ty.
   *
   * @return array
   *   Cấu hình đã chuẩn hoá.
   */
  public function buildConfig(TermInterface $config_entity): array {
    return [
      "invoice_config_id" => $config_entity->id(),
      "invoice_provider" => $this->value($config_entity, "field_inv_provider"),
      "invoice_host" => $this->uri($config_entity, "field_inv_host"),
      "invoice_appurl" => $this->uri($config_entity, "field_inv_appurl"),
      "invoice_username" => $this->value($config_entity, "field_inv_username"),
      "invoice_password" => $this->value($config_entity, "field_inv_password"),
      "invoice_taxcode" => $this->value($config_entity, "field_inv_taxcode"),
      "invoice_appid" => $this->value($config_entity, "field_inv_appid"),
      "invoice_client" => $this->value($config_entity, "field_inv_client"),
      "invoice_subscribers" => $this->value($config_entity, "field_inv_subscribers"),
      "invoice_organization" => $this->value($config_entity, "field_inv_organization"),
      "invoice_token" => $this->value($config_entity, "field_inv_token"),
      "invoice_jwt_token" => $this->value($config_entity, "field_inv_jwt_token"),
      "invoice_expiration" => $this->value($config_entity, "field_inv_expiration"),
      "invoice_templates" => $config_entity->hasField("field_inv_templates")
        ? $config_entity->get("field_inv_templates")->getValue()
        : [],
    ];
  }

  /**
   * Lấy token mới và gán vào term.
   *
   * Term không được lưu ở đây, chỗ gọi tự quyết định (hook presave chỉ cần gán,
   * còn ::handle() và ::refresh() thì phải gọi save).
   *
   * @param \Drupal\taxonomy\TermInterface $config_entity
   *   Term cấu hình sẽ được gán token mới.
   * @param array|null $config
   *   Cấu hình đã đọc sẵn, để trống thì tự đọc lại từ term.
   *
   * @return array|null
   *   Cấu hình đã cập nhật token, hoặc NULL khi lấy token thất bại.
   */
  public function refreshToken(TermInterface $config_entity, ?array $config = NULL): ?array {
    $config = $config ?? $this->buildConfig($config_entity);
    $provider_id = $config["invoice_provider"] ?? "";

    if ($provider_id === "" || !$this->providers->hasDefinition($provider_id)) {
      $this->messenger->addError(
        $this->t("The invoice provider is not configured correctly.")
      );

      return NULL;
    }

    try {
      /** @var \Drupal\e_invoice\InvoiceProvidersInterface $provider */
      $provider = $this->providers->createInstance($provider_id);
      $token = $provider->authenticate($config);
    }
    catch (\DomainException $e) {
      $this->messenger->addError($e->getMessage());
      return NULL;
    }
    catch (\Throwable $e) {
      $this->logger->error("Invoice token error: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);

      $this->messenger->addError($this->t("The system is experiencing problems"));
      return NULL;
    }

    $config["invoice_token"] = $token["token"] ?? "";
    $config["invoice_jwt_token"] = $token["jwt_token"] ?? "";
    $config["invoice_subscribers"] = $token["subscribers"] ?? "";
    $config["invoice_organization"] = $token["organization"] ?? "";
    $config["invoice_expiration"] = date(
      "Y-m-d",
      strtotime(static::TOKEN_LIFETIME, $this->time->getRequestTime())
    );

    foreach (static::TOKEN_FIELDS as $field => $key) {
      if ($config_entity->hasField($field)) {
        $config_entity->set($field, $config[$key]);
      }
    }

    $this->messenger->addStatus($this->t("Get token successfully"));

    return $config;
  }

  /**
   * Kiểm tra bộ token đã hết hạn hoặc chưa từng được lấy hay chưa.
   *
   * @param array $config
   *   Cấu hình đã đọc từ term.
   *
   * @return bool
   *   TRUE khi cần lấy token mới.
   */
  private function isExpired(array $config): bool {
    if (empty($config["invoice_token"]) && empty($config["invoice_jwt_token"])) {
      return TRUE;
    }

    $expiration = strtotime((string) ($config["invoice_expiration"] ?? ""));

    // Không có mốc hết hạn nghĩa là token cũ chưa ghi nhận thời hạn, lấy lại
    // cho chắc thay vì dùng mãi.
    return $expiration === FALSE || $expiration < $this->time->getRequestTime();
  }

  /**
   * Lưu term cấu hình mà không kích hoạt lại hook lấy token.
   *
   * @param \Drupal\taxonomy\TermInterface $config_entity
   *   Term cấu hình vừa được gán token mới.
   */
  private function saveConfigEntity(TermInterface $config_entity): void {
    // Cờ này để e_invoice_entity_presave() không gọi authenticate() lần nữa.
    $config_entity->skip_call_token = TRUE;

    try {
      $config_entity->save();
    }
    catch (\Throwable $e) {
      $this->logger->error("Cannot save invoice token: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);
    }
  }

  /**
   * Đọc giá trị field kiểu chuỗi, trả chuỗi rỗng khi bundle không có field.
   *
   * @param \Drupal\taxonomy\TermInterface $config_entity
   *   Term cấu hình.
   * @param string $field
   *   Tên field.
   *
   * @return string
   *   Giá trị field.
   */
  private function value(TermInterface $config_entity, string $field): string {
    return $config_entity->hasField($field)
      ? (string) $config_entity->get($field)->value
      : "";
  }

  /**
   * Đọc giá trị field kiểu link, bỏ dấu "/" thừa ở cuối.
   *
   * @param \Drupal\taxonomy\TermInterface $config_entity
   *   Term cấu hình.
   * @param string $field
   *   Tên field.
   *
   * @return string
   *   Base URL đã chuẩn hoá.
   */
  private function uri(TermInterface $config_entity, string $field): string {
    if (!$config_entity->hasField($field)) {
      return "";
    }

    return rtrim((string) $config_entity->get($field)->uri, "/");
  }

}
