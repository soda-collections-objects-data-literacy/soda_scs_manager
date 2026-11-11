<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait;
use Drupal\soda_scs_manager\Validation\InputDisallowedTerms;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Form controller for the ScsComponent entity create form.
 *
 * The form is used to create a new component entity.
 * It saves the entity with the fields:
 * - user: The user ID of the user who created the entity.
 * - created: The time the entity was created.
 * - updated: The time the entity was updated.
 * - label: The label of the entity.
 * - notes: Private notes of the user for the entity.
 * - description: The description of the entity (comes from bundle).
 * - image: The image of the entity (comes from bundle).
 * and redirects to the components page.
 */
class SodaScsStackCreateForm extends ContentEntityForm {

  use SodaScsProjectScopedCreateFormTrait;

  /**
   * The SODa SCS Stack bundle.
   *
   * @var string
   */
  protected string $bundle;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsStackActions;

  /**
   * The Nextcloud helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers
   */
  protected SodaScsNextcloudHelpers $nextcloudHelpers;

  /**
   * Looks up applications that exist once per project.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers
   */
  protected SodaScsProjectApplicationHelpers $projectApplicationHelpers;

  /**
   * Derives machine names from labels when the client omits them.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper
   */
  protected SodaScsMachineNameHelper $machineNameHelper;

  /**
   * Constructs a new SodaScsComponentCreateForm.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface $sodaScsStackActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers $nextcloudHelpers
   *   The Nextcloud helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers $projectApplicationHelpers
   *   Looks up applications that exist once per project.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper $machineNameHelper
   *   Derives machine names from labels.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    TimeInterface $time,
    #[Autowire(service: 'soda_scs_manager.stack.actions')]
    SodaScsStackActionsInterface $sodaScsStackActions,
    EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    SodaScsNextcloudHelpers $nextcloudHelpers,
    #[Autowire(service: 'soda_scs_manager.project_application.helpers')]
    SodaScsProjectApplicationHelpers $projectApplicationHelpers,
    #[Autowire(service: 'soda_scs_manager.machine_name.helpers')]
    SodaScsMachineNameHelper $machineNameHelper,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->loggerFactory = $loggerFactory;
    $this->sodaScsStackActions = $sodaScsStackActions;
    $this->entityTypeManager = $entityTypeManager;
    $this->nextcloudHelpers = $nextcloudHelpers;
    $this->projectApplicationHelpers = $projectApplicationHelpers;
    $this->machineNameHelper = $machineNameHelper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('current_user'),
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.stack.actions'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.nextcloud.helpers'),
      $container->get('soda_scs_manager.project_application.helpers'),
      $container->get('soda_scs_manager.machine_name.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_stack_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string|null $bundle = NULL) {

    $this->bundle = $bundle;
    // Build the form.
    $form = parent::buildForm($form, $form_state);

    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_stack');

    $form['#title'] = $this->t('Create a new @label', ['@label' => $bundle_info[$this->bundle]['label']]);

    $form['owner']['widget']['#default_value'] = $this->currentUser->id();
    if (!$this->currentUser->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
      $form['developmentInstance']['#access'] = FALSE;
      $form['automatedUpdates']['#access'] = FALSE;
    }

    // Make the machineName field readonly and
    // add JavaScript to auto-generate it.
    if (isset($form['machineName'])) {
      // Keep the title for validation messages; hide it in the UI.
      $form['machineName']['#title_display'] = 'invisible';
      $form['machineName']['#description'] = '';
      // @todo Check if there is a better way to do this.
      // Add CSS classes for machine name generation.
      $form['label']['widget'][0]['value']['#attributes']['class'][] = 'soda-scs-manager--machine-name-source';
      $form['machineName']['widget'][0]['value']['#attributes']['class'][] = 'soda-scs-manager--machine-name-target';
      $form['machineName']['widget'][0]['value']['#title_display'] = 'invisible';
      $form['machineName']['widget'][0]['value']['#description'] = '';
      $urlTemplate = $this->bundle === 'soda_scs_wisski_stack'
        ? str_replace('{instanceId}', '{machineName}', (string) $this->settings->get('wisski.instances.baseUrl'))
        : '';
      $form['machineName']['widget'][0]['value']['#attributes']['data-application-url-template'] = $urlTemplate;
      // Make the machine name field read-only.
      $form['machineName']['widget'][0]['value']['#attributes']['readonly'] = 'readonly';
      $form['machineName']['widget'][0]['value']['#attributes']['class'][] = 'visually-hidden';
      $form['machineName']['widget'][0]['value']['#attributes']['aria-hidden'] = 'true';
      $form['machineName']['widget'][0]['value']['#attributes']['tabindex'] = '-1';
      // Fill machineName before the #required check. It stays required.
      $form['machineName']['widget'][0]['value']['#value_callback'] = [
        SodaScsMachineNameHelper::class,
        'valueCallback',
      ];
      $form['machineName']['widget'][0]['value']['#required_error'] = $this->t('The machine name could not be derived from the label.');
      if ($urlTemplate !== '') {
        $form['machineName']['widget'][0]['value']['#suffix'] = '<span class="description soda-scs-manager--machine-name-description"><a class="soda-scs-manager--application-url" target="_blank" rel="noopener noreferrer"></a></span>';
      }
      // Attach JavaScript to auto-generate machine name.
      $form['#attached']['library'][] = 'soda_scs_manager/machineNameGenerator';
    }

    // Every application belongs to one of the current user's projects.
    $this->buildProjectScope($form);

    // Hide the flavours field.
    $form['flavours']['#access'] = FALSE;

    // Hide the language field.
    $form['langcode']['#access'] = FALSE;

    // Hide the defaultLanguage field.
    $form['defaultLanguage']['#access'] = FALSE;

    // Change the label of the submit button.
    $form['actions']['submit']['#value'] = $this->t('Create');
    // No Drupal button--primary: the manager theme styles it orange.
    $form['actions']['submit']['#button_type'] = '';
    $form['actions']['submit']['#attributes']['class'][] = 'btn';
    $form['actions']['submit']['#attributes']['class'][] = 'btn--primary';
    $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-stack--stack--form-submit';

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';

    if ($this->bundle === 'soda_scs_wisski_stack') {
      $form['#attached']['drupalSettings']['sodaScsManager']['throbberPrimaryMessage'] = (string) $this->t('Creating your WissKI environment. Please do not close this window.');
      $form['#attached']['drupalSettings']['sodaScsManager']['throbberInfo'] = (string) $this->t('Please note: After creating the WissKI Environment, it can take up to 5 minutes to setup everything.<br><br>Please check the health status to monitor the startup progress.');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    // Before parent::validateForm() so the entity is built with the project.
    $this->applySelectedProject($form_state);
    // Backstop when the form is validated without the value callback (the
    // callback already filled a normal POST). Entity required still runs in
    // parent::validateForm() and needs the derived value.
    $this->ensureMachineNameFromLabel($form_state);
    parent::validateForm($form, $form_state);

    // Check for Little Bobby Tables SQL injection attempt.
    $bobbyTablesPattern = '/Robert`\); DROP TABLE .+; --/';
    $labelValue = $form_state->getValue('label')[0]['value'] ?? '';
    if (!empty($labelValue) && preg_match($bobbyTablesPattern, $labelValue)) {
      $form_state->setErrorByName('label', $this->t('Little Bobby Tables has no power here!'));
    }

    $machineName = trim((string) ($form_state->getValue(['machineName', 0, 'value']) ?? ''));
    if ($machineName === '') {
      $form_state->setErrorByName('machineName', $this->t('The machine name could not be derived from the label.'));
    }
    else {
      $pattern = '/^[a-z0-9-]+$/';

      if (!preg_match($pattern, $machineName)) {
        $form_state->setErrorByName('machineName', $this->t('The machinename can only contain small letters, digits, and minus.'));
      }

      if (InputDisallowedTerms::isSqlMachineName($machineName)) {
        $form_state->setErrorByName('machineName', $this->t('The machineName cannot contain the word "@word"', [
          '@word' => mb_strtolower(trim($machineName)),
        ]));
      }

      // Check if the machineName is already in use
      // by another SodaScsComponent entity.
      $entity_query = $this->entityTypeManager->getStorage('soda_scs_component')->getQuery()
        ->accessCheck(FALSE)
        ->condition('machineName', $machineName);
      $existing_entities = $entity_query->execute();

      if (!empty($existing_entities)) {
        $form_state->setErrorByName('machineName', $this->t('The machineName is already in use by another SODa SCS Component entity'));
      }

      if (strlen($machineName) > SodaScsMachineNameHelper::APPLICATION_MAX_LENGTH) {
        $form_state->setErrorByName('machineName', $this->t('The machine name must not exceed 30 characters.'));
      }
    }

    if (strlen($form_state->getValue('label')[0]['value']) > 25) {
      $form_state->setErrorByName('label', $this->t('The label must not exceed 25 characters.'));
    }

    // WebProtégé and JupyterHub exist once per project.
    if ($this->projectApplicationHelpers->isSingleInstanceBundle('soda_scs_stack', $this->bundle)) {
      $projectId = $this->resolveSelectedProjectId($form_state);
      $applicationLabel = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_stack')[$this->bundle]['label'] ?? $this->bundle;
      if ($projectId === NULL) {
        $form_state->setErrorByName('label', $this->t('@application can only be added to a project.', [
          '@application' => $applicationLabel,
        ]));
      }
      elseif ($this->projectApplicationHelpers->projectHasApplication($projectId, 'soda_scs_stack', $this->bundle)) {
        $form_state->setErrorByName('label', $this->t('This project already has @application.', [
          '@application' => $applicationLabel,
        ]));
      }
    }

    // For WissKI stacks, ensure Nextcloud credentials are available.
    // Tries stored credentials first; if missing, attempts auto-creation via
    // the current OIDC token (Flow B). If both fail, block submission.
    if ($this->bundle === 'soda_scs_wisski_stack') {
      $owner = $this->entityTypeManager->getStorage('user')
        ->load($this->currentUser->id());
      if ($owner) {
        $machineName = $form_state->getValue('machineName')[0]['value'] ?? 'scs-wisski';
        $credentials = $this->nextcloudHelpers->ensureCredentials($owner, $machineName);
        if ($credentials === NULL) {
          $connectUrl = Url::fromRoute('openid_connect.accounts_controller_index', [
            'user' => $this->currentUser->id(),
          ])->toString();
          $form_state->setErrorByName('', $this->t(
            'Your Nextcloud account is not connected. <a href=":url">Connect your Nextcloud account</a> before creating a WissKI stack.',
            [':url' => $connectUrl]
          ));
        }
      }
    }

    return TRUE;
  }

  /**
   * Language from the user's profile, limited to the field's allowed values.
   */
  private function resolveProfileLanguage(): string {
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $langcode = $user?->getPreferredLangcode(FALSE);
    return in_array($langcode, ['en', 'de'], TRUE) ? $langcode : 'en';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {

    // We call it stack here.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    $stack = $this->entity;
    $projectId = $stack->hasField('partOfProjects') && !$stack->get('partOfProjects')->isEmpty()
      ? $stack->get('partOfProjects')->target_id
      : NULL;
    $stack->set('bundle', $this->bundle);
    $stack->set('created', $this->time->getRequestTime());
    if ($stack->hasField('defaultLanguage')) {
      $stack->set('defaultLanguage', $this->resolveProfileLanguage());
    }
    $developmentInstance = (bool) ($form_state->getValue(['developmentInstance', 'value']) ?? FALSE);
    if ($stack->hasField('developmentInstance')) {
      $stack->set('developmentInstance', $developmentInstance);
    }
    $stack->set('health', 'Unknown');
    $stack->set('machineName', $form_state->getValue('machineName')[0]['value']);
    $stack->set('owner', $this->currentUser->getAccount()->id());
    $stack->set('partOfProjects', $form_state->getValue('partOfProjects'));
    $stack->set('updated', $this->time->getRequestTime());
    $stack->setLabel($form_state->getValue('label')[0]['value']);

    // Create external stack.
    $createStackResult = $this->sodaScsStackActions->createStack($stack);

    if (!$createStackResult['success']) {
      $error = $createStackResult['error'];
      // Handle different error types: Exception object, string, or null/false.
      if ($error instanceof \Exception) {
        $exception = $error;
        $errorMessage = $error->getMessage();
      }
      else {
        $errorMessage = $error ?? 'Unknown error occurred';
        $exception = new \Exception($errorMessage);
      }
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $exception,
        'Cannot create stack: @message',
        ['@message' => $errorMessage],
        LogLevel::ERROR
      );
      $this->messenger()->addMessage($this->t('Cannot create stack "@label". See logs for more details.', [
        '@label' => $this->entity->label(),
        '@username' => $this->currentUser->getAccount()->getDisplayName(),
      ]), 'error');
      // Without a rebuild, Drupal redirects (also to ?destination=).
      $form_state->setRebuild(TRUE);
      return;
    }

    if ($projectId !== NULL && $projectId !== '') {
      $form_state->setRedirect('entity.soda_scs_project.canonical', [
        'soda_scs_project' => $projectId,
      ]);
    }
    else {
      $form_state->setRedirect('soda_scs_manager.dashboard');
    }
  }

  /**
   * Fills machineName from label when the client left it empty.
   *
   * The project wizard posts application forms loaded via fetch; with JS
   * aggregation the machine-name behavior may never run, so the field arrives
   * empty even though the label is set.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function ensureMachineNameFromLabel(FormStateInterface $form_state): void {
    $machineName = trim((string) ($form_state->getValue(['machineName', 0, 'value']) ?? ''));
    if ($machineName !== '') {
      return;
    }
    $label = (string) ($form_state->getValue(['label', 0, 'value']) ?? '');
    $form_state->setValue(
      ['machineName', 0, 'value'],
      $this->machineNameHelper->fromLabel($label, SodaScsMachineNameHelper::APPLICATION_MAX_LENGTH),
    );
  }

}
