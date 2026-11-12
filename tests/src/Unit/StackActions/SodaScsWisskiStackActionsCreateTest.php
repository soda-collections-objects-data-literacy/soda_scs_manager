<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\StackActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\SnapshotActions\SodaScsSnapshotActionsInterface;
use Drupal\soda_scs_manager\StackActions\SodaScsWisskiStackActions;
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Failure results of SodaScsWisskiStackActions::createStack().
 *
 * Issue 3637658: whichever step fails, the result has success FALSE, a
 * non-empty error and a translated message naming the step.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\StackActions\SodaScsWisskiStackActions
 * @group soda_scs_manager
 */
class SodaScsWisskiStackActionsCreateTest extends UnitTestCase {

  use ProphecyTrait;
  use SodaScsCreatePathTestTrait;
  use StringTranslationTrait;

  /**
   * The mocked SQL component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $sqlActions;

  /**
   * The mocked triplestore component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $triplestoreActions;

  /**
   * The mocked WissKI component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $wisskiActions;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $messenger;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsWisskiStackActions
   */
  protected SodaScsWisskiStackActions $actions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $bundleInfo = $this->prophesize(EntityTypeBundleInfoInterface::class);
    $bundleInfo->getBundleInfo('soda_scs_stack')->willReturn([
      'soda_scs_wisski_stack' => ['description' => 'A WissKI.', 'imageUrl' => ''],
    ]);

    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->id()->willReturn('5');
    $componentStorage = $this->prophesize(EntityStorageInterface::class);
    $componentStorage->create(Argument::type('array'))->willReturn($component->reveal());
    $versionStorage = $this->prophesize(EntityStorageInterface::class);
    $versionStorage->loadMultiple()->willReturn([]);
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('soda_scs_component')->willReturn($componentStorage->reveal());
    $entityTypeManager->getStorage('soda_scs_wisski_component_ver')->willReturn($versionStorage->reveal());

    $configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $configFactory->getEditable('soda_scs_manager.settings')->willReturn($this->prophesize(Config::class)->reveal());

    $this->logger = $this->prophesize(LoggerChannelInterface::class);
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->logger->reveal());

    $this->messenger = $this->prophesize(MessengerInterface::class);
    $this->setStringTranslation($this->getStringTranslationStub());
    $this->sqlActions = $this->prophesize(SodaScsComponentActionsInterface::class);
    $this->triplestoreActions = $this->prophesize(SodaScsComponentActionsInterface::class);
    $this->wisskiActions = $this->prophesize(SodaScsComponentActionsInterface::class);

    $this->actions = new SodaScsWisskiStackActions(
      $configFactory->reveal(),
      $this->prophesize(Connection::class)->reveal(),
      $bundleInfo->reveal(),
      $entityTypeManager->reveal(),
      $this->prophesize(FileSystemInterface::class)->reveal(),
      $loggerFactory->reveal(),
      $this->messenger->reveal(),
      $this->prophesize(SodaScsComponentActionsInterface::class)->reveal(),
      $this->sqlActions->reveal(),
      $this->triplestoreActions->reveal(),
      $this->wisskiActions->reveal(),
      $this->prophesize(SodaScsServiceActionsInterface::class)->reveal(),
      $this->prophesize(SodaScsServiceKeyActionsInterface::class)->reveal(),
      $this->prophesize(SodaScsServiceRequestInterface::class)->reveal(),
      $this->prophesize(SodaScsSnapshotActionsInterface::class)->reveal(),
      $this->prophesize(SodaScsSnapshotHelpers::class)->reveal(),
      $this->prophesize(SodaScsStackHelpers::class)->reveal(),
      $this->getStringTranslationStub(),
    );
  }

  /**
   * A failed SQL step names the database and passes the reason on.
   *
   * @covers ::createStack
   * @covers ::buildCreateStackFailure
   */
  public function testCreateStackReturnsReasonOfFailedSqlStep(): void {
    $error = new \RuntimeException('Database failed.');
    $this->sqlActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The database could not be created.'),
      'success' => FALSE,
      'error' => $error,
    ]);
    $this->triplestoreActions->createComponent(Argument::any())->shouldNotBeCalled();
    $this->wisskiActions->createComponent(Argument::any())->shouldNotBeCalled();
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createStack($this->stack());

    $this->assertCompleteFailure($result);
    $this->assertSame($error, $result['error']);
    $this->assertSame('Database: The database could not be created.', (string) $result['message']);
    $this->assertArrayHasKey('sqlComponentCreateResult', $result['data']);
  }

  /**
   * A failed triplestore step names the triplestore.
   *
   * @covers ::createStack
   * @covers ::buildCreateStackFailure
   */
  public function testCreateStackReturnsReasonOfFailedTriplestoreStep(): void {
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess());
    $error = new \RuntimeException('Repository failed.');
    $this->triplestoreActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The triplestore could not be created.'),
      'success' => FALSE,
      'error' => $error,
    ]);
    $this->wisskiActions->createComponent(Argument::any())->shouldNotBeCalled();

    $result = $this->actions->createStack($this->stack());

    $this->assertCompleteFailure($result);
    $this->assertSame($error, $result['error']);
    $this->assertSame('Triplestore: The triplestore could not be created.', (string) $result['message']);
    $this->assertArrayHasKey('triplestoreComponentCreateResult', $result['data']);
  }

  /**
   * A failed WissKI step names WissKI and keeps the user-facing reason.
   *
   * @covers ::createStack
   * @covers ::buildCreateStackFailure
   */
  public function testCreateStackReturnsReasonOfFailedWisskiStep(): void {
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess());
    $this->triplestoreActions->createComponent(Argument::any())->willReturn($this->triplestoreSuccess());
    $error = new \RuntimeException('Portainer failed.');
    $this->wisskiActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The WissKI container could not be created.'),
      'success' => FALSE,
      'error' => $error,
    ]);

    $result = $this->actions->createStack($this->stack());

    $this->assertCompleteFailure($result);
    $this->assertSame($error, $result['error']);
    $this->assertSame('WissKI: The WissKI container could not be created.', (string) $result['message']);
    $this->assertArrayHasKey('wisskiComponentCreateResult', $result['data']);
  }

  /**
   * Failure results without an error never yield a NULL or empty error.
   *
   * @covers ::createStack
   * @covers ::buildCreateStackFailure
   *
   * @dataProvider missingErrorProvider
   */
  public function testCreateStackFallsBackToDescriptiveErrorWhenStepReturnsNone(array $componentResult): void {
    $this->sqlActions->createComponent(Argument::any())->willReturn($componentResult);

    $result = $this->actions->createStack($this->stack());

    $this->assertCompleteFailure($result);
    $this->assertSame('Database step failed without error details.', $result['error']);
  }

  /**
   * Component results that carry no usable error.
   *
   * @return array<string, array>
   *   The results.
   */
  public static function missingErrorProvider(): array {
    return [
      'empty array' => [[]],
      'no error key' => [['success' => FALSE]],
      'NULL error' => [['success' => FALSE, 'error' => NULL]],
      'FALSE error' => [['success' => FALSE, 'error' => FALSE]],
      'empty string error' => [['success' => FALSE, 'error' => '']],
    ];
  }

  /**
   * A plain string message is never shown as the reason.
   *
   * @covers ::createStack
   * @covers ::buildCreateStackFailure
   */
  public function testCreateStackDoesNotShowPlainStringMessages(): void {
    $this->sqlActions->createComponent(Argument::any())->willReturn([
      'message' => 'mysql: access denied for user root',
      'success' => FALSE,
      'error' => 'access denied',
    ]);

    $result = $this->actions->createStack($this->stack());

    $this->assertCompleteFailure($result);
    $this->assertSame('access denied', $result['error']);
    $this->assertSame('Database could not be created.', (string) $result['message']);
    $this->assertStringNotContainsString('mysql', (string) $result['message']);
  }

  /**
   * A string error of a step is kept as it is.
   *
   * @covers ::createStack
   * @covers ::buildCreateStackFailure
   */
  public function testCreateStackKeepsStringErrorOfStep(): void {
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess());
    $this->triplestoreActions->createComponent(Argument::any())->willReturn($this->triplestoreSuccess());
    $this->wisskiActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The WissKI container could not be created.'),
      'success' => FALSE,
      'error' => 'Conflict',
    ]);

    $result = $this->actions->createStack($this->stack());

    $this->assertCompleteFailure($result);
    $this->assertSame('Conflict', $result['error']);
  }

  /**
   * An unexpected exception of a step is caught and returned as the error.
   *
   * @covers ::createStack
   */
  public function testCreateStackReturnsUnexpectedExceptionOfStep(): void {
    $failure = new \RuntimeException('Storage failed.');
    $this->sqlActions->createComponent(Argument::any())->willThrow($failure);
    $this->logger->log('error', Argument::type('string'), Argument::type('array'))->shouldBeCalledOnce();

    $result = $this->actions->createStack($this->stack());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame('The WissKI application could not be set up.', $result['message']->getUntranslatedString());
  }

  /**
   * A successful SQL step.
   */
  protected function sqlSuccess(): array {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->id()->willReturn('5');
    return ['success' => TRUE, 'error' => FALSE, 'data' => ['sqlComponent' => $component->reveal()]];
  }

  /**
   * A successful triplestore step.
   */
  protected function triplestoreSuccess(): array {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->id()->willReturn('6');
    return ['success' => TRUE, 'error' => NULL, 'data' => ['triplestoreComponent' => $component->reveal()]];
  }

  /**
   * Builds the stack the form hands to createStack().
   *
   * A real entity class without constructor: createStack() calls the static
   * SodaScsStack::setValue() through the instance, which a Prophecy double
   * cannot take.
   */
  protected function stack(): SodaScsStack {
    $fields = [];
    foreach ([
      'developmentInstance' => 0,
      'label' => 'Foo',
      'machineName' => 'foo',
      'automatedUpdates' => 0,
      'defaultLanguage' => 'en',
      'flavours' => NULL,
      'version' => '',
    ] as $name => $value) {
      $fields[$name] = $this->fieldList($value);
    }
    $fields['partOfProjects'] = $this->fieldList();

    return new class($fields) extends SodaScsStack {

      /**
       * Constructs the stack without the entity machinery.
       */
      public function __construct(protected array $fieldStubs) {}

      /**
       * {@inheritdoc}
       */
      public function get($field_name) {
        return $this->fieldStubs[$field_name];
      }

      /**
       * {@inheritdoc}
       */
      public function set($name, $value, $notify = TRUE) {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function getOwnerId() {
        return 7;
      }

      /**
       * {@inheritdoc}
       */
      public function save() {
        return 1;
      }

      /**
       * {@inheritdoc}
       */
      public static function setValue(SodaScsStackInterface $stack, string $fieldName, string $value, ?int $index = NULL, string $defaultValue = "", bool $overwriteOldValues = FALSE) {
        return NULL;
      }

    };
  }

}
