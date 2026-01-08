<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for progress tracking routes.
 */
final class SodaScsProgressController extends ControllerBase {

  /**
   * The progress helper service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper
   */
  protected SodaScsProgressHelper $sodaScsProgressHelper;

  /**
   * {@inheritDoc}
   */
  public function __construct(SodaScsProgressHelper $sodaScsProgressHelper) {
    $this->sodaScsProgressHelper = $sodaScsProgressHelper;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('soda_scs_manager.progress.helpers'),
    );
  }

  /**
   * Get the latest step for a given operation UUID.
   *
   * @param string $operation_uuid
   *   The operation UUID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the latest step data or error message.
   */
  public function getLatestStep(string $operation_uuid): JsonResponse {
    // Verify the operation exists.
    $operation = $this->sodaScsProgressHelper->readOperation($operation_uuid);
    if ($operation === NULL) {
      return new JsonResponse([
        'error' => 'Operation not found',
        'operation_uuid' => $operation_uuid,
      ], 404);
    }

    // Get the latest step.
    $steps = $this->sodaScsProgressHelper->findLatestStepsByOperation(
      $operation_uuid,
      [],
      'created',
      'DESC',
    );

    if (empty($steps)) {
      return new JsonResponse([
        'operation_uuid' => $operation_uuid,
        'operation' => [
          'uuid' => $operation['uuid'],
          'status' => $operation['status'],
        ],
        'step' => NULL,
        'message' => 'No steps found for this operation',
      ]);
    }

    // Return the first (latest) step.
    $latestStep = $steps[0];

    return new JsonResponse([
      'operation_uuid' => $operation_uuid,
      'operation' => [
        'uuid' => $operation['uuid'],
        'status' => $operation['status'],
      ],
      'step' => [
        'uuid' => $latestStep['uuid'],
        'message' => $latestStep['message'] ?? NULL,
        'created' => (int) $latestStep['created'],
      ],
    ]);
  }

}
