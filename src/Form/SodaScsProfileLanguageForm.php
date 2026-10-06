<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Choose the interface language of the current user.
 */
final class SodaScsProfileLanguageForm extends SodaScsProfileFormBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsProjectHelpers $projectHelpers,
    SodaScsKeycloakHelpers $keycloakHelpers,
    protected LanguageManagerInterface $languageManager,
    protected Connection $database,
  ) {
    parent::__construct($entityTypeManager, $projectHelpers, $keycloakHelpers);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.project.helpers'),
      $container->get('soda_scs_manager.keycloak_service.helpers'),
      $container->get('language_manager'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_profile_language_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = $this->loadUser();

    $options = [];
    foreach ($this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE) as $langcode => $language) {
      $options[$langcode] = $language->getName();
    }

    $form['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $options,
      '#default_value' => $user->getPreferredLangcode(),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['btn', 'btn--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->loadUser();
    $langcode = (string) $form_state->getValue('language');

    $user->set('preferred_langcode', $langcode);
    $user->save();

    // Login re-applies the language from the registration row; keep it in step.
    $this->database->update('keycloak_user_registration')
      ->fields(['interface_langcode' => $langcode])
      ->condition('status', 'approved')
      ->condition('username', $user->getAccountName())
      ->execute();

    $language = $this->languageManager->getLanguage($langcode);
    $form_state->setRedirect('soda_scs_manager.profile', [], ['language' => $language]);
    $this->messenger()->addStatus($this->t('Your language has been saved.'));
  }

}
