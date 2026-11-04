<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sets a project member's role on one application (JSON, for the team editor).
 */
final class SodaScsProjectMemberRoleController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected SodaScsProjectMembershipHelpers $projectMembershipHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.project_membership.helpers'),
    );
  }

  /**
   * Stores the role from the JSON body {"role": "admin"|"user"}.
   *
   * CSRF is enforced via route requirement _csrf_request_header_token.
   */
  public function setRole(Request $request, SodaScsProjectInterface $soda_scs_project, UserInterface $user, SodaScsComponentInterface $soda_scs_component): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);
    $role = is_array($payload) && is_string($payload['role'] ?? NULL) ? $payload['role'] : '';

    $result = $this->projectMembershipHelpers->setMemberComponentRole(
      $soda_scs_project,
      $user,
      $soda_scs_component,
      $role,
      $this->currentUser(),
    );

    return new JsonResponse([
      'success' => $result->success,
      'message' => $result->message,
      'role' => $result->success ? $role : NULL,
    ], $result->success || $result->error === 'keycloak_sync_failed' ? 200 : 400);
  }

}
