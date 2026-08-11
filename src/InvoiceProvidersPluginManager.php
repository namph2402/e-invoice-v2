<?php

namespace Drupal\e_invoice;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Invoice providers plugin manager.
 */
class InvoiceProvidersPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/Providers',
      $namespaces,
      $module_handler,
      'Drupal\e_invoice\InvoiceProvidersInterface',
      'Drupal\e_invoice\InvoiceProvidersAttribute',
    );
    $this->setCacheBackend($cache_backend, 'invoice_providers_plugins');
  }

}
