<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Service\SodaScsWisskiTriplestoreTokenSync;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The token of the triplestore owner is written into the WissKI adapter.
 *
 * Issue 3639291: OpenGDB issues one token per account, so a recreated account
 * or an imported database leaves the Salz adapter with a token that OpenGDB
 * rejects. The drush exec in the WissKI container is a double.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\SodaScsWisskiTriplestoreTokenSync
 * @group soda_scs_manager
 */
class SodaScsWisskiTriplestoreTokenSyncTest extends UnitTestCase {

  /**
   * Token that must reach the exec only through its environment.
   */
  private const TOKEN = 'not-a-real-token';

  /**
   * The container helpers double.
   */
  private SodaScsContainerHelpers&MockObject $containerHelpers;

  /**
   * The service key actions double.
   */
  private SodaScsServiceKeyActionsInterface&MockObject $serviceKeyActions;

  /**
   * The logger channel double.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * The component storage double.
   */
  private EntityStorageInterface&MockObject $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $this->containerHelpers->method('getComponentContainerName')->willReturn('wisski-demo--drupal');
    $this->serviceKeyActions = $this->createMock(SodaScsServiceKeyActionsInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->storage = $this->createMock(EntityStorageInterface::class);

    // Failure results log through \Drupal::logger().
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * An owner without a token service key fails before any exec.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsFailsWithoutTokenServiceKey(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn(NULL);
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertFalse($result->success);
    $this->assertSame('The triplestore owner has no token.', $result->message);
    $this->assertSame('No triplestore token service key for user 7.', $result->error);
  }

  /**
   * A token service key with an empty token fails before any exec.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsFailsWithEmptyToken(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(''));
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertFalse($result->success);
    $this->assertSame('The triplestore owner has no token.', $result->message);
  }

  /**
   * The key of the triplestore owner is looked up by bundle, type and user.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsLooksUpTheTokenOfTheTriplestoreOwner(): void {
    $this->serviceKeyActions->expects($this->once())->method('getServiceKey')
      ->with(['bundle' => 'soda_scs_triplestore_component', 'type' => 'token', 'userId' => 7])
      ->willReturn(NULL);

    $this->sync()->syncComponents($this->wisski(), $this->triplestore());
  }

  /**
   * The token and the repository travel in the environment, not the command.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsPassesTokenThroughEnvironmentNotCommand(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->expects($this->once())->method('executeDockerExecCommand')
      ->with($this->callback(function (array $request): bool {
        $this->assertSame('wisski-demo--drupal', $request['containerName']);
        $this->assertSame('www-data', $request['user']);
        $this->assertSame(['drush', 'php:eval'], array_slice($request['cmd'], 0, 2));
        $this->assertCount(3, $request['cmd']);
        $this->assertStringNotContainsString(self::TOKEN, implode(' ', $request['cmd']));
        $this->assertStringContainsString('wisski_salz.wisski_salz_adapter.default', $request['cmd'][2]);
        $this->assertSame(['SCS_TS_REPOSITORY=ts-demo', 'SCS_TS_TOKEN=' . self::TOKEN], $request['env']);
        return TRUE;
      }))
      ->willReturn($this->drushOutput('SCS_TOKEN_SYNC:updated'));

    $this->sync()->syncComponents($this->wisski(), $this->triplestore());
  }

  /**
   * The script sets the Django token header value and switches token auth on.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsScriptWritesTokenWithPrefix(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->expects($this->once())->method('executeDockerExecCommand')
      ->with($this->callback(function (array $request): bool {
        $script = $request['cmd'][2];
        $this->assertStringContainsString('"Token " . getenv("SCS_TS_TOKEN")', $script);
        $this->assertStringContainsString('->set("engine.token", $token)->set("engine.use_token", TRUE)->save();', $script);
        return TRUE;
      }))
      ->willReturn($this->drushOutput('SCS_TOKEN_SYNC:updated'));

    $this->sync()->syncComponents($this->wisski(), $this->triplestore());
  }

  /**
   * The marker "updated" is a success with state updated, logged as notice.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsMapsUpdatedMarkerToState(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('SCS_TOKEN_SYNC:updated'));
    $this->logger->expects($this->once())->method('notice')
      ->with($this->stringContains('Updated the triplestore token'), ['@wisski' => 12]);

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertTrue($result->success);
    $this->assertSame(['state' => 'updated'], $result->data);
  }

  /**
   * The marker "unchanged" is a success with state unchanged and no log entry.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsMapsUnchangedMarkerToState(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('SCS_TOKEN_SYNC:unchanged'));
    $this->logger->expects($this->never())->method('notice');

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertTrue($result->success);
    $this->assertSame(['state' => 'unchanged'], $result->data);
  }

  /**
   * An adapter that reads another repository is a success and stays as it is.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsMapsOtherRepositoryMarkerToState(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('SCS_TOKEN_SYNC:other repository'));
    $this->logger->expects($this->never())->method('notice');

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertTrue($result->success);
    $this->assertSame(['state' => 'other repository'], $result->data);
  }

  /**
   * The marker is found between the notices that drush prints around it.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsFindsMarkerAmongDrushNotices(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn($this->drushOutput("Deprecated: something\nSCS_TOKEN_SYNC:unchanged\nNotice: more"));

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertTrue($result->success);
    $this->assertSame('unchanged', $result->data['state']);
  }

  /**
   * A failing drush returns the failure and does not claim a state.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsFailsWhenDrushFails(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, ['output' => 'SCS_TOKEN_SYNC:updated'], 'drush exited with 1', 'failed'));
    $this->logger->expects($this->never())->method('notice');

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertFalse($result->success);
    $this->assertSame('Could not update the triplestore token of the WissKI.', $result->message);
    $this->assertSame('drush exited with 1', $result->error);
  }

  /**
   * A failing exec without an error text still carries an error.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsFailsWithDefaultErrorWhenDrushFailsWithoutText(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, NULL, NULL, 'failed'));

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertFalse($result->success);
    $this->assertSame('drush failed', $result->error);
  }

  /**
   * Output without the marker is a failure, however the exec ended.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsFailsWhenMarkerIsMissing(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('Some warning only'));
    $this->logger->expects($this->never())->method('notice');

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertFalse($result->success);
    $this->assertSame('Missing result in drush output.', $result->error);
  }

  /**
   * A successful exec without output data is a failure, not a state.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsFailsWhenOutputIsMissing(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn(SodaScsResult::success([], 'ok'));

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertFalse($result->success);
    $this->assertSame('Missing result in drush output.', $result->error);
  }

  /**
   * An unknown state in the marker is not taken as a success.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsFailsOnUnknownMarkerState(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('SCS_TOKEN_SYNC:exploded'));

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertFalse($result->success);
    $this->assertSame('Missing result in drush output.', $result->error);
  }

  /**
   * The token reaches neither the log nor the failure messages.
   *
   * @covers ::syncComponents
   */
  public function testSyncComponentsKeepsTokenOutOfLogAndFailure(): void {
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('no marker'));
    $this->logger->expects($this->never())->method($this->anything());

    $result = $this->sync()->syncComponents($this->wisski(), $this->triplestore());

    $this->assertStringNotContainsString(self::TOKEN, (string) $result->error . $result->message);
  }

  /**
   * Every triplestore of the user with a stack WissKI is synced and counted.
   *
   * @covers ::syncUser
   */
  public function testSyncUserCountsSuccessfulSyncs(): void {
    $this->useTriplestores([
      1 => $this->triplestore('ts-one', $this->stackWith([$this->wisski(11)])),
      2 => $this->triplestore('ts-two', $this->stackWith([$this->wisski(12)])),
    ]);
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $repositories = [];
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturnCallback(function (array $request) use (&$repositories): SodaScsResult {
        $repositories[] = $request['env'][0];
        return $this->drushOutput('SCS_TOKEN_SYNC:unchanged');
      });
    $this->logger->expects($this->never())->method('warning');

    $this->assertSame(2, $this->sync()->syncUser(7));
    $this->assertSame(['SCS_TS_REPOSITORY=ts-one', 'SCS_TS_REPOSITORY=ts-two'], $repositories);
  }

  /**
   * Only the triplestores of the given user are queried.
   *
   * @covers ::syncUser
   */
  public function testSyncUserQueriesTriplestoresOfTheUser(): void {
    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(function (string $field, $value) use ($query, &$conditions): QueryInterface {
      $conditions[$field] = $value;
      return $query;
    });
    $query->method('execute')->willReturn([]);
    $this->storage->method('getQuery')->willReturn($query);
    $this->storage->method('loadMultiple')->with([])->willReturn([]);

    $this->assertSame(0, $this->sync()->syncUser(9));
    $this->assertSame(['bundle' => 'soda_scs_triplestore_component', 'owner' => 9], $conditions);
  }

  /**
   * A triplestore without a stack is skipped; no exec runs for it.
   *
   * @covers ::syncUser
   */
  public function testSyncUserSkipsTriplestoreWithoutStack(): void {
    $this->useTriplestores([1 => $this->triplestore('ts-one', NULL)]);
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $this->assertSame(0, $this->sync()->syncUser(7));
  }

  /**
   * A stack without a WissKI component is skipped.
   *
   * @covers ::syncUser
   */
  public function testSyncUserSkipsStackWithoutWisski(): void {
    $sql = $this->createMock(SodaScsComponentInterface::class);
    $sql->method('bundle')->willReturn('soda_scs_sql_component');
    $this->useTriplestores([1 => $this->triplestore('ts-one', $this->stackWith([$sql]))]);
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $this->assertSame(0, $this->sync()->syncUser(7));
  }

  /**
   * The WissKI is picked from the other components of the stack.
   *
   * @covers ::syncUser
   */
  public function testSyncUserPicksTheWisskiComponentOfTheStack(): void {
    $sql = $this->createMock(SodaScsComponentInterface::class);
    $sql->method('bundle')->willReturn('soda_scs_sql_component');
    $this->useTriplestores([1 => $this->triplestore('ts-one', $this->stackWith([$sql, $this->wisski(12)]))]);
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->expects($this->once())->method('getComponentContainerName')
      ->with($this->callback(static fn (SodaScsComponentInterface $component): bool => $component->id() === 12))
      ->willReturn('wisski-demo--drupal');
    $this->containerHelpers->method('executeDockerExecCommand')->willReturn($this->drushOutput('SCS_TOKEN_SYNC:updated'));

    $this->assertSame(1, $this->sync()->syncUser(7));
  }

  /**
   * A failing WissKI is logged as a warning and the others are still synced.
   *
   * @covers ::syncUser
   */
  public function testSyncUserLogsWarningOnFailureAndContinues(): void {
    $this->useTriplestores([
      1 => $this->triplestore('ts-one', $this->stackWith([$this->wisski(11)])),
      2 => $this->triplestore('ts-two', $this->stackWith([$this->wisski(12)])),
    ]);
    $this->serviceKeyActions->method('getServiceKey')->willReturn($this->tokenKey(self::TOKEN));
    $this->containerHelpers->method('executeDockerExecCommand')->willReturnOnConsecutiveCalls(
      new SodaScsResult(FALSE, NULL, 'container is not running', 'failed'),
      $this->drushOutput('SCS_TOKEN_SYNC:updated'),
    );
    $this->logger->expects($this->once())->method('warning')->with(
      $this->stringContains('Could not update the triplestore token of WissKI'),
      ['@error' => 'container is not running', '@wisski' => 11],
    );

    $this->assertSame(1, $this->sync()->syncUser(7));
  }

  /**
   * A user without triplestores syncs nothing and executes nothing.
   *
   * @covers ::syncUser
   */
  public function testSyncUserWithoutTriplestoresDoesNothing(): void {
    $this->useTriplestores([]);
    $this->containerHelpers->expects($this->never())->method('executeDockerExecCommand');

    $this->assertSame(0, $this->sync()->syncUser(7));
  }

  /**
   * Builds the service under test.
   */
  private function sync(): SodaScsWisskiTriplestoreTokenSync {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('soda_scs_component')->willReturn($this->storage);
    return new SodaScsWisskiTriplestoreTokenSync(
      $this->logger,
      $this->containerHelpers,
      $this->serviceKeyActions,
      $entityTypeManager,
    );
  }

  /**
   * Feeds the component storage with triplestores keyed by ID.
   *
   * @param array<int, \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface> $triplestores
   *   The components the user owns.
   */
  private function useTriplestores(array $triplestores): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine(array_keys($triplestores), array_keys($triplestores)));
    $this->storage->method('getQuery')->willReturn($query);
    $this->storage->method('loadMultiple')->willReturn($triplestores);
  }

  /**
   * A drush answer with the given output.
   */
  private function drushOutput(string $output): SodaScsResult {
    return SodaScsResult::success(['output' => $output], 'ok');
  }

  /**
   * Builds a token service key.
   */
  private function tokenKey(string $token): SodaScsServiceKeyInterface {
    $field = $this->createMock(FieldItemList::class);
    $field->method('__get')->with('value')->willReturn($token);
    $key = $this->createMock(SodaScsServiceKeyInterface::class);
    $key->method('get')->with('servicePassword')->willReturn($field);
    return $key;
  }

  /**
   * Builds the triplestore component of the user 7.
   */
  private function triplestore(string $machineName = 'ts-demo', ?SodaScsStackInterface $stack = NULL): SodaScsComponentInterface {
    $field = $this->createMock(FieldItemList::class);
    $field->method('__get')->with('value')->willReturn($machineName);
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('bundle')->willReturn('soda_scs_triplestore_component');
    $component->method('getOwnerId')->willReturn(7);
    $component->method('get')->with('machineName')->willReturn($field);
    $component->method('getPartOfStack')->willReturn($stack);
    return $component;
  }

  /**
   * Builds a WissKI component.
   */
  private function wisski(int $id = 12): SodaScsComponentInterface {
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('bundle')->willReturn('soda_scs_wisski_component');
    $component->method('id')->willReturn($id);
    return $component;
  }

  /**
   * Builds a stack that includes the given components.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[] $components
   *   The included components.
   */
  private function stackWith(array $components): SodaScsStackInterface {
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('referencedEntities')->willReturn($components);
    $stack = $this->createMock(SodaScsStackInterface::class);
    $stack->method('get')->with('includedComponents')->willReturn($field);
    return $stack;
  }

}
