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
   * Gets the container ID.
   *
   * @return string|null
   *   The container ID or NULL when not available.
   */
  public function getContainerId(): ?string;

  /**
   * Gets the container name.
   *
   * @return string|null
   *   The container name or NULL when not available.
   */
  public function getContainerName(): ?string;

  /**
   * Gets the description.
   *
   * @return string
   *   The description.
   */
  public function getDescription();

  /**
   * Gets the image URL.
   *
   * @return string
   *   The image URL.
   */
  public function getImageUrl();

  /**
   * Gets the label.
   *
   * @return string
   *   The label.
   */
  public function getLabel();

  /**
   * Gets the parent stack.
   *
   * @return string|null
   *   The parent stack or NULL when not available.
   */
  public function getPartOfStack(): ?string;

  /**
   * Gets the version.
   *
   * @return string|null
   *   The version or NULL when not available.
   */
  public function getVersion(): ?string;

  /**
   * Loads components by owner.
   *
   * @param int $ownerId
   *   The owner ID.
   *
   * @return static[]
   *   The components.
   */
  public static function loadByOwner($ownerId): array;

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
   * Sets the image URL.
   *
   * @param string $imageUrl
   *   The image URL.
   *
   * @return $this
   */
  public function setImageUrl($imageUrl);

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
