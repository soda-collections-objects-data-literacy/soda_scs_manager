<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
   * Get the latest steps for a given operation UUID.
   *
   * @param string $operation_uuid
   *   The operation UUID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the latest steps data or error message.
   */
  public function getLatestSteps(string $operation_uuid, Request $request): JsonResponse {
    // Get limit from query parameter, default to 5.
    $limit = (int) $request->query->get('limit', 5);
    // Ensure limit is between 1 and 100.
    $limit = max(1, min(100, $limit));

    // Verify the operation exists.
    $operation = $this->sodaScsProgressHelper->readOperation($operation_uuid);
    if ($operation === NULL) {
      return new JsonResponse([
        'error' => 'Operation not found',
        'operation_uuid' => $operation_uuid,
      ], 404);
    }

    // Get the latest steps (ordered by created_microtime for precise chronological order).
    $steps = $this->sodaScsProgressHelper->findLatestStepsByOperation(
      $operation_uuid,
      [],
      'created',
      'DESC',
      $limit,
    );

    if (empty($steps)) {
      return new JsonResponse([
        'operation_uuid' => $operation_uuid,
        'operation' => [
          'uuid' => $operation['uuid'],
          'status' => $operation['status'],
        ],
        'steps' => [],
        'message' => 'No steps found for this operation',
      ]);
    }

    return new JsonResponse([
      'operation_uuid' => $operation_uuid,
      'operation' => [
        'uuid' => $operation['uuid'],
        'status' => $operation['status'],
      ],
      'steps' => $steps,
    ]);
  }

}
