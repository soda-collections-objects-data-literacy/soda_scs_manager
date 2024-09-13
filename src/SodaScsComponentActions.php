<?php

namespace Drupal\soda_scs_manager;

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
  public function __construct(SodaScsComponentActionsInterface $sodaScsSqlComponentActions, SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions, SodaScsComponentActionsInterface $sodaScsWisskiComponentActions, TranslationInterface $stringTranslation) {
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsTriplestoreComponentActions = $sodaScsTriplestoreComponentActions;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Creates a stack.
   *
   * A stack consists of one or more components.
   * We sort by bundle.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS Component entity.
   *
   * @return array
   *   The result of the request.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array {
    switch ($entity->getBundle()) {
      case 'wisski':
        return $this->sodaScsWisskiComponentActions->createComponent($entity);

      case 'sql':
        return $this->sodaScsSqlComponentActions->createComponent($entity);

      case 'triplestore':
        return $this->sodaScsTriplestoreComponentActions->createComponent($entity);

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
      case 'wisski':
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
      case 'wisski':
        return $this->sodaScsWisskiComponentActions->deleteComponent($component);

      case 'sql':
        return $this->sodaScsSqlComponentActions->deleteComponent($component);

      case 'triplestore':
        return $this->sodaScsTriplestoreComponentActions->deleteComponent($component);

      default:
        return [
          'message' => $this->t('Could not delete stack of type %bundle.'), ['%bundle' => $component->bundle()],
          'data' => [],
          'success' => FALSE,
          'error' => 'Component type not supported for deletion.',
        ];
    }
  }

}
