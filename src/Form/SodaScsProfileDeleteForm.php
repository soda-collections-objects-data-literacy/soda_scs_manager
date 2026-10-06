<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to delete the current user's own account.
 */
final class SodaScsProfileDeleteForm extends ConfirmFormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_profile_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Do you really want to delete your profile?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This deletes your account, including your login. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete profile');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('soda_scs_manager.profile');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser()->id());
    if (!$user instanceof UserInterface || (int) $user->id() === 1) {
      $this->messenger()->addError($this->t('This profile cannot be deleted.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    // hook_user_delete removes the Keycloak account as well.
    $user->delete();
    $this->messenger()->addStatus($this->t('Your profile has been deleted.'));
    $form_state->setRedirect('<front>');
  }

}
