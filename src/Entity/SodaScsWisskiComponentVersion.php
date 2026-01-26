<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the WissKI Component Version config entity.
 *
 * @ConfigEntityType(
 *   id = "soda_scs_wisski_component_ver",
 *   label = @Translation("WissKI Component Version"),
 *   label_collection = @Translation("WissKI Component Versions"),
 *   label_singular = @Translation("WissKI Component Version"),
 *   label_plural = @Translation("WissKI Component Versions"),
 *   label_count = @PluralTranslation(
 *     singular = "@count WissKI Component Version",
 *     plural = "@count WissKI Component Versions",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsWisskiComponentVersionListBuilder",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsWisskiComponentVersionForm",
 *       "add" = "Drupal\soda_scs_manager\Form\SodaScsWisskiComponentVersionForm",
 *       "edit" = "Drupal\soda_scs_manager\Form\SodaScsWisskiComponentVersionForm",
 *       "delete" = "Drupal\soda_scs_manager\Form\SodaScsWisskiComponentVersionDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "wisski_component_version",
 *   admin_permission = "administer soda scs manager settings",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "version",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "add-form" = "/admin/config/soda-scs-manager/wisski-versions/add",
 *     "edit-form" = "/admin/config/soda-scs-manager/wisski-versions/{soda_scs_wisski_component_ver}/edit",
 *     "delete-form" = "/admin/config/soda-scs-manager/wisski-versions/{soda_scs_wisski_component_ver}/delete",
 *     "collection" = "/admin/config/soda-scs-manager/wisski-versions",
 *   },
 *   config_export = {
 *     "id",
 *     "uuid",
 *     "version",
 *     "wisskiStack",
 *     "wisskiImage",
 *     "packageEnvironment",
 *   }
 * )
 */
class SodaScsWisskiComponentVersion extends ConfigEntityBase implements SodaScsWisskiComponentVersionInterface {

  /**
   * The version number (e.g., "1.0.0").
   *
   * @var string
   */
  protected string $version = '';

  /**
   * The WissKI stack version.
   *
   * @var string
   */
  protected string $wisskiStack = '';

  /**
   * The WissKI image version.
   *
   * @var string
   */
  protected string $wisskiImage = '';

  /**
   * The package environment version.
   *
   * @var string
   */
  protected string $packageEnvironment = '';

  /**
   * {@inheritdoc}
   */
  public function getVersion(): string {
    return $this->version ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setVersion(string $version): void {
    $this->version = $version;
  }

  /**
   * {@inheritdoc}
   */
  public function getWisskiStack(): string {
    return $this->wisskiStack ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setWisskiStack(string $wisskiStack): void {
    $this->wisskiStack = $wisskiStack;
  }

  /**
   * {@inheritdoc}
   */
  public function getWisskiImage(): string {
    return $this->wisskiImage ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setWisskiImage(string $wisskiImage): void {
    $this->wisskiImage = $wisskiImage;
  }

  /**
   * {@inheritdoc}
   */
  public function getPackageEnvironment(): string {
    return $this->packageEnvironment ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setPackageEnvironment(string $packageEnvironment): void {
    $this->packageEnvironment = $packageEnvironment;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return $this->getVersion();
  }

}
