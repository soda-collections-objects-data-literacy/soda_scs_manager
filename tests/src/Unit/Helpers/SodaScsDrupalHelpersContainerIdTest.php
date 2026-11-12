<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsWisskiComponentActions;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * The container ID the Docker exec commands of SodaScsDrupalHelpers use.
 *
 * A WissKI whose container started after the create request has no stored
 * container ID; the helper then syncs it instead of exec-ing into an empty
 * container name.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers
 * @group soda_scs_manager
 */
class SodaScsDrupalHelpersContainerIdTest extends UnitTestCase {

  use SodaScsCreatePathTestTrait;

  /**
   * The mocked container helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $containerHelpers;

  /**
   * The helper under test.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers
   */
  protected SodaScsDrupalHelpers $helper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The failure results log through \Drupal::logger().
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);

    $componentHelpers = $this->createMock(SodaScsComponentHelpers::class);
    $componentHelpers->method('drupalHealthCheck')
      ->willReturn(['success' => TRUE, 'status' => 'running']);
    $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);

    $this->helper = new SodaScsDrupalHelpers(
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $loggerFactory,
      $this->createMock(SodaScsDatabaseHelpers::class),
      $componentHelpers,
      $this->containerHelpers,
      $this->createMock(SodaScsServiceHelpers::class),
      $this->createMock(SodaScsServiceKeyActionsInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(SodaScsHelpers::class),
      $this->createMock(SodaScsProgressHelper::class),
      $this->createMock(SodaScsWisskiComponentActions::class),
      $this->createMock(SodaScsSnapshotHelpers::class),
    );
    $this->helper->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * An empty stored container ID is synced and the synced ID is used.
   *
   * @covers ::setDrupalMaintainmentMode
   * @covers ::resolveContainerId
   */
  public function testEmptyContainerIdIsSyncedAndUsedAsContainerName(): void {
    $component = $this->component('');
    $this->containerHelpers->expects($this->once())
      ->method('syncComponentContainerId')
      ->with($component)
      ->willReturn(SodaScsResult::success(['containerId' => 'synced123'], 'Refreshed.'));
    $this->containerHelpers->expects($this->once())
      ->method('executeDockerExecCommand')
      ->with($this->callback(fn(array $params) => $params['containerName'] === 'synced123'))
      ->willReturn(SodaScsResult::success(['output' => ''], 'Done.'));

    $result = $this->helper->setDrupalMaintainmentMode($component, TRUE);

    $this->assertTrue($result->success);
  }

  /**
   * A NULL stored container ID is treated like an empty one.
   *
   * @covers ::setDrupalMaintainmentMode
   * @covers ::resolveContainerId
   */
  public function testNullContainerIdIsSynced(): void {
    $component = $this->component(NULL);
    $this->containerHelpers->expects($this->once())
      ->method('syncComponentContainerId')
      ->willReturn(SodaScsResult::success(['containerId' => 'synced123'], 'Refreshed.'));
    $this->containerHelpers->expects($this->once())
      ->method('executeDockerExecCommand')
      ->with($this->callback(fn(array $params) => $params['containerName'] === 'synced123'))
      ->willReturn(SodaScsResult::success(['output' => ''], 'Done.'));

    $this->assertTrue($this->helper->setDrupalMaintainmentMode($component, FALSE)->success);
  }

  /**
   * A stored container ID is used as it is; nothing is synced.
   *
   * @covers ::setDrupalMaintainmentMode
   * @covers ::resolveContainerId
   */
  public function testStoredContainerIdIsUsedWithoutSync(): void {
    $this->containerHelpers->expects($this->never())->method('syncComponentContainerId');
    $this->containerHelpers->expects($this->once())
      ->method('executeDockerExecCommand')
      ->with($this->callback(fn(array $params) => $params['containerName'] === 'stored456'))
      ->willReturn(SodaScsResult::success(['output' => ''], 'Done.'));

    $result = $this->helper->setDrupalMaintainmentMode($this->component('stored456'), TRUE);

    $this->assertTrue($result->success);
  }

  /**
   * A failed sync leaves the container name empty and the exec fails.
   *
   * The exec result decides: the empty name is passed on, never a stale or
   * made-up ID.
   *
   * @covers ::setDrupalMaintainmentMode
   * @covers ::resolveContainerId
   */
  public function testFailedSyncPassesEmptyContainerName(): void {
    $this->containerHelpers->expects($this->once())
      ->method('syncComponentContainerId')
      ->willReturn(SodaScsResult::failure(
        error: 'Container not found for name: wisski-foo--drupal',
        message: 'WissKI container not found.',
        log: FALSE,
      ));
    $this->containerHelpers->expects($this->once())
      ->method('executeDockerExecCommand')
      ->with($this->callback(fn(array $params) => $params['containerName'] === ''))
      ->willReturn(SodaScsResult::failure(error: 'No container name.', message: 'Exec failed.', log: FALSE));

    $result = $this->helper->setDrupalMaintainmentMode($this->component(''), TRUE);

    $this->assertFalse($result->success);
  }

  /**
   * A sync that answers without a container ID leaves the name empty.
   *
   * @covers ::setDrupalMaintainmentMode
   * @covers ::resolveContainerId
   */
  public function testSyncWithoutContainerIdInDataPassesEmptyContainerName(): void {
    $this->containerHelpers->expects($this->once())
      ->method('syncComponentContainerId')
      ->willReturn(SodaScsResult::success([], 'Refreshed.'));
    $this->containerHelpers->expects($this->once())
      ->method('executeDockerExecCommand')
      ->with($this->callback(fn(array $params) => $params['containerName'] === ''))
      ->willReturn(SodaScsResult::failure(error: 'No container name.', message: 'Exec failed.', log: FALSE));

    $this->assertFalse($this->helper->setDrupalMaintainmentMode($this->component(''), TRUE)->success);
  }

  /**
   * The installed packages command uses the synced container ID as well.
   *
   * @covers ::getInstalledDrupalPackages
   * @covers ::resolveContainerId
   */
  public function testInstalledPackagesUseSyncedContainerId(): void {
    $this->containerHelpers->expects($this->once())
      ->method('syncComponentContainerId')
      ->willReturn(SodaScsResult::success(['containerId' => 'synced123'], 'Refreshed.'));
    $this->containerHelpers->expects($this->once())
      ->method('executeDockerExecCommand')
      ->with($this->callback(fn(array $params) => $params['containerName'] === 'synced123'))
      ->willReturn(SodaScsResult::failure(error: 'Stop here.', message: 'Exec failed.', log: FALSE));

    $this->assertFalse($this->helper->getInstalledDrupalPackages($this->component(''))->success);
  }

  /**
   * Builds a component with the given stored container ID.
   *
   * @param string|null $containerId
   *   The stored container ID.
   */
  protected function component(?string $containerId): SodaScsComponentInterface {
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('get')->willReturnCallback(
      fn(string $name) => $this->fieldList($name === 'containerId' ? $containerId : NULL)
    );
    $component->method('id')->willReturn(5);
    return $component;
  }

}
