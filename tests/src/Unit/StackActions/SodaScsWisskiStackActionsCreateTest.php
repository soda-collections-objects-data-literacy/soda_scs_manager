<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\StackActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions;
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
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Failure results of createStack() and deleteStack() of the WissKI stack.
 *
 * Issue 3637658: whichever step fails, the result has success FALSE, a
 * non-empty error and a translated message naming the step.
 *
 * Issue 3638514: a component that cannot be deleted stays in the stack, the
 * stack is kept and the result has success FALSE.
 *
 * Issue 3638552: a failed createStack() deletes what it created, in reverse
 * order, and the stack; a component that cannot be deleted stays in the kept
 * stack.
 *
 * Issue 3638568: deleteStack() of a stack without WissKI component deletes the
 * Keycloak client and groups that a failed WissKI creation left behind.
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
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $wisskiActions;

  /**
   * The mocked stack helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $stackHelpers;

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
   * The mocked component storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $componentStorage;

  /**
   * The mocked query that looks for a WissKI component by machine name.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $componentQuery;

  /**
   * The mocked query that looks for another WissKI stack with the same name.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $stackQuery;

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
    $componentStorage = $this->componentStorage = $this->prophesize(EntityStorageInterface::class);
    $componentStorage->create(Argument::type('array'))->willReturn($component->reveal());
    // No WissKI component owns the Keycloak client unless a test says so.
    $this->componentQuery = $this->prophesize(QueryInterface::class);
    $this->componentQuery->accessCheck(FALSE)->willReturn($this->componentQuery->reveal());
    $this->componentQuery->condition('bundle', 'soda_scs_wisski_component')->willReturn($this->componentQuery->reveal());
    $this->componentQuery->condition('machineName', Argument::type('string'))->willReturn($this->componentQuery->reveal());
    $this->componentQuery->range(0, 1)->willReturn($this->componentQuery->reveal());
    $this->componentQuery->execute()->willReturn([]);
    $componentStorage->getQuery()->willReturn($this->componentQuery->reveal());
    // No other WissKI stack has the name unless a test says so.
    $this->stackQuery = $this->prophesize(QueryInterface::class);
    $this->stackQuery->accessCheck(FALSE)->willReturn($this->stackQuery->reveal());
    $this->stackQuery->condition('bundle', 'soda_scs_wisski_stack')->willReturn($this->stackQuery->reveal());
    $this->stackQuery->condition('machineName', Argument::type('array'), 'IN')->willReturn($this->stackQuery->reveal());
    $this->stackQuery->condition('id', 40, '<>')->willReturn($this->stackQuery->reveal());
    $this->stackQuery->range(0, 1)->willReturn($this->stackQuery->reveal());
    $this->stackQuery->execute()->willReturn([]);
    $stackStorage = $this->prophesize(EntityStorageInterface::class);
    $stackStorage->getQuery()->willReturn($this->stackQuery->reveal());
    $versionStorage = $this->prophesize(EntityStorageInterface::class);
    $versionStorage->loadMultiple()->willReturn([]);
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $entityTypeManager->getStorage('soda_scs_component')->willReturn($componentStorage->reveal());
    $entityTypeManager->getStorage('soda_scs_stack')->willReturn($stackStorage->reveal());
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
    $this->wisskiActions = $this->prophesize(SodaScsWisskiComponentActions::class);
    // A failed createStack() deletes what it created: by default that works.
    $this->sqlActions->deleteComponent(Argument::any())->willReturn(['success' => TRUE]);
    $this->triplestoreActions->deleteComponent(Argument::any())->willReturn(['success' => TRUE]);
    $this->wisskiActions->deleteComponent(Argument::any())->willReturn(['success' => TRUE]);
    $this->wisskiActions->deleteKeycloakResources(Argument::cetera())->willReturn(SodaScsResult::success(['deleted' => []], 'Nothing left.'));
    $this->stackHelpers = $this->prophesize(SodaScsStackHelpers::class);

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
      $this->stackHelpers->reveal(),
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
   * The WissKI component is created with the choice stored on the stack.
   *
   * @covers ::createStack
   *
   * @dataProvider withDefaultDataModelProvider
   */
  public function testCreateStackPassesWithDefaultDataModelToWisskiComponent(mixed $stackValue, bool $expected): void {
    $this->componentStorage->create(Argument::that(
      fn(array $values) => ($values['bundle'] ?? '') === 'soda_scs_wisski_component'
        && array_key_exists('withDefaultDataModel', $values)
        && (bool) $values['withDefaultDataModel'] === $expected
    ))->willReturn($this->prophesize(SodaScsComponentInterface::class)->reveal())->shouldBeCalledOnce();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess());
    $this->triplestoreActions->createComponent(Argument::any())->willReturn($this->triplestoreSuccess());
    $this->wisskiActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The WissKI container could not be created.'),
      'success' => FALSE,
      'error' => 'stop here',
    ]);

    $this->actions->createStack($this->stack(['withDefaultDataModel' => $stackValue]));
  }

  /**
   * Stack values of the field and what the WissKI component gets.
   *
   * @return array<string, array>
   *   The stored value and the expected boolean.
   */
  public static function withDefaultDataModelProvider(): array {
    return [
      'stack with default data model' => [1, TRUE],
      'stack without default data model' => [0, FALSE],
      'stack without default data model, string from the database' => ['0', FALSE],
      'stack from before the field existed' => [NULL, TRUE],
    ];
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
   * A failed database delete keeps the stack and stops before the rest.
   *
   * @covers ::deleteStack
   * @covers ::buildDeleteStackFailure
   */
  public function testDeleteStackKeepsStackWhenDatabaseDeleteFails(): void {
    $stack = $this->deletableStack();
    $sql = $this->includedComponent($stack, 'soda_scs_sql_component');
    $this->sqlActions->deleteComponent($sql)->willReturn([
      'message' => 'Cannot delete database.',
      'success' => FALSE,
      'error' => 'Portainer answered 500.',
    ]);
    $this->triplestoreActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $this->wisskiActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $this->stackHelpers->removeIncludedComponentValue(Argument::cetera())->shouldNotBeCalled();
    $stack->delete()->shouldNotBeCalled();
    $this->logger->error(Argument::containingString('Kept WissKI stack'), Argument::withEntry('@stack', 40))->shouldBeCalledOnce();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('Portainer answered 500.', $result['error']);
    $this->assertSame('Could not delete the database of the WissKI stack.', (string) $result['message']);
  }

  /**
   * A failed triplestore delete keeps the stack and the WissKI.
   *
   * @covers ::deleteStack
   * @covers ::buildDeleteStackFailure
   */
  public function testDeleteStackKeepsStackWhenTriplestoreDeleteFails(): void {
    $stack = $this->deletableStack();
    $sql = $this->includedComponent($stack, 'soda_scs_sql_component');
    $triplestore = $this->includedComponent($stack, 'soda_scs_triplestore_component');
    $this->sqlActions->deleteComponent($sql)->willReturn(['success' => TRUE]);
    $this->triplestoreActions->deleteComponent($triplestore)->willReturn([
      'message' => 'Cannot delete triplestore.',
      'success' => FALSE,
      'error' => 'User not found.',
    ]);
    $this->wisskiActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    // Only the database leaves the stack.
    $this->stackHelpers->removeIncludedComponentValue(Argument::cetera())->shouldBeCalledOnce();
    $stack->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('User not found.', $result['error']);
    $this->assertSame('Could not delete the triplestore of the WissKI stack.', (string) $result['message']);
  }

  /**
   * A WissKI delete answer without a success flag keeps the stack.
   *
   * @covers ::deleteStack
   * @covers ::buildDeleteStackFailure
   */
  public function testDeleteStackKeepsStackWhenWisskiDeleteAnswersWithoutSuccess(): void {
    $stack = $this->deletableStack();
    $sql = $this->includedComponent($stack, 'soda_scs_sql_component');
    $triplestore = $this->includedComponent($stack, 'soda_scs_triplestore_component');
    $wisski = $this->includedComponent($stack, 'soda_scs_wisski_component');
    $this->sqlActions->deleteComponent($sql)->willReturn(['success' => TRUE]);
    $this->triplestoreActions->deleteComponent($triplestore)->willReturn(['success' => TRUE]);
    $this->wisskiActions->deleteComponent($wisski)->willReturn(['message' => 'Cannot get WissKI component client uuid at keycloak.']);
    // Database and triplestore leave the stack; the WissKI stays in it.
    $this->stackHelpers->removeIncludedComponentValue(Argument::cetera())->shouldBeCalledTimes(2);
    $stack->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('Cannot get WissKI component client uuid at keycloak.', $result['error']);
    $this->assertSame('Could not delete the WissKI instance of the WissKI stack.', (string) $result['message']);
  }

  /**
   * The stack is deleted once every component is gone.
   *
   * @covers ::deleteStack
   */
  public function testDeleteStackDeletesStackWhenEveryComponentIsDeleted(): void {
    $stack = $this->deletableStack();
    $sql = $this->includedComponent($stack, 'soda_scs_sql_component');
    $triplestore = $this->includedComponent($stack, 'soda_scs_triplestore_component');
    $this->stackHelpers->retrieveIncludedComponent($stack, 'soda_scs_wisski_component')->willReturn(NULL);
    $this->sqlActions->deleteComponent($sql)->willReturn(['success' => TRUE]);
    $this->triplestoreActions->deleteComponent($triplestore)->willReturn(['success' => TRUE]);
    $this->stackHelpers->removeIncludedComponentValue(Argument::cetera())->shouldBeCalledTimes(2);
    $stack->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertTrue($result['success']);
  }

  /**
   * A failed SQL step has nothing to delete, but the stack goes.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   */
  public function testCreateStackDeletesOnlyStackWhenSqlStepFails(): void {
    $this->sqlActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The database could not be created.'),
      'success' => FALSE,
      'error' => 'Database failed.',
    ]);
    $this->sqlActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $this->triplestoreActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $this->wisskiActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame('Database: The database could not be created.', (string) $result['message']);
    $this->assertSame(1, $stack->deleteCount);
  }

  /**
   * A failed triplestore step deletes the SQL component and the stack.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   * @covers ::componentActionsFor
   */
  public function testCreateStackDeletesSqlComponentAndStackWhenTriplestoreStepFails(): void {
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The triplestore could not be created.'),
      'success' => FALSE,
      'error' => 'Repository failed.',
    ]);
    $this->sqlActions->deleteComponent($sqlComponent)->willReturn(['success' => TRUE])->shouldBeCalledOnce();
    $this->triplestoreActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $this->wisskiActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame('Repository failed.', $result['error']);
    $this->assertSame('Triplestore: The triplestore could not be created.', (string) $result['message']);
    $this->assertSame(1, $stack->deleteCount);
  }

  /**
   * A failed WissKI step deletes triplestore, then SQL, then the stack.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   * @covers ::componentActionsFor
   */
  public function testCreateStackDeletesComponentsInReverseOrderWhenWisskiStepFails(): void {
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $triplestoreComponent = $this->componentDouble('6', 'soda_scs_triplestore_component')->reveal();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())->willReturn($this->triplestoreSuccess($triplestoreComponent));
    $this->wisskiActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The WissKI container could not be created.'),
      'success' => FALSE,
      'error' => 'Portainer failed.',
    ]);
    $order = [];
    $this->triplestoreActions->deleteComponent($triplestoreComponent)->will(function () use (&$order) {
      $order[] = 'triplestore';
      return ['success' => TRUE];
    })->shouldBeCalledOnce();
    $this->sqlActions->deleteComponent($sqlComponent)->will(function () use (&$order) {
      $order[] = 'sql';
      return ['success' => TRUE];
    })->shouldBeCalledOnce();
    $this->wisskiActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame('Portainer failed.', $result['error']);
    $this->assertSame(['triplestore', 'sql'], $order);
    $this->assertSame(1, $stack->deleteCount);
  }

  /**
   * An exception after all components were created deletes all of them.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   * @covers ::componentActionsFor
   */
  public function testCreateStackDeletesAllComponentsWhenLinkingThemThrows(): void {
    $failure = new \RuntimeException('Storage failed.');
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $triplestoreComponent = $this->componentDouble('6', 'soda_scs_triplestore_component')->reveal();
    $wisskiProphecy = $this->componentDouble('7', 'soda_scs_wisski_component');
    $wisskiProphecy->save()->willThrow($failure);
    $wisskiComponent = $wisskiProphecy->reveal();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())->willReturn($this->triplestoreSuccess($triplestoreComponent));
    $this->wisskiActions->createComponent(Argument::any())->willReturn($this->wisskiSuccess($wisskiComponent));
    $order = [];
    $this->wisskiActions->deleteComponent($wisskiComponent)->will(function () use (&$order) {
      $order[] = 'wisski';
      return ['success' => TRUE];
    })->shouldBeCalledOnce();
    $this->triplestoreActions->deleteComponent($triplestoreComponent)->will(function () use (&$order) {
      $order[] = 'triplestore';
      return ['success' => TRUE];
    })->shouldBeCalledOnce();
    $this->sqlActions->deleteComponent($sqlComponent)->will(function () use (&$order) {
      $order[] = 'sql';
      return ['success' => TRUE];
    })->shouldBeCalledOnce();
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame(['wisski', 'triplestore', 'sql'], $order);
    $this->assertSame(1, $stack->deleteCount);
  }

  /**
   * A WissKI saved by createComponent() before it threw is deleted as well.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   *
   * @dataProvider savedWisskiProvider
   */
  public function testCreateStackDeletesWisskiThatWasSavedBeforeCreateComponentThrew(bool $isNew, int $expectedWisskiDeletes): void {
    $failure = new \RuntimeException('Portainer failed after the save.');
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $triplestoreComponent = $this->componentDouble('6', 'soda_scs_triplestore_component')->reveal();
    $wisskiProphecy = $this->componentDouble('7', 'soda_scs_wisski_component');
    $wisskiProphecy->isNew()->willReturn($isNew);
    $wisskiComponent = $wisskiProphecy->reveal();
    $this->componentStorage->create(Argument::that(
      fn(array $values) => ($values['bundle'] ?? '') === 'soda_scs_wisski_component'
    ))->willReturn($wisskiComponent);
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())->willReturn($this->triplestoreSuccess($triplestoreComponent));
    $this->wisskiActions->createComponent(Argument::any())->willThrow($failure);
    $this->wisskiActions->deleteComponent($wisskiComponent)->willReturn(['success' => TRUE])->shouldBeCalledTimes($expectedWisskiDeletes);
    $this->triplestoreActions->deleteComponent($triplestoreComponent)->willReturn(['success' => TRUE])->shouldBeCalledOnce();
    $this->sqlActions->deleteComponent($sqlComponent)->willReturn(['success' => TRUE])->shouldBeCalledOnce();
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame(1, $stack->deleteCount);
  }

  /**
   * Whether the WissKI was saved, and how often it is deleted then.
   *
   * @return array<string, array>
   *   The isNew() answer and the expected delete count.
   */
  public static function savedWisskiProvider(): array {
    return [
      'saved before the exception' => [FALSE, 1],
      'never saved' => [TRUE, 0],
    ];
  }

  /**
   * An unexpected exception before any component exists only deletes the stack.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   */
  public function testCreateStackDeletesOnlyStackWhenFirstStepThrows(): void {
    $this->sqlActions->createComponent(Argument::any())->willThrow(new \RuntimeException('Storage failed.'));
    $this->sqlActions->deleteComponent(Argument::any())->shouldNotBeCalled();
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame(1, $stack->deleteCount);
  }

  /**
   * A component that cannot be deleted keeps the stack and stays in it.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   */
  public function testCreateStackKeepsStackWithUndeletableComponentAndReturnsOriginalFailure(): void {
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The triplestore could not be created.'),
      'success' => FALSE,
      'error' => 'Repository failed.',
    ]);
    $this->sqlActions->deleteComponent($sqlComponent)->willReturn([
      'message' => 'Cannot delete database.',
      'success' => FALSE,
      'error' => 'Portainer answered 500.',
    ]);
    $this->logger->error(Argument::containingString('Could not remove component'), Argument::withEntry('@component', '5'))->shouldBeCalledOnce();
    $this->logger->warning(Argument::containingString('Kept the failed WissKI stack'), Argument::withEntry('@count', 1))->shouldBeCalledOnce();
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame('Repository failed.', $result['error']);
    $this->assertSame('Triplestore: The triplestore could not be created.', (string) $result['message']);
    $this->assertSame(0, $stack->deleteCount, 'The stack is kept.');
    $this->assertSame([['target_id' => '5']], $stack->setValues['includedComponents']);
    // Once before the components, once for the kept ones.
    $this->assertSame(2, $stack->saveCount);
  }

  /**
   * A failed delete does not stop the others; only the failed one is kept.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   */
  public function testCreateStackKeepsOnlyUndeletableComponentAndDeletesTheRest(): void {
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $triplestoreComponent = $this->componentDouble('6', 'soda_scs_triplestore_component')->reveal();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())->willReturn($this->triplestoreSuccess($triplestoreComponent));
    $this->wisskiActions->createComponent(Argument::any())->willReturn([
      'message' => $this->t('The WissKI container could not be created.'),
      'success' => FALSE,
      'error' => 'Portainer failed.',
    ]);
    $this->triplestoreActions->deleteComponent($triplestoreComponent)
      ->willReturn(['success' => FALSE, 'error' => 'User not found.']);
    $this->sqlActions->deleteComponent($sqlComponent)->willReturn(['success' => TRUE])->shouldBeCalledOnce();
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame('Portainer failed.', $result['error']);
    $this->assertSame(0, $stack->deleteCount);
    $this->assertSame([['target_id' => '6']], $stack->setValues['includedComponents']);
  }

  /**
   * Kept components stay in creation order.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   */
  public function testCreateStackKeepsAllUndeletableComponentsInCreationOrder(): void {
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $triplestoreComponent = $this->componentDouble('6', 'soda_scs_triplestore_component')->reveal();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())->willReturn($this->triplestoreSuccess($triplestoreComponent));
    $this->wisskiActions->createComponent(Argument::any())
      ->willReturn(['success' => FALSE, 'error' => 'Portainer failed.']);
    $this->triplestoreActions->deleteComponent($triplestoreComponent)
      ->willReturn(['success' => FALSE, 'error' => 'User not found.']);
    $this->sqlActions->deleteComponent($sqlComponent)
      ->willReturn(['success' => FALSE, 'error' => 'Portainer answered 500.']);
    $stack = $this->stack();

    $this->actions->createStack($stack);

    $this->assertSame(0, $stack->deleteCount);
    $this->assertSame([['target_id' => '5'], ['target_id' => '6']], $stack->setValues['includedComponents']);
  }

  /**
   * A component delete that throws counts as not deleted.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   */
  public function testCreateStackKeepsStackWhenComponentDeleteThrows(): void {
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())
      ->willReturn(['success' => FALSE, 'error' => 'Repository failed.']);
    $this->sqlActions->deleteComponent($sqlComponent)->willThrow(new \RuntimeException('Connection refused.'));
    $stack = $this->stack();

    $result = $this->actions->createStack($stack);

    $this->assertCompleteFailure($result);
    $this->assertSame('Repository failed.', $result['error']);
    $this->assertSame(0, $stack->deleteCount);
    $this->assertSame([['target_id' => '5']], $stack->setValues['includedComponents']);
  }

  /**
   * A component delete answer without success flag counts as not deleted.
   *
   * @covers ::createStack
   * @covers ::rollBackCreateStack
   */
  public function testCreateStackKeepsStackWhenComponentDeleteAnswersWithoutSuccess(): void {
    $sqlComponent = $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    $this->sqlActions->createComponent(Argument::any())->willReturn($this->sqlSuccess($sqlComponent));
    $this->triplestoreActions->createComponent(Argument::any())
      ->willReturn(['success' => FALSE, 'error' => 'Repository failed.']);
    $this->sqlActions->deleteComponent($sqlComponent)->willReturn([]);
    $stack = $this->stack();

    $this->actions->createStack($stack);

    $this->assertSame(0, $stack->deleteCount);
    $this->assertSame([['target_id' => '5']], $stack->setValues['includedComponents']);
  }

  /**
   * A stack without WissKI component deletes the Keycloak leftovers.
   *
   * The raw machine name is read first without a required client; a name with
   * the "stack-" prefix is read once more without it, with a required client.
   *
   * @param string $stackMachineName
   *   The machine name of the stack.
   * @param array $expectedCalls
   *   The expected client ID and requireClient flag, in order.
   *
   * @covers ::deleteStack
   * @covers ::deleteOrphanedKeycloakResources
   *
   * @dataProvider stackMachineNameProvider
   */
  public function testDeleteStackDeletesKeycloakResourcesOfStackWithoutWisski(string $stackMachineName, array $expectedCalls): void {
    $stack = $this->deletableStack();
    $stack->get('machineName')->willReturn($this->fieldList($stackMachineName));
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    foreach ($expectedCalls as [$clientId, $requireClient]) {
      $this->wisskiActions->deleteKeycloakResources($clientId, 'Foo', $requireClient)
        ->willReturn(SodaScsResult::success(['deleted' => ['client']], 'Deleted.'))
        ->shouldBeCalledOnce();
    }
    $this->wisskiActions->deleteKeycloakResources(Argument::cetera())->shouldBeCalledTimes(count($expectedCalls));
    $stack->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertTrue($result['success']);
    $this->assertNull($result['error']);
  }

  /**
   * Stack machine names and the Keycloak lookups they stand for.
   *
   * @return array<string, array>
   *   The stack machine name and the client ID / requireClient pairs.
   */
  public static function stackMachineNameProvider(): array {
    return [
      'stack prefix is also read without it' => [
        'stack-foo',
        [['wisski-stack-foo', FALSE], ['wisski-foo', TRUE]],
      ],
      'only the leading stack prefix is stripped' => [
        'stack-stack-foo',
        [['wisski-stack-stack-foo', FALSE], ['wisski-stack-foo', TRUE]],
      ],
      'machine name without prefix' => ['foo', [['wisski-foo', FALSE]]],
    ];
  }

  /**
   * A bare "stack-" name has nothing to strip, only the raw reading remains.
   *
   * @covers ::deleteOrphanedKeycloakResources
   */
  public function testDeleteStackReadsBareStackPrefixOnlyOnce(): void {
    $stack = $this->deletableStack();
    $stack->get('machineName')->willReturn($this->fieldList('stack-'));
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    $this->wisskiActions->deleteKeycloakResources('wisski-stack-', 'Foo', FALSE)
      ->willReturn(SodaScsResult::success(['deleted' => []], 'Nothing left.'))
      ->shouldBeCalledOnce();
    $this->wisskiActions->deleteKeycloakResources(Argument::cetera())->shouldBeCalledTimes(1);
    $stack->delete()->shouldBeCalledOnce();

    $this->assertTrue($this->actions->deleteStack($stack->reveal())['success']);
  }

  /**
   * An empty machine name looks for no client at all.
   *
   * @covers ::deleteOrphanedKeycloakResources
   */
  public function testDeleteStackLooksForNoKeycloakClientWhenMachineNameIsEmpty(): void {
    $stack = $this->deletableStack();
    $stack->get('machineName')->willReturn($this->fieldList(NULL));
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    $this->wisskiActions->deleteKeycloakResources(Argument::cetera())->shouldNotBeCalled();
    $stack->delete()->shouldBeCalledOnce();

    $this->assertTrue($this->actions->deleteStack($stack->reveal())['success']);
  }

  /**
   * Another WissKI stack with the name may be a creation in progress.
   *
   * @covers ::deleteStack
   * @covers ::deleteOrphanedKeycloakResources
   * @covers ::isWisskiNameInUse
   */
  public function testDeleteStackLeavesKeycloakResourcesWhenAnotherWisskiStackHasTheName(): void {
    $stack = $this->deletableStack();
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    // Both readings of "stack-foo" look for the raw and the prefixed name.
    $this->stackQuery->condition('machineName', ['stack-foo', 'stack-stack-foo'], 'IN')->willReturn($this->stackQuery->reveal())->shouldBeCalledOnce();
    $this->stackQuery->condition('machineName', ['foo', 'stack-foo'], 'IN')->willReturn($this->stackQuery->reveal())->shouldBeCalledOnce();
    $this->stackQuery->execute()->willReturn(['41' => '41']);
    $this->wisskiActions->deleteKeycloakResources(Argument::cetera())->shouldNotBeCalled();
    $stack->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertTrue($result['success']);
  }

  /**
   * The stack that is being deleted never counts as "another" stack.
   *
   * @covers ::isWisskiNameInUse
   */
  public function testDeleteStackExcludesItselfFromTheNameInUseLookup(): void {
    $stack = $this->deletableStack();
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    $this->stackQuery->condition('id', 40, '<>')->willReturn($this->stackQuery->reveal())->shouldBeCalled();
    $stack->delete()->shouldBeCalledOnce();

    $this->assertTrue($this->actions->deleteStack($stack->reveal())['success']);
  }

  /**
   * When the raw reading is in use, the stripped reading is still checked.
   *
   * @covers ::deleteOrphanedKeycloakResources
   */
  public function testDeleteStackSkipsOnlyTheReadingThatIsInUse(): void {
    $stack = $this->deletableStack();
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    $lastName = NULL;
    $query = $this->componentQuery->reveal();
    $this->componentQuery->condition('machineName', Argument::type('string'))->will(function (array $args) use (&$lastName, $query) {
      $lastName = $args[1];
      return $query;
    });
    $this->componentQuery->execute()->will(function () use (&$lastName) {
      return $lastName === 'wisski-stack-foo' ? ['9' => '9'] : [];
    });
    $this->wisskiActions->deleteKeycloakResources('wisski-foo', 'Foo', TRUE)
      ->willReturn(SodaScsResult::success(['deleted' => ['client']], 'Deleted.'))
      ->shouldBeCalledOnce();
    $this->wisskiActions->deleteKeycloakResources(Argument::cetera())->shouldBeCalledTimes(1);
    $stack->delete()->shouldBeCalledOnce();

    $this->assertTrue($this->actions->deleteStack($stack->reveal())['success']);
  }

  /**
   * An existing WissKI component with that name owns the client.
   *
   * @covers ::deleteStack
   * @covers ::deleteOrphanedKeycloakResources
   */
  public function testDeleteStackLeavesKeycloakResourcesOfExistingWisskiComponent(): void {
    $stack = $this->deletableStack();
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    $this->componentQuery->execute()->willReturn(['9' => '9']);
    $this->wisskiActions->deleteKeycloakResources(Argument::cetera())->shouldNotBeCalled();
    $stack->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertTrue($result['success']);
  }

  /**
   * A WissKI component in the stack deletes its Keycloak client itself.
   *
   * @covers ::deleteStack
   */
  public function testDeleteStackDoesNotLookForOrphansWhenStackHasWisskiComponent(): void {
    $stack = $this->deletableStack();
    $sql = $this->includedComponent($stack, 'soda_scs_sql_component');
    $triplestore = $this->includedComponent($stack, 'soda_scs_triplestore_component');
    $wisski = $this->includedComponent($stack, 'soda_scs_wisski_component');
    $this->sqlActions->deleteComponent($sql)->willReturn(['success' => TRUE]);
    $this->triplestoreActions->deleteComponent($triplestore)->willReturn(['success' => TRUE]);
    $this->wisskiActions->deleteComponent($wisski)->willReturn(['success' => TRUE]);
    $this->stackHelpers->removeIncludedComponentValue(Argument::cetera())->shouldBeCalledTimes(3);
    $this->componentStorage->getQuery()->shouldNotBeCalled();
    $this->wisskiActions->deleteKeycloakResources(Argument::cetera())->shouldNotBeCalled();
    $stack->delete()->shouldBeCalledOnce();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertTrue($result['success']);
  }

  /**
   * A failed Keycloak cleanup keeps the stack and skips the next reading.
   *
   * @covers ::deleteStack
   * @covers ::deleteOrphanedKeycloakResources
   */
  public function testDeleteStackKeepsStackWhenKeycloakCleanupFails(): void {
    $stack = $this->deletableStack();
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    $this->wisskiActions->deleteKeycloakResources('wisski-stack-foo', 'Foo', FALSE)->willReturn(SodaScsResult::failure(
      error: 'Keycloak client delete failed: Server error.',
      message: 'Could not delete the Keycloak client of the WissKI instance.',
      log: FALSE,
    ));
    $this->wisskiActions->deleteKeycloakResources('wisski-foo', Argument::cetera())->shouldNotBeCalled();
    $stack->delete()->shouldNotBeCalled();
    $this->logger->error(Argument::containingString('Kept WissKI stack'), Argument::withEntry('@stack', 40))->shouldBeCalledOnce();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('Keycloak client delete failed: Server error.', $result['error']);
    $this->assertSame('Could not delete the Keycloak client of the WissKI stack.', (string) $result['message']);
  }

  /**
   * A failure of the stripped reading keeps the stack as well.
   *
   * @covers ::deleteOrphanedKeycloakResources
   */
  public function testDeleteStackKeepsStackWhenKeycloakCleanupOfStrippedNameFails(): void {
    $stack = $this->deletableStack();
    $this->stackHelpers->retrieveIncludedComponent($stack, Argument::any())->willReturn(NULL);
    $this->wisskiActions->deleteKeycloakResources('wisski-foo', 'Foo', TRUE)->willReturn(SodaScsResult::failure(
      error: 'Keycloak group lookup of wisski-foo-admin failed: Server error.',
      message: 'Could not delete the Keycloak groups of the WissKI instance.',
      log: FALSE,
    ));
    $stack->delete()->shouldNotBeCalled();

    $result = $this->actions->deleteStack($stack->reveal());

    $this->assertFalse($result['success']);
    $this->assertSame('Keycloak group lookup of wisski-foo-admin failed: Server error.', $result['error']);
  }

  /**
   * A stack double for deleteStack().
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Prophecy\Prophecy\ObjectProphecy
   *   The stack prophecy.
   */
  protected function deletableStack() {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->id()->willReturn(40);
    $stack->get('machineName')->willReturn($this->fieldList('stack-foo'));
    $stack->get('label')->willReturn($this->fieldList('Foo'));
    return $stack;
  }

  /**
   * A component of a bundle that the stack helpers find in the stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Prophecy\Prophecy\ObjectProphecy $stack
   *   The stack prophecy.
   * @param string $bundle
   *   The component bundle.
   */
  protected function includedComponent($stack, string $bundle): SodaScsComponentInterface {
    $component = $this->prophesize(SodaScsComponentInterface::class)->reveal();
    $this->stackHelpers->retrieveIncludedComponent($stack, $bundle)->willReturn($component);
    return $component;
  }

  /**
   * A component double as the component actions return it.
   *
   * @param string $id
   *   The component ID.
   * @param string $bundle
   *   The component bundle.
   *
   * @return \Prophecy\Prophecy\ObjectProphecy
   *   The component prophecy.
   */
  protected function componentDouble(string $id, string $bundle) {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->id()->willReturn($id);
    $component->bundle()->willReturn($bundle);
    $component->isNew()->willReturn(FALSE);
    $component->set(Argument::cetera())->willReturn($component->reveal());
    $component->save()->willReturn(1);
    return $component;
  }

  /**
   * A successful SQL step.
   */
  protected function sqlSuccess(?SodaScsComponentInterface $component = NULL): array {
    $component ??= $this->componentDouble('5', 'soda_scs_sql_component')->reveal();
    return ['success' => TRUE, 'error' => FALSE, 'data' => ['sqlComponent' => $component]];
  }

  /**
   * A successful triplestore step.
   */
  protected function triplestoreSuccess(?SodaScsComponentInterface $component = NULL): array {
    $component ??= $this->componentDouble('6', 'soda_scs_triplestore_component')->reveal();
    return ['success' => TRUE, 'error' => NULL, 'data' => ['triplestoreComponent' => $component]];
  }

  /**
   * A successful WissKI step.
   */
  protected function wisskiSuccess(?SodaScsComponentInterface $component = NULL): array {
    $component ??= $this->componentDouble('7', 'soda_scs_wisski_component')->reveal();
    return ['success' => TRUE, 'error' => NULL, 'data' => ['wisskiComponent' => $component]];
  }

  /**
   * Builds the stack the form hands to createStack().
   *
   * A real entity class without constructor: createStack() calls the static
   * SodaScsStack::setValue() through the instance, which a Prophecy double
   * cannot take.
   *
   * @param array $overrides
   *   Field values by field name that replace the defaults.
   */
  protected function stack(array $overrides = []): SodaScsStack {
    $fields = [];
    foreach ([
      'developmentInstance' => 0,
      'label' => 'Foo',
      'machineName' => 'foo',
      'automatedUpdates' => 0,
      'defaultLanguage' => 'en',
      'flavours' => NULL,
      'version' => '',
      'withDefaultDataModel' => NULL,
    ] as $name => $value) {
      $fields[$name] = $this->fieldList(array_key_exists($name, $overrides) ? $overrides[$name] : $value);
    }
    $fields['partOfProjects'] = $this->fieldList();

    return new class($fields) extends SodaScsStack {

      /**
       * How often delete() ran.
       */
      public int $deleteCount = 0;

      /**
       * How often save() ran.
       */
      public int $saveCount = 0;

      /**
       * The values set on the stack, by field name.
       */
      public array $setValues = [];

      /**
       * Constructs the stack without the entity machinery.
       */
      public function __construct(protected array $fieldStubs) {}

      /**
       * {@inheritdoc}
       */
      public function delete() {
        $this->deleteCount++;
      }

      /**
       * {@inheritdoc}
       */
      public function get($field_name) {
        return $this->fieldStubs[$field_name];
      }

      /**
       * {@inheritdoc}
       */
      public function id() {
        return 40;
      }

      /**
       * {@inheritdoc}
       */
      public function set($name, $value, $notify = TRUE) {
        $this->setValues[$name] = $value;
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
        $this->saveCount++;
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
