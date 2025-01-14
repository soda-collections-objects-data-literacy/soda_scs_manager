<?php

namespace Drupal\soda_scs_manager\StackActions;

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
    switch ($stack->bundle()) {
      case 'soda_scs_wisski_stack':
        return $this->sodaScsWisskiStackActions->createStack($stack);

      case 'soda_scs_sql_stack':
        return $this->sodaScsSqlStackActions->createStack($stack);

      case 'soda_scs_triplestore_stack':
        return $this->sodaScsTriplestoreStackActions->createStack($stack);

      default:
        throw new \Exception('Component type not supported for creation.');
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
      case 'soda_scs_wisski_stack':
        return $this->sodaScsWisskiStackActions->getStacks($bundle, $options);

      default:
        throw new \Exception('Component type not supported.');
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
    switch ($component->bundle()) {
      case 'soda_scs_wisski_stack':
        return $this->sodaScsWisskiStackActions->getStack($component);

      default:
        throw new \Exception('Component type not supported.');
    }
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
      case 'soda_scs_wisski_stack':
        return $this->sodaScsWisskiStackActions->updateStack($component);

      default:
        throw new \Exception('Component type not supported.');
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
    switch ($stack->bundle()) {
      case 'soda_scs_wisski_stack':
        return $this->sodaScsWisskiStackActions->deleteStack($stack);

      case 'soda_scs_sql_stack':
        return $this->sodaScsSqlStackActions->deleteStack($stack);

      case 'soda_scs_triplestore_stack':
        return $this->sodaScsTriplestoreStackActions->deleteStack($stack);

      /* @todo Better error handling with trace info. */
      default:
        throw new \Exception('Component type not supported.');
    }
  }

}
