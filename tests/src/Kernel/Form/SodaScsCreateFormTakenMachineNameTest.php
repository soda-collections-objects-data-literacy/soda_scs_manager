<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Form;

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Render\Element;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm;
use Drupal\soda_scs_manager\Form\SodaScsStackCreateForm;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;
use Psr\Log\AbstractLogger;

/**
 * The create forms reject a name whose stored machine names are taken.
 *
 * Issue 3639084: the check compared the entered name with the components'
 * machine names, but those are stored with a prefix ("sql-", "ts-", "wisski-",
 * "stack-"), so a taken name was only found when creation failed.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm
 *
 * @group soda_scs_manager
 */
class SodaScsCreateFormTakenMachineNameTest extends SodaScsKernelTestBase {

  /**
   * The message both forms show for a taken name.
   */
  protected const TAKEN_MESSAGE = 'An application with this name already exists. Choose another name.';

  /**
   * The message when no project was chosen.
   */
  protected const PROJECT_CHOICE_MESSAGE = 'Choose the project this application belongs to.';

  /**
   * Notices the module logged during the test.
   *
   * @var array<int, array{message: string, context: array}>
   */
  protected array $notices = [];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected function installSchemas(): void {
    parent::installSchemas();
    // The WissKI stack form looks the user up by its external authname.
    $this->installSchema('externalauth', ['authmap']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $role = Role::load('authenticated');
    $role->grantPermission('soda scs create stack entities');
    $role->grantPermission('soda scs create component entities');
    $role->grantPermission('access content');
    $role->save();
    $this->container->get('current_user')->setAccount($this->testUser);
    $this->container->get('request_stack')->getCurrentRequest()->query->set('deferProject', '1');

    $notices = &$this->notices;
    $this->container->get('logger.factory')->addLogger(new class($notices) extends AbstractLogger {

      /**
       * Collects notices of the module's channel.
       */
      public function __construct(protected array &$notices) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? '') === 'soda_scs_manager' && $level === RfcLogLevel::NOTICE) {
          $this->notices[] = ['message' => (string) $message, 'context' => $context];
        }
      }

    });
  }

  /**
   * A WissKI name is taken when its "sql-" component exists.
   *
   * @covers ::validateForm
   * @covers ::isMachineNameTaken
   */
  public function testStackFormRejectsNameOfExistingPrefixedComponent(): void {
    $prefixByBundle = [
      'soda_scs_sql_component' => 'sql-',
      'soda_scs_triplestore_component' => 'ts-',
      'soda_scs_wisski_component' => 'wisski-',
    ];
    foreach ($prefixByBundle as $bundle => $prefix) {
      $this->createTestComponent($bundle, ['machineName' => $prefix . 'wisski-demo']);
      $errors = $this->submitStackForm('soda_scs_wisski_stack', 'wisski demo');
      $this->assertContains(self::TAKEN_MESSAGE, $errors, "Component stored as {$prefix}wisski-demo");
      $this->entityTypeManager->getStorage('soda_scs_component')->delete(
        $this->entityTypeManager->getStorage('soda_scs_component')->loadMultiple()
      );
    }
  }

  /**
   * A WissKI name is taken when its "stack-" stack exists.
   *
   * @covers ::validateForm
   */
  public function testStackFormRejectsNameOfExistingWisskiStack(): void {
    $this->createTestStack('soda_scs_wisski_stack', ['machineName' => 'stack-wisski-demo']);

    $this->assertContains(self::TAKEN_MESSAGE, $this->submitStackForm('soda_scs_wisski_stack', 'wisski demo'));
  }

  /**
   * The rejection is logged as a notice with the stored names and the user.
   *
   * @covers ::validateForm
   */
  public function testStackFormLogsNoticeWithStoredNamesWhenRejecting(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-wisski-demo']);

    $this->submitStackForm('soda_scs_wisski_stack', 'wisski demo');

    $this->assertCount(1, $this->notices);
    $context = $this->notices[0]['context'];
    $this->assertStringStartsWith('Rejected machine name @machineName', $this->notices[0]['message']);
    $this->assertSame('wisski-demo', $context['@machineName']);
    $this->assertSame('soda_scs_wisski_stack', $context['@bundle']);
    $this->assertSame('sql-wisski-demo, ts-wisski-demo, wisski-wisski-demo, stack-wisski-demo, wisski-demo', $context['@storedNames']);
    $this->assertEquals($this->testUser->id(), $context['@uid']);
  }

  /**
   * A free name raises no taken error and logs no notice.
   *
   * @covers ::validateForm
   */
  public function testStackFormAcceptsFreeName(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-other']);
    $this->createTestStack('soda_scs_wisski_stack', ['machineName' => 'stack-another']);

    $this->assertNotContains(self::TAKEN_MESSAGE, $this->submitStackForm('soda_scs_wisski_stack', 'wisski demo'));
    $this->assertSame([], $this->notices);
  }

  /**
   * The bare name is no stored name of a WissKI, so it does not block it.
   *
   * @covers ::validateForm
   */
  public function testStackFormIgnoresBareNameOfOtherApplication(): void {
    $this->createTestComponent('soda_scs_webprotege_component', ['machineName' => 'wisski-demo']);

    $this->assertNotContains(self::TAKEN_MESSAGE, $this->submitStackForm('soda_scs_wisski_stack', 'wisski demo'));
  }

  /**
   * A bundle without a prefix still compares the bare name.
   *
   * @covers ::validateForm
   */
  public function testStackFormComparesBareNameForBundleWithoutPrefix(): void {
    $this->createTestStack('soda_scs_jupyter_stack', ['machineName' => 'lab-demo']);

    $this->assertContains(self::TAKEN_MESSAGE, $this->submitStackForm('soda_scs_jupyter_stack', 'lab demo'));
  }

  /**
   * A standalone database name is taken when its "sql-" component exists.
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::validateForm
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::isMachineNameTaken
   */
  public function testComponentFormRejectsNameOfExistingSqlComponent(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-demo']);

    $this->assertContains(self::TAKEN_MESSAGE, $this->submitComponentForm('soda_scs_sql_component', 'demo'));
    $this->assertCount(1, $this->notices);
    $this->assertSame('sql-demo', $this->notices[0]['context']['@storedNames']);
  }

  /**
   * A standalone database named like a WissKI collides with its "sql-" part.
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::validateForm
   */
  public function testComponentFormRejectsNameOfExistingWisskiSqlComponent(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-wisski-demo']);

    $this->assertContains(self::TAKEN_MESSAGE, $this->submitComponentForm('soda_scs_sql_component', 'wisski demo'));
  }

  /**
   * A WissKI stack under creation still has the bare name and blocks it.
   *
   * SodaScsWisskiStackActions::createStack() saves the stack with the entered
   * name and adds "stack-" only once the components exist.
   *
   * @covers ::isMachineNameTaken
   */
  public function testStackFormRejectsNameOfWisskiStackUnderCreation(): void {
    $this->createTestStack('soda_scs_wisski_stack', ['machineName' => 'wisski-demo']);

    $this->assertContains(self::TAKEN_MESSAGE, $this->submitStackForm('soda_scs_wisski_stack', 'wisski demo'));
  }

  /**
   * A free standalone database name raises no taken error and no notice.
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::validateForm
   */
  public function testComponentFormAcceptsFreeName(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-other']);
    // The bare name is not what an SQL component stores.
    $this->createTestComponent('soda_scs_webprotege_component', ['machineName' => 'demo']);

    $this->assertNotContains(self::TAKEN_MESSAGE, $this->submitComponentForm('soda_scs_sql_component', 'demo'));
    $this->assertSame([], $this->notices);
  }

  /**
   * Validate-only mode reports a taken name of a stack.
   *
   * @covers ::validateForm
   * @covers \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait::isValidateOnlyRequest
   */
  public function testValidateOnlyStackFormReportsTakenName(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-wisski-demo']);
    $form_object = $this->buildStackFormObject('soda_scs_wisski_stack');

    $form_state = $this->submitValidateOnly($form_object, 'soda_scs_wisski_stack', 'wisski demo');

    $this->assertContains(self::TAKEN_MESSAGE, $this->errorsOf($form_state));
    $this->assertCount(1, $this->notices);
    $this->assertFalse($form_state->isExecuted(), 'A rejected form must not be submitted.');
  }

  /**
   * Validate-only mode reports a taken name of a standalone component.
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::validateForm
   */
  public function testValidateOnlyComponentFormReportsTakenName(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-demo']);
    $form_object = $this->buildComponentFormObject('soda_scs_sql_component');

    $form_state = $this->submitValidateOnly($form_object, 'soda_scs_sql_component', 'demo');

    $this->assertContains(self::TAKEN_MESSAGE, $this->errorsOf($form_state));
  }

  /**
   * Validate-only mode saves nothing when the name is rejected.
   *
   * @covers ::validateForm
   * @covers ::save
   */
  public function testValidateOnlyStackFormSavesNothingWhenNameIsTaken(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-wisski-demo']);
    $stackCount = $this->countEntities('soda_scs_stack');
    $componentCount = $this->countEntities('soda_scs_component');
    $form_object = $this->buildStackFormObject('soda_scs_wisski_stack');

    $this->submitValidateOnly($form_object, 'soda_scs_wisski_stack', 'wisski demo');

    $this->assertSame($stackCount, $this->countEntities('soda_scs_stack'));
    $this->assertSame($componentCount, $this->countEntities('soda_scs_component'));
  }

  /**
   * A free stack name gives no error and creates nothing.
   *
   * The mocked stack actions fail the test when createStack() is called.
   *
   * @covers ::validateForm
   * @covers \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait::submitValidateOnly
   */
  public function testValidateOnlyStackFormAcceptsFreeNameWithoutSaving(): void {
    $this->createTestComponent('soda_scs_sql_component', ['machineName' => 'sql-other']);
    $stackCount = $this->countEntities('soda_scs_stack');
    $componentCount = $this->countEntities('soda_scs_component');
    $form_object = $this->buildStackFormObject('soda_scs_wisski_stack');

    $form_state = $this->submitValidateOnly($form_object, 'soda_scs_wisski_stack', 'wisski demo');

    $this->assertSame([], $this->errorsOf($form_state));
    $this->assertSame([], $this->notices);
    $this->assertSubmittedByValidateOnlyHandler($form_state);
    $this->assertSame($stackCount, $this->countEntities('soda_scs_stack'));
    $this->assertSame($componentCount, $this->countEntities('soda_scs_component'));
  }

  /**
   * A WissKI step without obtainable Nextcloud credentials is reported.
   *
   * The check only asks canEnsureCredentials(); the mock fails the test when
   * ensureCredentials() would create an app password.
   *
   * @covers ::validateForm
   */
  public function testValidateOnlyWisskiStackReportsMissingNextcloudCredentials(): void {
    $form_object = $this->buildStackFormObject('soda_scs_wisski_stack', FALSE);

    $form_state = $this->submitValidateOnly($form_object, 'soda_scs_wisski_stack', 'wisski demo');

    $errors = $this->errorsOf($form_state);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('Your Nextcloud account is not connected.', $errors[0]);
  }

  /**
   * A free component name gives no error and creates nothing.
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::validateForm
   * @covers \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait::submitValidateOnly
   */
  public function testValidateOnlyComponentFormAcceptsFreeNameWithoutSaving(): void {
    $componentCount = $this->countEntities('soda_scs_component');
    $form_object = $this->buildComponentFormObject('soda_scs_sql_component');

    $form_state = $this->submitValidateOnly($form_object, 'soda_scs_sql_component', 'demo');

    $this->assertSame([], $this->errorsOf($form_state));
    $this->assertSubmittedByValidateOnlyHandler($form_state);
    $this->assertSame($componentCount, $this->countEntities('soda_scs_component'));
  }

  /**
   * Validate-only mode neither requires nor reports a project (stack).
   *
   * Without validateOnly the same request reports the missing project, so the
   * project choice is only skipped in the validate-only mode.
   *
   * @covers ::validateForm
   * @covers \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait::applySelectedProject
   * @covers \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait::getEditedFieldNames
   */
  public function testValidateOnlyStackFormDoesNotReportMissingProject(): void {
    $deferredOnly = $this->submitValidateOnly(
      $this->buildStackFormObject('soda_scs_jupyter_stack'),
      'soda_scs_jupyter_stack',
      'lab demo',
      NULL,
    );
    $this->assertContains(self::PROJECT_CHOICE_MESSAGE, $this->errorsOf($deferredOnly));

    $validateOnly = $this->submitValidateOnly($this->buildStackFormObject('soda_scs_jupyter_stack'), 'soda_scs_jupyter_stack', 'lab demo');
    $this->assertSame([], $this->errorsOf($validateOnly));
  }

  /**
   * Validate-only mode neither requires nor reports a project (component).
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::validateForm
   */
  public function testValidateOnlyComponentFormDoesNotReportMissingProject(): void {
    $deferredOnly = $this->submitValidateOnly(
      $this->buildComponentFormObject('soda_scs_webprotege_component'),
      'soda_scs_webprotege_component',
      'protege demo',
      NULL,
    );
    $this->assertContains(self::PROJECT_CHOICE_MESSAGE, $this->errorsOf($deferredOnly));

    $validateOnly = $this->submitValidateOnly($this->buildComponentFormObject('soda_scs_webprotege_component'), 'soda_scs_webprotege_component', 'protege demo');
    $this->assertSame([], $this->errorsOf($validateOnly));
  }

  /**
   * The single-instance check of a project is skipped in validate-only mode.
   *
   * A new project has no applications, so "can only be added to a project"
   * must not appear for JupyterHub and WebProtégé.
   *
   * @covers ::validateForm
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::validateForm
   */
  public function testValidateOnlySkipsSingleInstanceProjectCheck(): void {
    $stackErrors = $this->errorsOf($this->submitValidateOnly(
      $this->buildStackFormObject('soda_scs_jupyter_stack'),
      'soda_scs_jupyter_stack',
      'lab demo',
    ));
    $componentErrors = $this->errorsOf($this->submitValidateOnly(
      $this->buildComponentFormObject('soda_scs_webprotege_component'),
      'soda_scs_webprotege_component',
      'protege demo',
    ));

    foreach ([...$stackErrors, ...$componentErrors] as $error) {
      $this->assertStringNotContainsString('can only be added to a project', $error);
      $this->assertStringNotContainsString('already has', $error);
    }
  }

  /**
   * Validate-only mode still reports a malformed name.
   *
   * @covers ::validateForm
   */
  public function testValidateOnlyStackFormReportsLabelLongerThanLimit(): void {
    $form_state = $this->submitValidateOnly(
      $this->buildStackFormObject('soda_scs_jupyter_stack'),
      'soda_scs_jupyter_stack',
      'a label that is longer than twenty-five characters',
    );

    $this->assertContains('The label must not exceed 25 characters.', $this->errorsOf($form_state));
  }

  /**
   * The save() backstop creates nothing in validate-only mode (stack).
   *
   * The mocked stack actions fail the test when createStack() is called.
   *
   * @covers ::save
   */
  public function testStackFormSaveDoesNothingInValidateOnlyMode(): void {
    $this->container->get('request_stack')->getCurrentRequest()->query->set('validateOnly', '1');
    $form_object = $this->buildStackFormObject('soda_scs_wisski_stack');
    $stackCount = $this->countEntities('soda_scs_stack');

    $form = [];
    $form_object->save($form, new FormState());

    $this->assertSame($stackCount, $this->countEntities('soda_scs_stack'));
  }

  /**
   * The save() backstop creates nothing in validate-only mode (component).
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm::save
   */
  public function testComponentFormSaveDoesNothingInValidateOnlyMode(): void {
    $this->container->get('request_stack')->getCurrentRequest()->query->set('validateOnly', '1');
    $form_object = $this->buildComponentFormObject('soda_scs_sql_component');
    $componentCount = $this->countEntities('soda_scs_component');

    $form = [];
    $form_object->save($form, new FormState());

    $this->assertSame($componentCount, $this->countEntities('soda_scs_component'));
  }

  /**
   * The submit buttons are replaced only when both query values are "1".
   *
   * @param string|null $deferProject
   *   The deferProject query value, NULL for none.
   * @param string|null $validateOnly
   *   The validateOnly query value, NULL for none.
   * @param bool $isReplaced
   *   Whether the buttons only call submitValidateOnly().
   *
   * @covers \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait::buildProjectScope
   * @covers \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait::isValidateOnlyRequest
   *
   * @dataProvider providerValidateOnlyQueryValues
   */
  public function testStackFormReplacesSubmitHandlersOnlyForBothQueryValues(?string $deferProject, ?string $validateOnly, bool $isReplaced): void {
    $form = $this->buildFormWithQuery($this->buildStackFormObject('soda_scs_wisski_stack'), 'soda_scs_wisski_stack', $deferProject, $validateOnly);

    $this->assertSame($isReplaced, $this->hasOnlyValidateOnlySubmitHandlers($form));
  }

  /**
   * The component form replaces its submit buttons by the same rule.
   *
   * @covers \Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait::buildProjectScope
   *
   * @dataProvider providerValidateOnlyQueryValues
   */
  public function testComponentFormReplacesSubmitHandlersOnlyForBothQueryValues(?string $deferProject, ?string $validateOnly, bool $isReplaced): void {
    $form = $this->buildFormWithQuery($this->buildComponentFormObject('soda_scs_sql_component'), 'soda_scs_sql_component', $deferProject, $validateOnly);

    $this->assertSame($isReplaced, $this->hasOnlyValidateOnlySubmitHandlers($form));
  }

  /**
   * Query values and whether they switch the form to validate-only mode.
   *
   * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
   *   Query values and the expectation.
   */
  public static function providerValidateOnlyQueryValues(): array {
    return [
      'both are 1' => ['1', '1', TRUE],
      'validateOnly alone' => [NULL, '1', FALSE],
      'deferProject alone' => ['1', NULL, FALSE],
      'neither' => [NULL, NULL, FALSE],
      'validateOnly is 0' => ['1', '0', FALSE],
      'validateOnly is 01' => ['1', '01', FALSE],
      'validateOnly is true' => ['1', 'true', FALSE],
      'deferProject is 0' => ['0', '1', FALSE],
      'deferProject is true' => ['true', '1', FALSE],
    ];
  }

  /**
   * Builds a create form and returns the form array.
   *
   * @return array<string, mixed>
   *   The built form.
   */
  protected function buildFormWithQuery($form_object, string $bundle, ?string $deferProject, ?string $validateOnly): array {
    $query = $this->container->get('request_stack')->getCurrentRequest()->query;
    $query->remove('deferProject');
    $query->remove('validateOnly');
    if ($deferProject !== NULL) {
      $query->set('deferProject', $deferProject);
    }
    if ($validateOnly !== NULL) {
      $query->set('validateOnly', $validateOnly);
    }
    return $this->container->get('form_builder')->getForm($form_object, $bundle);
  }

  /**
   * Whether every action button only calls submitValidateOnly().
   */
  protected function hasOnlyValidateOnlySubmitHandlers(array $form): bool {
    $keys = Element::children($form['actions']);
    $this->assertNotEmpty($keys, 'The form has action buttons.');
    foreach ($keys as $key) {
      if (($form['actions'][$key]['#submit'] ?? NULL) !== ['::submitValidateOnly']) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Builds the stack create form with the services kept away from the network.
   *
   * The stack actions fail the test when a stack would be created, and the
   * Nextcloud helpers when an app password would be created: the builder is
   * only used for validate-only requests.
   *
   * @param string $bundle
   *   The stack bundle.
   * @param bool $canEnsureCredentials
   *   What the Nextcloud credential check answers.
   */
  protected function buildStackFormObject(string $bundle, bool $canEnsureCredentials = TRUE): SodaScsStackCreateForm {
    $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->create(['bundle' => $bundle]);
    $form_object = $this->entityTypeManager->getFormObject('soda_scs_stack', 'add');
    $form_object->setEntity($stack);
    $nextcloudHelpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $nextcloudHelpers->expects($this->never())->method('ensureCredentials');
    $nextcloudHelpers->method('canEnsureCredentials')->willReturn($canEnsureCredentials);
    $stackActions = $this->createMock(SodaScsStackActionsInterface::class);
    $stackActions->expects($this->never())->method($this->anything());
    (new \ReflectionProperty(SodaScsStackCreateForm::class, 'nextcloudHelpers'))->setValue($form_object, $nextcloudHelpers);
    (new \ReflectionProperty(SodaScsStackCreateForm::class, 'sodaScsStackActions'))->setValue($form_object, $stackActions);
    return $form_object;
  }

  /**
   * Builds the component create form; its actions fail on any call.
   */
  protected function buildComponentFormObject(string $bundle): SodaScsComponentCreateForm {
    $component = $this->entityTypeManager->getStorage('soda_scs_component')->create(['bundle' => $bundle]);
    $form_object = $this->entityTypeManager->getFormObject('soda_scs_component', 'add');
    $form_object->setEntity($component);
    $componentActions = $this->createMock(SodaScsComponentActionsInterface::class);
    $componentActions->expects($this->never())->method($this->anything());
    (new \ReflectionProperty(SodaScsComponentCreateForm::class, 'sodaScsComponentActions'))->setValue($form_object, $componentActions);
    return $form_object;
  }

  /**
   * Submits a form as the wizard does and returns the form state.
   *
   * @param object $form_object
   *   The stack or component create form.
   * @param string $bundle
   *   The bundle of the new entity.
   * @param string $label
   *   The entered label.
   * @param string|null $validateOnly
   *   The validateOnly query value; NULL leaves the flag out.
   */
  protected function submitValidateOnly($form_object, string $bundle, string $label, ?string $validateOnly = '1'): FormState {
    $this->notices = [];
    $query = $this->container->get('request_stack')->getCurrentRequest()->query;
    $query->remove('validateOnly');
    if ($validateOnly !== NULL) {
      $query->set('validateOnly', $validateOnly);
    }
    $form_state = new FormState();
    // The wizard posts as a browser: hidden fields keep their access check.
    $form_state->setProgrammedBypassAccessCheck(FALSE);
    $form_state->setValue(['label', 0, 'value'], $label);
    $form_state->setValue(['machineName', 0, 'value'], '');
    $this->container->get('form_builder')->submitForm($form_object, $form_state, $bundle);
    return $form_state;
  }

  /**
   * The validation errors of a form state as strings.
   *
   * @return string[]
   *   The errors.
   */
  protected function errorsOf(FormState $form_state): array {
    return array_values(array_map('strval', $form_state->getErrors()));
  }

  /**
   * Asserts the form was submitted and only submitValidateOnly() ran.
   */
  protected function assertSubmittedByValidateOnlyHandler(FormState $form_state): void {
    $this->assertTrue($form_state->isExecuted(), 'The form was submitted.');
    $this->assertSame(['::submitValidateOnly'], $form_state->getTriggeringElement()['#submit']);
  }

  /**
   * Counts the stored entities of a type.
   */
  protected function countEntities(string $entityTypeId): int {
    return (int) $this->entityTypeManager->getStorage($entityTypeId)->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Submits the stack create form and returns the error messages.
   *
   * @return string[]
   *   The validation errors as strings.
   */
  protected function submitStackForm(string $bundle, string $label): array {
    $this->notices = [];
    $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->create(['bundle' => $bundle]);
    $form_object = $this->entityTypeManager->getFormObject('soda_scs_stack', 'add');
    $form_object->setEntity($stack);
    // Validation of a WissKI stack asks for Nextcloud credentials; keep the
    // test away from the real service.
    $nextcloudHelpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $nextcloudHelpers->method('ensureCredentials')->willReturn([
      'username' => 'test-user',
      'appPassword' => 'test-secret',
    ]);
    $property = new \ReflectionProperty(SodaScsStackCreateForm::class, 'nextcloudHelpers');
    $property->setValue($form_object, $nextcloudHelpers);
    return $this->submit($form_object, $bundle, $label);
  }

  /**
   * Submits the component create form and returns the error messages.
   *
   * @return string[]
   *   The validation errors as strings.
   */
  protected function submitComponentForm(string $bundle, string $label): array {
    $this->notices = [];
    $component = $this->entityTypeManager->getStorage('soda_scs_component')->create(['bundle' => $bundle]);
    $form_object = $this->entityTypeManager->getFormObject('soda_scs_component', 'add');
    $form_object->setEntity($component);
    return $this->submit($form_object, $bundle, $label);
  }

  /**
   * Runs the form with an empty machine name, derived from the label.
   *
   * The form state is set to rebuild, so the submit handlers that would
   * create the application never run.
   *
   * @return string[]
   *   The validation errors as strings.
   */
  protected function submit($form_object, string $bundle, string $label): array {
    $form_state = new FormState();
    $form_state->setProgrammedBypassAccessCheck(TRUE);
    $form_state->setRebuild(TRUE);
    $form_state->setValue(['label', 0, 'value'], $label);
    $form_state->setValue(['machineName', 0, 'value'], '');

    $this->container->get('form_builder')->submitForm($form_object, $form_state, $bundle);

    $this->assertFalse($form_state->isExecuted(), 'A rejected form must not be saved.');
    return array_values(array_map('strval', $form_state->getErrors()));
  }

}
