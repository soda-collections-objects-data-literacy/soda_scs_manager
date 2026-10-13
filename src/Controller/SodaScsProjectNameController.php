<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectNameHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Answers whether a project name is still available.
 */
final class SodaScsProjectNameController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected SodaScsProjectNameHelpers $projectNameHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.project_name.helpers'),
    );
  }

  /**
   * Checks a project label.
   *
   * Query: label (string), exclude (project id being renamed, optional).
   * Returns JSON: { "available": bool, "machineName": string, "error"?: string }.
   */
  public function check(Request $request): JsonResponse {
    $label = $request->query->get('label', '');
    $label = is_string($label) ? trim(strip_tags($label)) : '';
    if ($label === '') {
      return new JsonResponse([
        'available' => FALSE,
        'machineName' => '',
      ]);
    }

    $exclude = $request->query->get('exclude');
    $excludeProjectId = is_string($exclude) && ctype_digit($exclude) ? $exclude : NULL;
    $check = $this->projectNameHelpers->checkLabel($label, $excludeProjectId);

    $response = [
      'available' => $check['error'] === NULL,
      'machineName' => $check['machineName'],
    ];
    if ($check['error'] !== NULL) {
      $response['error'] = (string) $this->projectNameHelpers->errorMessage($check['error']);
    }
    return new JsonResponse($response);
  }

}
