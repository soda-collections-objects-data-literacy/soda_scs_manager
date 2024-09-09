<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;

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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function createStack(SodaScsComponentInterface $component): array {
    switch ($component->bundle()) {
      case 'wisski':
        return $this->sodaScsWisskiStackActions->createStack($component);

      case 'sql':
        return $this->sodaScsSqlStackActions->createStack($component);

      case 'triplestore':
        return $this->sodaScsTriplestoreStackActions->createStack($component);

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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function deleteStack(SodaScsComponentInterface $component): array {
    // @todo slim down if there is no more logic
    switch ($component->bundle()) {
      case 'wisski':
        return $this->sodaScsWisskiStackActions->deleteStack($component);

      case 'sql':
        return $this->sodaScsSqlStackActions->deleteStack($component);

      case 'triplestore':
        return $this->sodaScsTriplestoreStackActions->deleteStack($component);

      default:
        return [
          'message' => $this->t('Could not delete stack of type %bundle.'), ['%bundle' => $component->get('bundle')->value],
          'data' => [],
          'success' => FALSE,
          'error' => 'Component type not supported for deletion.',
        ];
    }
  }

}
