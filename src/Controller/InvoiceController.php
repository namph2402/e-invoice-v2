<?php

namespace Drupal\e_invoice\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Invoice controller.
 */
class InvoiceController extends ControllerBase {

  public function __construct(
    protected TimeInterface $time,
  ) {}

  /**
   * Form duplicate.
   */
  public function formDuplicate(String $uuid) {
    $invoices = $this->entityTypeManager()
      ->getStorage('invoice')
      ->loadByProperties([
        'uuid' => $uuid,
      ]);

    /** @var \Drupal\e_invoice\Entity\Invoice $invoice */
    if (!$invoice = reset($invoices)) {
      throw new NotFoundHttpException();
    }

    $date = new \DateTime('now', new \DateTimeZone('UTC'));

    $duplicate = $invoice->createDuplicate();
    $duplicate->set("field_invoice_date", $date->format('Y-m-d\TH:i:s'));
    $duplicate->set("field_invoice_issue", 0);
    $duplicate->set("field_invoice_status", 0);
    $duplicate->set("field_invoice_status_cqt", 0);
    $duplicate->set("field_invoice_mccqt", NULL);
    $duplicate->set("field_invoice_no", NULL);
    $duplicate->set("field_invoice_serial", NULL);
    $duplicate->set("field_invoice_pattern", NULL);
    $duplicate->set("field_invoice_transaction", NULL);
    $duplicate->set("field_invoice_refno", NULL);
    $duplicate->set("field_invoice_pdf", NULL);
    $duplicate->set("field_invoice_id_related", NULL);
    $duplicate->set("field_invoice_relateds", NULL);
    $duplicate->set("field_invoice_is_xml", 0);
    $duplicate->set("field_invoice_export", 0);
    $duplicate->set('created', $this->time->getRequestTime());

    return $this->entityFormBuilder()->getForm($duplicate, "edit");
  }

  /**
   * Form replace.
   */
  public function formReplace(String $uuid) {
    $invoices = $this->entityTypeManager()
      ->getStorage('invoice')
      ->loadByProperties([
        'uuid' => $uuid,
      ]);

    /** @var \Drupal\e_invoice\Entity\Invoice $invoice */
    if (!$invoice = reset($invoices)) {
      throw new NotFoundHttpException();
    }

    $date = new \DateTime('now', new \DateTimeZone('UTC'));

    $duplicate = $invoice->createDuplicate();
    $duplicate->set("field_invoice_date", $date->format('Y-m-d\TH:i:s'));
    $duplicate->set("field_invoice_issue", 0);
    $duplicate->set("field_invoice_status", 2);
    $duplicate->set("field_invoice_status_cqt", 0);
    $duplicate->set("field_invoice_mccqt", NULL);
    $duplicate->set("field_invoice_no", NULL);
    $duplicate->set("field_invoice_serial", NULL);
    $duplicate->set("field_invoice_pattern", NULL);
    $duplicate->set("field_invoice_transaction", NULL);
    $duplicate->set("field_invoice_refno", NULL);
    $duplicate->set("field_invoice_pdf", NULL);
    $duplicate->set("field_invoice_id_related", $invoice->id());
    $duplicate->set("field_invoice_relateds", $invoice->get("field_invoice_no")->value ?? NULL);
    $duplicate->set('created', $this->time->getRequestTime());

    return $this->entityFormBuilder()->getForm($duplicate, "edit");
  }

}
