<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\UserInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;

/**
 * Defines the SODa SCS project membership request entity.
 *
 * @ContentEntityType(
 *   id = "soda_scs_project_membership",
 *   label = @Translation("Project membership"),
 *   label_collection = @Translation("Project memberships"),
 *   label_singular = @Translation("Project membership"),
 *   label_plural = @Translation("Project memberships"),
 *   label_count = @PluralTranslation(
 *     singular = "@count project membership",
 *     plural = "@count project memberships",
 *   ),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *   },
 *   base_table = "soda_scs_project_membership",
 *   data_table = "soda_scs_project_membership_field_data",
 *   admin_permission = "soda scs manager admin",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   field_ui_base_route = "entity.soda_scs_project.collection"
 * )
 */
final class SodaScsProjectMembership extends ContentEntityBase implements SodaScsProjectMembershipInterface {

  use EntityChangedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('Administrative label describing the request.'))
      ->setReadOnly(FALSE)
      ->setRequired(TRUE);

    $fields['requester'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Requester'))
      ->setDescription(new TranslatableMarkup('The user who created the request.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setCardinality(1);

    $fields['recipient'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Recipient'))
      ->setDescription(new TranslatableMarkup('The user that has to accept or reject the request.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setCardinality(1);

    $fields['project'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Project'))
      ->setDescription(new TranslatableMarkup('The project the user was invited to.'))
      ->setSetting('target_type', 'soda_scs_project')
      ->setRequired(TRUE)
      ->setCardinality(1);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('The status of the request.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::STATUS_PENDING => (string) new TranslatableMarkup('Pending'),
        self::STATUS_ACCEPTED => (string) new TranslatableMarkup('Accepted'),
        self::STATUS_REJECTED => (string) new TranslatableMarkup('Rejected'),
      ])
      ->setDefaultValue(self::STATUS_PENDING);

    $fields['decisionTime'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Decision time'))
      ->setDescription(new TranslatableMarkup('The timestamp when the request was accepted or rejected.'))
      ->setRequired(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the membership request was created.'))
      ->setTranslatable(FALSE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the membership request was last updated.'))
      ->setTranslatable(FALSE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $label = $this->get('label')->value;
    if ($label === NULL || $label === '') {
      $projectLabel = $this->getProject()->label();
      $recipientName = $this->getRecipient()->getDisplayName();
      $this->set('label', (string) $this->t('Invite @recipient to @project', [
        '@recipient' => $recipientName,
        '@project' => $projectLabel,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProject(): SodaScsProjectInterface {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->get('project')->entity;
    return $project;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequester(): UserInterface {
    /** @var \Drupal\user\UserInterface $requester */
    $requester = $this->get('requester')->entity;
    return $requester;
  }

  /**
   * {@inheritdoc}
   */
  public function getRecipient(): UserInterface {
    /** @var \Drupal\user\UserInterface $recipient */
    $recipient = $this->get('recipient')->entity;
    return $recipient;
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
  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDecisionTime(): int|null {
    $value = $this->get('decisionTime')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function setDecisionTime(int $timestamp): static {
    $this->set('decisionTime', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasStatus(string $status): bool {
    return $this->getStatus() === $status;
  }

}


