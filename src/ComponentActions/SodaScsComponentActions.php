<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The SCS filesystem actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsFilesystemComponentActions;

  /**
   * The SCS sql actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsSqlComponentActions;

  /**
   * The SCS triplestore actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions;

  /**
   * The SCS wisski actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsWisskiComponentActions;

  /**
   * Class constructor.
   */
  public function __construct(SodaScsComponentActionsInterface $sodaScsFilesystemComponentActions, SodaScsComponentActionsInterface $sodaScsSqlComponentActions, SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions, SodaScsComponentActionsInterface $sodaScsWisskiComponentActions, TranslationInterface $stringTranslation) {
    $this->sodaScsFilesystemComponentActions = $sodaScsFilesystemComponentActions;
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsTriplestoreComponentActions = $sodaScsTriplestoreComponentActions;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Creates a stack.
   *
   * A stack consists of one or more components.
   * We sort by type.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS Component entity.
   *
   * @return array
   *   The result of the request.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array {
    switch ($entity->bundle()) {
      case 'soda_scs_filesystem_component':
        return $this->sodaScsFilesystemComponentActions->createComponent($entity);

      case 'soda_scs_sql_component':
        return $this->sodaScsSqlComponentActions->createComponent($entity);

      case 'soda_scs_triplestore_component':
        return $this->sodaScsTriplestoreComponentActions->createComponent($entity);

      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions->createComponent($entity);

      default:
        return [];
    }
  }

  /**
   * Get all SODa SCS components.
   *
   * @return array
   *   The result of the request.
   */
  public function getComponents(): array {
    return [];

  }

  /**
   * Read a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function getComponent($component): array {
    return [
      'message' => 'Component read',
      'data' => [],
      'error' => NULL,
      'success' => TRUE,
    ];
  }

  /**
   * Updates a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function updateComponent($component): array {
    switch ($component->bundle()) {
      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions->updateComponent($component);

      default:
        return [];
    }
  }

  /**
   * Deletes a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   *
   * @todo Check if referenced components are deleted as well.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    // @todo slim down if there is no more logic
    switch ($component->bundle()) {
      case 'soda_scs_filesystem_component':
        return $this->sodaScsFilesystemComponentActions->deleteComponent($component);

      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions->deleteComponent($component);

      case 'soda_scs_sql_component':
        return $this->sodaScsSqlComponentActions->deleteComponent($component);

      case 'soda_scs_triplestore_component':
        return $this->sodaScsTriplestoreComponentActions->deleteComponent($component);

      default:
        return [
          'message' => $this->t('Could not delete component of type @bundle.', ['@bundle' => $component->bundle()]),
          'data' => [],
          'success' => FALSE,
          'error' => 'Component type not supported for deletion.',
        ];
    }
  }

  /**
   * Create a snapshot of a component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @param string $label
   *   The label of the snapshot.
   *
   * @return array
   *   The result of the request.
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $label): array {
    return [];
  }

}
