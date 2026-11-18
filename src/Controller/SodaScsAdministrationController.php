<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Legacy redirects for SODa SCS Manager administration paths.
 *
 * The administration overview itself uses
 * SystemController::systemAdminMenuBlockPage so Gin and Claro can render the
 * child menu links.
 */
final class SodaScsAdministrationController extends ControllerBase {

  /**
   * Sends the old People path of the registrations to the new page.
   *
   * Approval e-mails sent before the move link to the old path.
   */
  public function redirectKeycloakRegistrations(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('soda_scs_manager.user_registration_approvals')->toString(),
    );
  }

}
