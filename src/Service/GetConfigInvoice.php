<?php

namespace Drupal\e_invoice\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\taxonomy\TermInterface;

/**
 * Lấy cấu hình hóa đơn trong taxonomy.
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
    protected MessengerInterface $messenger,
    protected HandleInvoice $handleInvoice,
  ) {}

  /**
   * Lấy cấu hình, tự động làm mới token khi hết hạn.
   */
  public function handle(TermInterface $config_entity): array {

    $config = $this->buildConfig($config_entity);

    $expiration_ts = strtotime($config["invoice_expiration"] ?? "");

    if ($expiration_ts && $expiration_ts < time()) {
      $refreshed = $this->refreshToken($config_entity, $config);

      if ($refreshed !== NULL) {
        $config = $refreshed;
        $config_entity->skip_call_token = TRUE;
        $config_entity->save();
      }
    }

    return $config;
  }

  /**
   * Đọc cấu hình hóa đơn từ taxonomy term.
   */
  public function buildConfig(TermInterface $config_entity): array {
    return [
      "invoice_provider" => $config_entity->get("field_inv_provider")->value,
      "invoice_host" => $config_entity->get("field_inv_host")->uri,
      "invoice_username" => $config_entity->get("field_inv_username")->value,
      "invoice_password" => $config_entity->get("field_inv_password")->value,
      "invoice_taxcode" => $config_entity->get("field_inv_taxcode")->value,
      "invoice_appid" => $config_entity->get("field_inv_appid")->value,
      "invoice_appurl" => $config_entity->get("field_inv_appurl")->uri,
      "invoice_client" => $config_entity->get("field_inv_client")->value,
      "invoice_subscribers" => $config_entity->get("field_inv_subscribers")->value,
      "invoice_organization" => $config_entity->get("field_inv_organization")->value,
      "invoice_token" => $config_entity->get("field_inv_token")->value,
      "invoice_jwt_token" => $config_entity->get("field_inv_jwt_token")->value,
      "invoice_templates" => $config_entity->get("field_inv_templates")->getValue() ?? [],
      "invoice_expiration" => $config_entity->get("field_inv_expiration")->value,
    ];
  }

  /**
   * Lấy token mới và gán vào term.
   *
   * Term không được lưu ở đây, chỗ gọi tự quyết định (hook presave chỉ cần gán,
   * còn ::handle() thì phải gọi save).
   *
   * @param TermInterface $config_entity
   *   Term cấu hình sẽ được gán token mới.
   * @param array|null $config
   *   Cấu hình đã đọc sẵn, để trống thì tự đọc lại từ term.
   *
   * @return array|null
   *   Cấu hình đã cập nhật token, hoặc NULL khi lấy token thất bại.
   */
  public function refreshToken(TermInterface $config_entity, ?array $config = NULL): ?array {

    $config = $config ?? $this->buildConfig($config_entity);

    $data_token = $this->handleInvoice->getToken($config);

    if (empty($data_token["success"])) {
      if (array_key_exists("message", $data_token)) {
        $this->messenger->addError($data_token["message"]);
      }

      return NULL;
    }

    $date = new DrupalDateTime("now");
    $date->modify(static::TOKEN_LIFETIME);

    $config["invoice_subscribers"] = $data_token["data"]["subscribers"] ?? "";
    $config["invoice_organization"] = $data_token["data"]["organization"]["Id"] ?? "";
    $config["invoice_token"] = $data_token["data"]["token"];
    $config["invoice_jwt_token"] = $data_token["data"]["jwt_token"];
    $config["invoice_expiration"] = $date->format("Y-m-d");

    foreach (static::TOKEN_FIELDS as $field => $key) {
      if ($config_entity->hasField($field)) {
        $config_entity->set($field, $config[$key]);
      }
    }

    $this->messenger->addStatus($this->t("Get token successfully"));

    return $config;
  }

}
