<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * SODa SCS Project.
 *
 * @ContentEntityType(
 *   id = "soda_scs_project",
 *   label = @Translation("SODa SCS Project"),
 *   label_collection = @Translation("SODa SCS Projects"),
 *   label_singular = @Translation("SODa SCS Project"),
 *   label_plural = @Translation("SODa SCS Projects"),
 *   label_count = @PluralTranslation(
 *     singular = "@count SODa SCS Project",
 *     plural = "@count SODa SCS Projects",
 *   ),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityForm",
 *     },
 *     "access" = "Drupal\Core\Entity\Access\AccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "soda_scs_project",
 *   data_table = "soda_scs_project_field_data",
 *   admin_permission = "administer soda scs project entities",
 *   field_ui_base_route = "entity.soda_scs_project.edit_form",
 *   fieldable = TRUE,
 *   common_reference_target = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "langcode" = "langcode",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "bundle" = "bundle",
 *     "uid" = "owner",
 *     "owner" = "owner",
 *   },
 *   links = {
 *     "canonical" = "/soda-scs-manager/project/{soda_scs_project}",
 *     "add-form" = "/soda-scs-manager/project/add/{bundle}",
 *     "delete-form" = "/soda-scs-manager/project/{soda_scs_project}/delete",
 *     "collection" = "/soda-scs-manager/projects",
 *   },
 *   config_export = {
 *     "bundle",
 *     "created",
 *     "description",
 *     "id",
 *     "imageUrl",
 *     "label",
 *     "langcode",
 *     "machineName",
 *     "uuid",
 *     "updated",
 *     "owner",
 *     "members",
 *     "components",
 *     "stack",
 *     "rights",
 *   }
 * )
 */
class SodaScsProject extends ContentEntityBase implements EntityInterface
{

  use EntityOwnerTrait;

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
  {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['members'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Members'))
      ->setDescription(new TranslatableMarkup('The members associated with the project.'))
      ->setSetting('target_type', 'user')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['components'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Components'))
      ->setDescription(new TranslatableMarkup('The components associated with the project.'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['stack'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Stack'))
      ->setDescription(new TranslatableMarkup('The technology stack used in the project.'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['rights'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Rights'))
      ->setDescription(new TranslatableMarkup('The rights associated with the project.'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  // Add getter and setter methods for new fields if necessary.
}
