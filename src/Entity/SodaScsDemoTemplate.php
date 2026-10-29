<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a versioned demo project template.
 *
 * The admin builds a new version from the central demo project; user demo
 * projects are created and reset from a ready version.
 *
 * @ContentEntityType(
 *   id = "soda_scs_demo_template",
 *   label = @Translation("Demo project template"),
 *   label_collection = @Translation("Demo project templates"),
 *   label_singular = @Translation("demo project template"),
 *   label_plural = @Translation("demo project templates"),
 *   label_count = @PluralTranslation(
 *     singular = "@count demo project template",
 *     plural = "@count demo project templates",
 *   ),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "soda_scs_demo_template",
 *   admin_permission = "soda scs manager admin",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class SodaScsDemoTemplate extends ContentEntityBase implements SodaScsDemoTemplateInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['artifactDirectory'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Artifact directory'))
      ->setDescription(new TranslatableMarkup('Absolute directory with the exported data of this version.'))
      ->setSetting('max_length', 1024);

    $fields['changelog'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Changelog'))
      ->setDescription(new TranslatableMarkup('What changed compared to the previous version.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE);

    $fields['manifest'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Manifest'))
      ->setDescription(new TranslatableMarkup('JSON list of the exported applications and their artifacts.'));

    $fields['sourceProject'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Source project'))
      ->setSetting('target_type', 'soda_scs_project');

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_BUILDING)
      ->setSetting('max_length', 16);

    $fields['version'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Version'))
      ->setDescription(new TranslatableMarkup('Semantic version "major.minor.patch".'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
      ->addConstraint('UniqueField');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getArtifactDirectory(): string {
    return (string) $this->get('artifactDirectory')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangelog(): string {
    return (string) $this->get('changelog')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getManifest(): array {
    $manifest = json_decode((string) $this->get('manifest')->value, TRUE);
    return is_array($manifest) ? $manifest : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): string {
    return (string) $this->get('version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isReady(): bool {
    return $this->getStatus() === self::STATUS_READY;
  }

  /**
   * {@inheritdoc}
   */
  public function setManifest(array $manifest): static {
    $this->set('manifest', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

}
