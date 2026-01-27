<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for WissKI Component Version entities.
 */
interface SodaScsWisskiComponentVersionInterface extends ConfigEntityInterface {

  /**
   * Gets the version number.
   *
   * @return string
   *   The version number (e.g., "1.0.0").
   */
  public function getVersion(): string;

  /**
   * Sets the version number.
   *
   * @param string $version
   *   The version number.
   */
  public function setVersion(string $version): void;

  /**
   * Gets the WissKI stack version.
   *
   * @return string
   *   The WissKI stack version.
   */
  public function getWisskiStack(): string;

  /**
   * Sets the WissKI stack version.
   *
   * @param string $wisskiStack
   *   The WissKI stack version.
   */
  public function setWisskiStack(string $wisskiStack): void;

  /**
   * Gets the WissKI image version.
   *
   * @return string
   *   The WissKI image version.
   */
  public function getWisskiImage(): string;

  /**
   * Sets the WissKI image version.
   *
   * @param string $wisskiImage
   *   The WissKI image version.
   */
  public function setWisskiImage(string $wisskiImage): void;

  /**
   * Gets the package environment version.
   *
   * @return string
   *   The package environment version.
   */
  public function getPackageEnvironment(): string;

  /**
   * Sets the package environment version.
   *
   * @param string $packageEnvironment
   *   The package environment version.
   */
  public function setPackageEnvironment(string $packageEnvironment): void;

  /**
   * Gets the WissKI starter recipe version.
   *
   * @return string
   *   The WissKI starter recipe version.
   */
  public function getWisskiStarterRecipe(): string;

  /**
   * Sets the WissKI starter recipe version.
   *
   * @param string $wisskiStarterRecipe
   *   The WissKI starter recipe version.
   */
  public function setWisskiStarterRecipe(string $wisskiStarterRecipe): void;

  /**
   * Gets the WissKI default data model recipe version.
   *
   * @return string
   *   The WissKI default data model recipe version.
   */
  public function getWisskiDefaultDataModelRecipe(): string;

  /**
   * Sets the WissKI default data model recipe version.
   *
   * @param string $wisskiDefaultDataModelRecipe
   *   The WissKI default data model recipe version.
   */
  public function setWisskiDefaultDataModelRecipe(string $wisskiDefaultDataModelRecipe): void;

}
