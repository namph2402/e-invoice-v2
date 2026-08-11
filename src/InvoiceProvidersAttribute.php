<?php

namespace Drupal\e_invoice;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an address provider item annotation object.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class InvoiceProvidersAttribute extends Plugin {

  /**
   * Constructs an invoice attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param TranslatableMarkup|null $label
   *   The administrative label of the provider.
   */
  public function __construct(
    public readonly string $id,
    public ?TranslatableMarkup $label = NULL,
  ) {}

}
