<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait;
use Drupal\soda_scs_manager\Validation\InputDisallowedTerms;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
class SodaScsComponentCreateForm extends ContentEntityForm {

  use SodaScsProjectScopedCreateFormTrait;

  /**
   * The SODa SCS Component bundle.
   *
   * @var string
   */
  protected string $bundle;

  /**
   * The SODa SCS Component bundle info.
   *
   * @var array
   */
  protected array $bundleInfo;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions;

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsComponentActions;

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
   * @param Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsComponentActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
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
    SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions,
    SodaScsComponentActionsInterface $sodaScsComponentActions,
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsProjectApplicationHelpers $projectApplicationHelpers,
    SodaScsMachineNameHelper $machineNameHelper,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->sodaScsDockerRegistryServiceActions = $sodaScsDockerRegistryServiceActions;
    $this->sodaScsComponentActions = $sodaScsComponentActions;
    $this->projectApplicationHelpers = $projectApplicationHelpers;
    $this->machineNameHelper = $machineNameHelper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.docker_registry_service.actions'),
      $container->get('soda_scs_manager.component.actions'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.project_application.helpers'),
      $container->get('soda_scs_manager.machine_name.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_component_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string|null $bundle = NULL) {

    $this->bundle = $bundle;
    $this->bundleInfo = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')[$this->bundle];

    // Ensure the entity has the correct bundle before building the form.
    if (!$this->entity->bundle() && $this->bundle) {
      $this->entity = $this->entityTypeManager->getStorage('soda_scs_component')->create([
        'bundle' => $this->bundle,
      ]);
    }

    // Set the imageUrl and description for the entity.
    $this->entity->set('imageUrl', $this->bundleInfo['imageUrl']);
    $this->entity->set('description', $this->bundleInfo['description']);
    // @todo Do we need to set the health anymore?
    $this->entity->set('health', 'Unknown');

    // Build the form.
    $form = parent::buildForm($form, $form_state);

    // Optional ?label= from dashboard intro pre-fills WissKI create form.
    $request = $this->getRequest();
    if ($request && $this->bundle === 'soda_scs_wisski_component') {
      $suggested = $request->query->get('label');
      if (is_string($suggested)) {
        $suggested = trim(strip_tags($suggested));
        if ($suggested !== '' && isset($form['label']['widget'][0]['value'])) {
          $form['label']['widget'][0]['value']['#default_value'] =
            mb_substr($suggested, 0, 255);
        }
      }
    }

    // Hide the flavours field.
    $form['flavours']['#access'] = FALSE;

    $form['#title'] = $this->t('Create a new @label', ['@label' => $this->bundleInfo['label']]);

    $form['info'] = [
      '#type' => 'details',
      '#title' => $this->t('What is that?'),
      '#value' => $this->bundleInfo['description'],
    ];

    $form['owner']['widget']['#default_value'] = $this->currentUser->id();
    if (!$this->currentUser->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
      $form['automatedUpdates']['#access'] = FALSE;
      $form['developmentInstance']['#access'] = FALSE;
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
      $urlTemplate = $this->bundle === 'soda_scs_wisski_component'
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

    // Hide partOfStack field.
    $form['partOfStack']['#access'] = FALSE;

    // Change the label of the submit button.
    $form['actions']['submit']['#value'] = $this->t('Create');
    // No Drupal button--primary: the manager theme styles it orange.
    $form['actions']['submit']['#button_type'] = '';
    $form['actions']['submit']['#attributes']['class'][] = 'btn';
    $form['actions']['submit']['#attributes']['class'][] = 'btn--primary';
    $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';

    if ($this->bundle === 'soda_scs_wisski_component') {
      $form['#attached']['drupalSettings']['sodaScsManager']['throbberPrimaryMessage'] = (string) $this->t('Creating your WissKI environment. Please do not close this window.');
      $form['#attached']['drupalSettings']['sodaScsManager']['throbberInfo'] = (string) $this->t('Please note: After creating the WissKI Environment, it can take up to 5 minutes to setup everything.<br><br>Please check the Health Status to monitor the startup progress.');
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
      $pattern = '/^[a-z0-9-_]+$/';

      if (!preg_match($pattern, $machineName)) {
        $form_state->setErrorByName('machineName', $this->t('The machineName can only contain small letters, digits, minus, and underscore'));
      }

      if (InputDisallowedTerms::isSqlMachineName($machineName)) {
        $form_state->setErrorByName('machineName', $this->t('The machineName cannot contain the word "@word"', [
          '@word' => mb_strtolower(trim($machineName)),
        ]));
      }

      // Check if machineName is already in use by another soda_scs_component.
      $entity_query = $this->entityTypeManager->getStorage('soda_scs_component')->getQuery()
        ->accessCheck(FALSE)
        ->condition('machineName', $machineName);
      $existing_entities = $entity_query->execute();

      if (!empty($existing_entities)) {
        $form_state->setErrorByName('machineName', $this->t('The machineName is already in use by another Soda SCS Component entity'));
      }
    }

    // WebProtégé and JupyterHub exist once per project.
    if ($this->projectApplicationHelpers->isSingleInstanceBundle('soda_scs_component', $this->bundle)) {
      $projectId = $this->resolveSelectedProjectId($form_state);
      $applicationLabel = $this->bundleInfo['label'];
      if ($projectId === NULL) {
        $form_state->setErrorByName('label', $this->t('@application can only be added to a project.', [
          '@application' => $applicationLabel,
        ]));
      }
      elseif ($this->projectApplicationHelpers->projectHasApplication($projectId, 'soda_scs_component', $this->bundle)) {
        $form_state->setErrorByName('label', $this->t('This project already has @application.', [
          '@application' => $applicationLabel,
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {

    $projectId = $this->entity->hasField('partOfProjects') && !$this->entity->get('partOfProjects')->isEmpty()
      ? $this->entity->get('partOfProjects')->target_id
      : NULL;

    // Set the label of the entity.
    $this->entity->set('label', $this->entity->get('label')->value . ' (' . $this->bundleInfo['label'] . ')');

    // Create external stack.
    $createComponentResult = $this->sodaScsComponentActions->createComponent($this->entity);

    if (!$createComponentResult['success']) {
      $this->messenger()->addMessage($this->t('Cannot create component "@label". See logs for more details.', [
        '@label' => $this->entity->label(),
        '@username' => $this->currentUser->getAccount()->getDisplayName(),
      ]), 'error');
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot create component: @message", [
        '@message' => $createComponentResult['message'],
      ]);
      // Without a rebuild, Drupal redirects (also to ?destination=).
      $form_state->setRebuild(TRUE);
      return;
    }

    switch ($this->bundle) {
      case 'soda_scs_sql_component':
        $componentId = $createComponentResult['data']['sqlComponent']->id();
        break;

      case 'soda_scs_triplestore_component':
        $componentId = $createComponentResult['data']['triplestoreComponent']->id();
        break;

      case 'soda_scs_wisski_component':
        $componentId = $createComponentResult['data']['wisskiComponent']->id();
        break;

      default:
        $componentId = $this->entity->id() ??
        throw new \Exception('Component ID not found');
    }

    if ($projectId !== NULL && $projectId !== '') {
      $form_state->setRedirect('entity.soda_scs_project.canonical', [
        'soda_scs_project' => $projectId,
      ]);
    }
    else {
      $form_state->setRedirect('entity.soda_scs_component.canonical', ['soda_scs_component' => $componentId]);
    }
  }

  /**
   * Fills machineName from label when the client left it empty.
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
