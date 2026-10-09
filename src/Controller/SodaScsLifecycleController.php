<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsLifecycleHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON start/stop/restart for dashboard application cards.
 */
class SodaScsLifecycleController extends ControllerBase {

  /**
   * Constructs a SodaScsLifecycleController.
   */
  public function __construct(
    protected SodaScsLifecycleHelpers $lifecycleHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.lifecycle.helpers'),
    );
  }

  /**
   * Access check for the stack lifecycle route.
   *
   * Jupyter stacks need view access (the action runs on the current user's
   * own notebook); all other stacks need update access.
   */
  public function accessStackLifecycle(SodaScsStackInterface $soda_scs_stack, AccountInterface $account): AccessResultInterface {
    return $soda_scs_stack->access($this->lifecycleHelpers->requiredOperation($soda_scs_stack), $account, TRUE);
  }

  /**
   * Starts, stops, or restarts a component container.
   *
   * CSRF is enforced via route requirement _csrf_request_header_token.
   */
  public function componentLifecycle(SodaScsComponentInterface $soda_scs_component, string $action): JsonResponse {
    return $this->respond($soda_scs_component, $action);
  }

  /**
   * Starts, stops, or restarts a stack's primary application container.
   *
   * CSRF is enforced via route requirement _csrf_request_header_token.
   */
  public function stackLifecycle(SodaScsStackInterface $soda_scs_stack, string $action): JsonResponse {
    return $this->respond($soda_scs_stack, $action);
  }

  /**
   * Runs the lifecycle action and returns JSON for the card UI.
   */
  private function respond(SodaScsComponentInterface|SodaScsStackInterface $entity, string $action): JsonResponse {
    if (!$this->lifecycleHelpers->supportsEntity($entity)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('This application cannot be started or stopped from the dashboard.'),
      ], 400);
    }

    $result = $this->lifecycleHelpers->performAction($entity, $action);
    if ($result->success) {
      $this->logger('soda_scs_manager')->notice('Lifecycle @action on @type @id by uid @uid.', [
        '@action' => $action,
        '@type' => $entity->getEntityTypeId(),
        '@id' => (string) $entity->id(),
        '@uid' => (string) $this->currentUser()->id(),
      ]);
      return new JsonResponse([
        'success' => TRUE,
        'message' => $result->message,
        'action' => $action,
      ]);
    }

    return new JsonResponse([
      'success' => FALSE,
      'message' => $result->message,
      'error' => $result->error,
      'action' => $action,
    ], 200);
  }

}
