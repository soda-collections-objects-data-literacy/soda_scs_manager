<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared dependencies for the forms on the profile page.
 */
abstract class SodaScsProfileFormBase extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SodaScsProjectHelpers $projectHelpers,
    protected SodaScsKeycloakHelpers $keycloakHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.project.helpers'),
      $container->get('soda_scs_manager.keycloak_service.helpers'),
    );
  }

  /**
   * Loads the current user entity.
   */
  protected function loadUser(): UserInterface {
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser()->id());
    if (!$user instanceof UserInterface) {
      throw new \RuntimeException('The current user could not be loaded.');
    }
    return $user;
  }

}
