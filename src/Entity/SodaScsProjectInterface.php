<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for SODa SCS Project entities.
 */
interface SodaScsProjectInterface extends ContentEntityInterface, EntityOwnerInterface {

}
