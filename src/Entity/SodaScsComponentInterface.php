<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for the SodaScsComponent entity.
 */
interface SodaScsComponentInterface extends ContentEntityInterface, EntityOwnerInterface {
    function getDescription();
    function setDescription($description);
    function getImageUrl();
    function setImageUrl($image_url);
    function getLabel();
    function setLabel($label);

}
