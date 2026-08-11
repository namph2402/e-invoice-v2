<?php

declare(strict_types=1);

namespace Drupal\e_invoice;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining an invoice entity type.
 */
interface InvoiceInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
