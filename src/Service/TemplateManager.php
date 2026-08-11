<?php

namespace Drupal\e_invoice\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Invoice template manager.
 */
class TemplateManager {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritDoc}
   */
  public function getTemplates(): array {
    $config = $this->configFactory->get('e_invoice.settings');
    return $config->get('invoice_templates') ?? [];
  }

  /**
   * {@inheritDoc}
   */
  public function getOptions(): array {
    $options = [];

    foreach ($this->getTemplates() as $key => $template) {
      $options[$key] = sprintf(
        '%s (%s | %s)',
        $template['name'],
        $template['pattern'],
        $template['serial']
      );
    }

    return $options;
  }

}
