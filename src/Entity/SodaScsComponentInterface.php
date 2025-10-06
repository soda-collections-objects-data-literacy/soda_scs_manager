<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for the SodaScsComponent entity.
 */
interface SodaScsComponentInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Gets the description.
   *
   * @return string
   *   The description.
   */
  public function getDescription();

  /**
   * Sets the description.
   *
   * @param string $description
   *   The description.
   *
   * @return $this
   */
  public function setDescription($description);

  /**
   * Gets the image URL.
   *
   * @return string
   *   The image URL.
   */
  public function getImageUrl();

  /**
   * Sets the image URL.
   *
   * @param string $imageUrl
   *   The image URL.
   *
   * @return $this
   */
  public function setImageUrl($imageUrl);

  /**
   * Gets the label.
   *
   * @return string
   *   The label.
   */
  public function getLabel();

  /**
   * Sets the label.
   *
   * @param string $label
   *   The label.
   *
   * @return $this
   */
  public function setLabel($label);

}
