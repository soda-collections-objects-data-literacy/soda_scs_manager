<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Start, stop, and restart application containers from dashboard cards.
 *
 * Shared services (WebProtégé, JupyterHub itself) are excluded. Jupyter
 * cards control the project's shared notebook server, not the Hub, so any
 * project member who can view the Jupyter stack may start or stop it.
 */
class SodaScsLifecycleHelpers {

  use StringTranslationTrait;

  /**
   * Component bundles with a dedicated Docker container the owner may control.
   */
  public const COMPONENT_BUNDLES = [
    'soda_scs_sql_component',
    'soda_scs_triplestore_component',
    'soda_scs_wisski_component',
  ];

  /**
   * Stack bundles whose primary app container the owner may control.
   */
  public const STACK_BUNDLES = [
    'soda_scs_jupyter_stack',
    'soda_scs_wisski_stack',
  ];

  /**
   * Allowed lifecycle action names.
   */
  public const ACTIONS = [
    'start',
    'stop',
    'restart',
  ];

  /**
   * Constructs a SodaScsLifecycleHelpers object.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    #[Autowire(service: 'soda_scs_manager.jupyter.helpers')]
    protected SodaScsJupyterHelpers $jupyterHelpers,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.project_application.helpers')]
    protected SodaScsProjectApplicationHelpers $projectApplicationHelpers,
  ) {}

  /**
   * Whether the entity exposes start/stop/restart on its health icon.
   */
  public function supportsEntity(EntityInterface $entity): bool {
    if ($entity instanceof SodaScsComponentInterface) {
      return in_array($entity->bundle(), self::COMPONENT_BUNDLES, TRUE);
    }
    if ($entity instanceof SodaScsStackInterface) {
      return in_array($entity->bundle(), self::STACK_BUNDLES, TRUE);
    }
    return FALSE;
  }

  /**
   * Whether the current user may manage this entity's container lifecycle.
   */
  public function currentUserCanManage(EntityInterface $entity): bool {
    if (!$this->supportsEntity($entity)) {
      return FALSE;
    }
    return $entity->access($this->requiredOperation($entity), $this->currentUser);
  }

  /**
   * Entity operation a user needs to run lifecycle actions on the entity.
   *
   * Jupyter stacks control the project's shared notebook server, which every
   * project member may start and stop, so view access is enough.
   */
  public function requiredOperation(EntityInterface $entity): string {
    if ($entity instanceof SodaScsStackInterface && $entity->bundle() === 'soda_scs_jupyter_stack') {
      return 'view';
    }
    return 'update';
  }

  /**
   * Runs start, stop, or restart on the entity's application container.
   */
  public function performAction(EntityInterface $entity, string $action): SodaScsResult {
    if (!in_array($action, self::ACTIONS, TRUE)) {
      return SodaScsResult::failure(
        error: 'Unsupported lifecycle action.',
        message: (string) $this->t('Unsupported action.'),
        log: FALSE,
      );
    }

    if (!$this->supportsEntity($entity)) {
      return SodaScsResult::failure(
        error: 'Lifecycle is not available for this application.',
        message: (string) $this->t('This application cannot be started or stopped from the dashboard.'),
        log: FALSE,
      );
    }

    if ($entity instanceof SodaScsComponentInterface) {
      return $this->runOnComponent($entity, $action);
    }

    if ($entity instanceof SodaScsStackInterface) {
      return $this->runOnStack($entity, $action);
    }

    return SodaScsResult::failure(
      error: 'Unsupported entity type.',
      message: (string) $this->t('This application cannot be started or stopped from the dashboard.'),
      log: FALSE,
    );
  }

  /**
   * Runs a lifecycle action on a component container.
   */
  private function runOnComponent(SodaScsComponentInterface $component, string $action): SodaScsResult {
    $inspect = $this->containerHelpers->inspectComponentContainer($component);
    if (!$inspect->success) {
      return $inspect;
    }

    $containerId = is_array($inspect->data) && $inspect->data !== []
      ? (string) array_key_first($inspect->data)
      : (string) ($component->getContainerId() ?? '');
    if ($containerId === '') {
      return SodaScsResult::failure(
        error: 'Component container ID not found.',
        message: (string) $this->t('The application container could not be found.'),
        log: FALSE,
      );
    }

    return $this->runOnContainer($containerId, $action);
  }

  /**
   * Runs a lifecycle action on a stack's primary application container.
   */
  private function runOnStack(SodaScsStackInterface $stack, string $action): SodaScsResult {
    if ($stack->bundle() === 'soda_scs_jupyter_stack') {
      return $this->runOnJupyterNotebook($stack, $action);
    }

    $wisskiComponent = $this->getIncludedComponent($stack, 'soda_scs_wisski_component');
    if (!$wisskiComponent instanceof SodaScsComponentInterface) {
      return SodaScsResult::failure(
        error: 'WissKI component not found in stack.',
        message: (string) $this->t('The application container could not be found.'),
        log: FALSE,
      );
    }

    return $this->runOnComponent($wisskiComponent, $action);
  }

  /**
   * Controls the project's shared Jupyter notebook server.
   */
  private function runOnJupyterNotebook(SodaScsStackInterface $stack, string $action): SodaScsResult {
    $project = $this->projectApplicationHelpers->loadPartOfProject($stack);
    if ($project === NULL) {
      return SodaScsResult::failure(
        error: 'Jupyter stack has no project.',
        message: (string) $this->t('This JupyterHub belongs to no project.'),
        log: FALSE,
      );
    }

    return match ($action) {
      'start' => $this->jupyterHelpers->startProjectServer($project),
      'stop' => $this->jupyterHelpers->stopProjectServer($project),
      'restart' => $this->jupyterHelpers->restartProjectServer($project),
      default => SodaScsResult::failure(
        error: 'Unsupported lifecycle action.',
        message: (string) $this->t('Unsupported action.'),
        log: FALSE,
      ),
    };
  }

  /**
   * Dispatches start/stop/restart to container helpers.
   */
  private function runOnContainer(string $containerId, string $action): SodaScsResult {
    return match ($action) {
      'start' => $this->containerHelpers->startContainer($containerId),
      'stop' => $this->containerHelpers->stopContainer($containerId),
      'restart' => $this->containerHelpers->restartContainer($containerId),
      default => SodaScsResult::failure(
        error: 'Unsupported lifecycle action.',
        message: (string) $this->t('Unsupported action.'),
        log: FALSE,
      ),
    };
  }

  /**
   * Returns the first included component of the given bundle, if any.
   */
  private function getIncludedComponent(SodaScsStackInterface $stack, string $bundle): ?SodaScsComponentInterface {
    $includedComponentsItemList = $stack->get('includedComponents');
    $includedComponents = $includedComponentsItemList->referencedEntities();
    foreach ($includedComponents as $component) {
      if ($component instanceof SodaScsComponentInterface && $component->bundle() === $bundle) {
        return $component;
      }
    }
    return NULL;
  }

}
