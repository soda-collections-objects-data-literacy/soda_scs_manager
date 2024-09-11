<?php

namespace Drupal\soda_scs_manager\Entity;

/**
 * Interface for the SodaScsComponent entity.
 */
interface SodaScsComponentBundleInterface {

  /**
   * Returns the description of the ComponentBundle.
   *
   * @return string
   *   The description of the ComponentBundle.
   */
  public function getDescription();

  /**
   * Sets the description of the ComponentBundle.
   *
   * @param string $description
   *   The description of the ComponentBundle.
   *
   * @return $this
   */
  public function setDescription($description): self;

  /**
   * Returns the image of the ComponentBundle.
   *
   * @return string
   *   The image of the ComponentBundle.
   */
  public function getImageUrl(): string;

  /**
   * Sets the image of the ComponentBundle.
   *
   * @param string $imageUrl
   *   The image of the ComponentBundle.
   *
   * @return $this
   */
  public function setImageUrl($imageUrl): self;

}
