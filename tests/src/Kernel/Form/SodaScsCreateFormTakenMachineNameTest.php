<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Form;

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\soda_scs_manager\Form\SodaScsStackCreateForm;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
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
