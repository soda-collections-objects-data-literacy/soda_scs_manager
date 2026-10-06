<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Form\SodaScsProfileInfoForm;
use Drupal\soda_scs_manager\Form\SodaScsProfileLanguageForm;
use Drupal\soda_scs_manager\Form\SodaScsProfilePictureForm;
use Drupal\soda_scs_manager\Form\SodaScsProfileSecurityForm;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The profile page of the current user.
 */
final class SodaScsProfileController extends ControllerBase {

  public function __construct(FormBuilderInterface $formBuilder) {
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('form_builder'));
  }

  /**
   * Builds the profile page.
   */
  public function page(): array {
    $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$user instanceof UserInterface) {
      throw new \RuntimeException('The current user could not be loaded.');
    }

    $firstName = trim((string) $user->get('first_name')->value);
    $lastName = trim((string) $user->get('last_name')->value);
    $name = trim($firstName . ' ' . $lastName);
    $initials = mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1);
    if ($initials === '') {
      $initials = mb_substr($user->getDisplayName(), 0, 1);
    }

    return [
      '#theme' => 'soda_scs_manager__profile',
      '#name' => $name !== '' ? $name : $user->getDisplayName(),
      '#initials' => mb_strtoupper($initials),
      '#picture' => $user->get('user_picture')->isEmpty()
        ? NULL
        : $user->get('user_picture')->view(['label' => 'hidden']),
      '#picture_form' => $this->formBuilder->getForm(SodaScsProfilePictureForm::class),
      '#info_form' => $this->formBuilder->getForm(SodaScsProfileInfoForm::class),
      '#security_form' => $this->formBuilder->getForm(SodaScsProfileSecurityForm::class),
      '#language_form' => $this->formBuilder->getForm(SodaScsProfileLanguageForm::class),
      '#can_delete' => (int) $user->id() !== 1,
      '#delete_url' => Url::fromRoute('soda_scs_manager.profile.delete')->toString(),
      '#delete_destination' => Url::fromRoute('<front>')->toString(),
      '#attached' => [
        'library' => [
          'soda_scs_manager/globalStyling',
          'scs_custom_theme/global-scripts',
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'languages:language_interface'],
        'max-age' => 0,
      ],
    ];
  }

}
