<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-user SCS Manager preferences on the user profile (co-working intro flag).
 */
class SodaScsUserScsManagerSettingsForm extends FormBase {

  public function __construct(
    protected UserDataInterface $userData,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.data'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_user_scs_manager_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = $this->getRouteMatch()->getParameter('user');
    if (!$user instanceof UserInterface) {
      throw new \InvalidArgumentException('Missing user route parameter.');
    }

    $form['#user'] = $user;
    $form['#cache'] = [
      'max-age' => 0,
    ];

    $uid = (int) $user->id();
    $completed = (bool) $this->userData->get('soda_scs_manager', $uid, 'coworking_intro_completed');

    $form['coworking_intro_completed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Co-working introduction completed'),
      '#description' => $this->t('When checked, the introduction wizard is not shown on the SCS Manager dashboard or start page. Uncheck to run the wizard again the next time you open those pages.'),
      '#default_value' => $completed,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\user\UserInterface $user */
    $user = $form['#user'];
    $uid = (int) $user->id();

    if ($form_state->getValue('coworking_intro_completed')) {
      $this->userData->set('soda_scs_manager', $uid, 'coworking_intro_completed', 1);
    }
    else {
      $this->userData->delete('soda_scs_manager', $uid, 'coworking_intro_completed');
    }

    $this->messenger()->addStatus($this->t('Your SCS Manager preferences have been saved.'));
  }

}
