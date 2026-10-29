<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Service\SodaScsUserOffboarding;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to delete the current user's own account.
 */
final class SodaScsProfileDeleteForm extends ConfirmFormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SodaScsUserOffboarding $userOffboarding,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.user_offboarding'),
    );
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
    return $this->t('This deletes your account, including your login, the projects you own and their applications. Projects where you are only a member stay, and you are removed from them. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser()->id());
    if (!$user instanceof UserInterface) {
      return $form;
    }

    $inventory = $this->userOffboarding->inventory($user);
    $owned = [];
    foreach ($inventory->ownedProjects() as $project) {
      $owned[] = $project->label();
    }
    $memberships = [];
    foreach ($inventory->memberships() as $project) {
      $memberships[] = $project->label();
    }

    if ($owned !== []) {
      $form['owned_projects'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Projects you own, which will be deleted'),
        '#items' => $owned,
      ];
    }
    if ($memberships !== []) {
      $form['memberships'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Projects you only belong to, which will be kept'),
        '#items' => $memberships,
      ];
    }
    if ($inventory->hasSharedOwnership()) {
      $form['confirm_shared_projects'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Delete these projects for the other members as well.'),
        '#description' => $this->t('Some projects you own still have other members. Confirm that those projects and their applications may be deleted.'),
        '#required' => TRUE,
      ];
    }

    return $form;
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

    // hook_user_predelete removes owned projects and service accounts.
    $user->delete();
    $this->messenger()->addStatus($this->t('Your profile has been deleted.'));
    $form_state->setRedirect('<front>');
  }

}
