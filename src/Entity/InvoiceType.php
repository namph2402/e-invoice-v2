<?php

declare(strict_types=1);

namespace Drupal\e_invoice\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * Defines the Invoice type configuration entity.
 *
 * @ConfigEntityType(
 *   id = "invoice_type",
 *   label = @Translation("Invoice type"),
 *   label_collection = @Translation("Invoice types"),
 *   label_singular = @Translation("invoice type"),
 *   label_plural = @Translation("invoices types"),
 *   label_count = {
 *     "singular" = "@count invoice type",
 *     "plural" = "@count invoices types",
 *   },
 *   config_prefix = "invoice_type",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   handlers = {
 *     "list_builder" = "Drupal\e_invoice\InvoiceTypeListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *     "form" = {
 *       "add" = "Drupal\e_invoice\Form\InvoiceTypeForm",
 *       "edit" = "Drupal\e_invoice\Form\InvoiceTypeForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   links = {
 *     "add-form" = "/admin/structure/invoice_types/add",
 *     "edit-form" = "/admin/structure/invoice_types/manage/{invoice_type}",
 *     "delete-form" = "/admin/structure/invoice_types/manage/{invoice_type}/delete",
 *     "collection" = "/admin/structure/invoice_types",
 *   },
 *   admin_permission = "administer invoice",
 *   bundle_of = "invoice",
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *   },
 * )
 */
final class InvoiceType extends ConfigEntityBundleBase {

  /**
   * The machine name of this invoice type.
   */
  protected string $id;

  /**
   * The human-readable name of the invoice type.
   */
  protected string $label;

}
