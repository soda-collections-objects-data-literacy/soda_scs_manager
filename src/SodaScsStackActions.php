<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsStackActions implements SodaScsStackActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;


  /**
   * The SCS sql actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsSqlStackActions;

  /**
   * The SCS triplestore actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsTriplestoreStackActions;

  /**
   * The SCS wisski actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsWisskiStackActions;

  /**
   * Class constructor.
   */
  public function __construct(SodaScsStackActionsInterface $sodaScsSqlStackActions, SodaScsStackActionsInterface $sodaScsTriplestoreStackActions, SodaScsStackActionsInterface $sodaScsWisskiStackActions, TranslationInterface $stringTranslation) {
    $this->sodaScsSqlStackActions = $sodaScsSqlStackActions;
    $this->sodaScsTriplestoreStackActions = $sodaScsTriplestoreStackActions;
    $this->sodaScsWisskiStackActions = $sodaScsWisskiStackActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Creates a stack.
   *
   * A stack consists of one or more components.
   * We sort by bundle.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack entity.
   *
   * @return array
   *   The result of the request.
   */
  public function createStack(SodaScsStackInterface $stack): array {
    switch ($stack->getBundle()) {
      case 'wisski':
        return $this->sodaScsWisskiStackActions->createStack($stack);

      case 'sql':
        return $this->sodaScsSqlStackActions->createStack($stack);

      case 'triplestore':
        return $this->sodaScsTriplestoreStackActions->createStack($stack);

      default:
        return [];
    }
  }

  /**
   * Get all stacks of a bundle.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The result of the request.
   */
  public function getStacks($bundle, $options): array {
    switch ($bundle) {
      case 'wisski':
        return $this->sodaScsWisskiStackActions->getStacks($bundle, $options);

      default:
        return [];
    }
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
  public function getStack($component): array {
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
  public function updateStack($component): array {
    switch ($component->bundle()) {
      case 'wisski':
        return $this->sodaScsWisskiStackActions->updateStack($component);

      default:
        return [];
    }
  }

  /**
   * Deletes a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function deleteStack(SodaScsStackInterface $stack): array {
    // @todo slim down if there is no more logic
    switch ($stack->getBundle()) {
      case 'wisski':
        return $this->sodaScsWisskiStackActions->deleteStack($stack);

      case 'sql':
        return $this->sodaScsSqlStackActions->deleteStack($stack);

      case 'triplestore':
        return $this->sodaScsTriplestoreStackActions->deleteStack($stack);

      default:
        return [
          'message' => $this->t('Could not delete stack of type %bundle.'), ['%bundle' => $stack->getBundle()],
          'data' => [],
          'success' => FALSE,
          'error' => 'Component type not supported for deletion.',
        ];
    }
  }

}
