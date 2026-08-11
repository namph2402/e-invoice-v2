<?php

declare(strict_types=1);

namespace Drupal\e_invoice;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list controller for the invoice entity type.
 */
final class InvoiceListBuilder extends EntityListBuilder {

  /**
   * {@inheritDoc}
   */
  protected DateFormatterInterface $dateFormatter;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    DateFormatterInterface $date_formatter,
  ) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get("entity_type.manager")->getStorage($entity_type->id()),
      $container->get("date.formatter"),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header["label"] = $this->t("Label");
    $header["status"] = $this->t("Status");
    $header["uid"] = $this->t("Author");
    $header["type"] = $this->t("Type");
    $header["created"] = $this->t("Created");
    $header["changed"] = $this->t("Updated");
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\e_invoice\InvoiceInterface $entity */
    $row["label"] = $entity->toLink();

    $row["status"] = $entity->get("status")->value
      ? $this->t("Enabled")
      : $this->t("Disabled");

    /** @var \Drupal\user\UserInterface $account */
    $account = $entity->get("uid")->entity;

    $row["uid"]["data"] = $entity->get("uid")->view([
      "label" => "hidden",
      "settings" => [
        "link" => $account->isAuthenticated(),
      ],
    ]);

    $bundle_info = \Drupal::service("entity_type.bundle.info")->getBundleInfo("invoice");
    $row["type"] = $bundle_info[$entity->bundle()]["label"] ?? $entity->bundle();

    $row["created"] = $this->dateFormatter->format(
      (int) $entity->get("created")->value,
      "custom",
      "M Y - H:i"
    );

    $row["changed"] = $this->dateFormatter->format(
      (int) $entity->get("changed")->value,
      "custom",
      "M Y - H:i"
    );

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityIds() {
    $query = $this->getStorage()->getQuery()
      ->sort("created", "DESC")
      ->accessCheck(TRUE);
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

}
