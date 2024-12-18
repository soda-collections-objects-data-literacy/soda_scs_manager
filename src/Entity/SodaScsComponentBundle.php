<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\soda_scs_manager\Entity\SodaScsComponentBundleInterface;

/**
 * Defines the SODa SCS Component Bundle entity.
 *
 * @ConfigEntityType(
 *   id = "soda_scs_component_bundle",
 *   label = @Translation("SODa SCS Component Bundle"),
 *   label_collection = @Translation("SODa SCS Component Bundles"),
 *   label_singular = @Translation("SODa SCS Component Bundle"),
 *   label_plural = @Translation("SODa SCS Component Bundles"),
 *   handlers = {
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsComponentBundleListBuilder",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsComponentBundleForm",
 *       "add" = "Drupal\soda_scs_manager\Form\SodaScsComponentBundleForm",
 *       "edit" = "Drupal\soda_scs_manager\Form\SodaScsComponentBundleForm",
 *       "delete" = "Drupal\soda_scs_manager\Form\SodaScsComponentBundleDeleteForm"
 *     },
 *   },
 *   revision_table = "soda_scs_component_bundle_revision",
 *   config_prefix = "soda_scs_component_bundle",
 *   admin_permission = "administer site configuration",
 *   bundle_of = "soda_scs_component",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "bundle" = "bundle",
 *     "owner" = "user",
 *     "user_id" = "user",
 *     "langcode" = "langcode",
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/soda_scs_component_bundle/{soda_scs_component_bundle}",
 *     "add-form" = "/admin/structure/soda_scs_component_bundle/add",
 *     "edit-form" = "/admin/structure/soda_scs_component_bundle/{soda_scs_component_bundle}/edit",
 *     "delete-form" = "/admin/structure/soda_scs_component_bundle/{soda_scs_component_bundle}/delete",
 *     "collection" = "/admin/structure/soda_scs_component_bundle"
 *   },
 *   config_export = {
 *     "description",
 *     "id",
 *     "imageUrl",
 *     "label",
 *     "optionsUrl",
 *     "uuid",
 *
 *   }
 * )
 */
class SodaScsComponentBundle extends ConfigEntityBundleBase implements SodaScsComponentBundleInterface {

  /**
   * {@inheritdoc}
   */
  public function isDefaultRevision() {
    return $this->isDefaultRevision;
  }

  /**
   * Returns the description of the ComponentBundle.
   *
   * @return string
   *   The description of the ComponentBundle.
   */
  public function getDescription() {
    return $this->description;
  }

  /**
   * Sets the description of the ComponentBundle.
   *
   * @param string $description
   *   The description of the ComponentBundle.
   *
   * @return $this
   */
  public function setDescription($description): self {
    $this->description = $description;
    return $this;
  }

  /**
   * Returns the image of the ComponentBundle.
   *
   * @return string
   *   The image of the ComponentBundle.
   */
  public function getImageUrl(): string {
    return $this->imageUrl;
  }

  /**
   * Sets the image of the ComponentBundle.
   *
   * @param string $imageUrl
   *   The image of the ComponentBundle.
   *
   * @return $this
   */
  public function setImageUrl($imageUrl): self {
    $this->imageUrl = $imageUrl;
    return $this;
  }

}
