<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\StackActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;

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
  protected SodaScsStackActionsInterface $sodaScsJupyterStackActions;

  /**
   * The SCS triplestore actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsNextcloudStackActions;

  /**
   * The SCS wisski actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsWisskiStackActions;

  /**
   * Class constructor.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.jupyter_stack.actions')]
    SodaScsStackActionsInterface $sodaScsJupyterStackActions,
    #[Autowire(service: 'soda_scs_manager.nextcloud_stack.actions')]
    SodaScsStackActionsInterface $sodaScsNextcloudStackActions,
    #[Autowire(service: 'soda_scs_manager.wisski_stack.actions')]
    SodaScsStackActionsInterface $sodaScsWisskiStackActions,
    TranslationInterface $stringTranslation,
  ) {
    $this->sodaScsJupyterStackActions = $sodaScsJupyterStackActions;
    $this->sodaScsNextcloudStackActions = $sodaScsNextcloudStackActions;
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

      case 'soda_scs_jupyter_stack':
        return $this->sodaScsJupyterStackActions->createStack($stack);

      case 'soda_scs_nextcloud_stack':
        return $this->sodaScsNextcloudStackActions->createStack($stack);

      default:
        throw new \Exception('Stack type not supported for creation.');
    }
  }

  /**
   * Creates a snapshot of a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack entity.
   * @param string $snapshotMachineName
   *   The snapshot machine name.
   * @param int $timestamp
   *   The timestamp.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function createSnapshot(SodaScsStackInterface $stack, string $snapshotMachineName, int $timestamp): SodaScsResult {
    switch ($stack->bundle()) {
      case 'soda_scs_wisski_stack':
        return $this->sodaScsWisskiStackActions->createSnapshot($stack, $snapshotMachineName, $timestamp);

      case 'soda_scs_jupyter_stack':
        return $this->sodaScsJupyterStackActions->createSnapshot($stack, $snapshotMachineName, $timestamp);

      case 'soda_scs_nextcloud_stack':
        return $this->sodaScsNextcloudStackActions->createSnapshot($stack, $snapshotMachineName, $timestamp);

      default:
        throw new \Exception('Component type not supported for snapshot creation.');
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

      // @todo Jupyter is not a stack, but an Account.
      case 'soda_scs_jupyter_stack':
        return $this->sodaScsJupyterStackActions->deleteStack($stack);

      // @todo Jupyter is not a stack, but an Account.
      case 'soda_scs_nextcloud_stack':
        return $this->sodaScsNextcloudStackActions->deleteStack($stack);

      /* @todo Better error handling with trace info. */
      default:
        throw new \Exception('Component type not supported.');
    }
  }

  /**
   * Restore a stack from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot): SodaScsResult {
    switch ($snapshot->get('snapshotOfStack')->entity->bundle()) {
      case 'soda_scs_wisski_stack':
        return $this->sodaScsWisskiStackActions->restoreFromSnapshot($snapshot);

      default:
        throw new \Exception('Stack type not supported.');
    }
  }

}
