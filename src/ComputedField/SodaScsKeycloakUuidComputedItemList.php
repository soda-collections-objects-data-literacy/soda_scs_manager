<?php

namespace Drupal\soda_scs_manager\ComputedField;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computed item list for the project keycloakUuid field.
 */
class SodaScsKeycloakUuidComputedItemList extends FieldItemList {
  use ComputedItemListTrait;
  use MessengerTrait;
  use LoggerChannelTrait;
  use StringTranslationTrait;

  /**
   * Ensure the computed value exists before Drupal checks emptiness.
   */
  public function isEmpty() {
    $this->ensureComputedValue();
    return parent::isEmpty();
  }

  /**
   * Ensure the computed value exists before retrieving it.
   */
  public function getValue() {
    $this->ensureComputedValue();
    return parent::getValue();
  }

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $entity = $this->getEntity();
    if (!$entity) {
      return;
    }

    // Ensure the entity is saved so that dependent computed fields (groupId)
    // can be resolved deterministically.
    if ($entity->id() === NULL) {
      return;
    }

    // Resolve the computed group id first.
    // Accessing ->value triggers computation of the dependent computed field.
    $projectGroupId = (string) ($entity->get('groupId')->value ?? '');
    if ($projectGroupId === '') {
      return;
    }

    try {
      /** @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers $projectHelpers */
      $projectHelpers = \Drupal::service('soda_scs_manager.project.helpers');
      $kcToken = $projectHelpers->getKeycloakToken();
      if (!$kcToken) {
        return;
      }

      $createProjectGroupResult = $projectHelpers->createProjectGroup($entity);
      if (!$createProjectGroupResult->success) {
        return;
      }
      $keycloakUuid = $createProjectGroupResult->data['keycloakGroupData']->uuid;

      if (is_string($keycloakUuid) && $keycloakUuid !== '') {
        $this->list[0] = $this->createItem(0, $keycloakUuid);
      }
    }
    catch (\Throwable $t) {
      $this->messenger()->addError($this->t('Failed to compute Keycloak UUID for project @project: @error', [
        '@project' => $entity->label(),
        '@error' => $t->getMessage(),
      ]));
      $this->getLogger('soda_scs_manager')->error('Failed to compute Keycloak UUID for project @project: @error', [
        '@project' => $entity->label(),
        '@error' => $t->getMessage(),
      ]);
      return;
    }
  }

}
