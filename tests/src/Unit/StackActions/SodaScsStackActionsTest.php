<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\StackActions;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActions;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests the SodaScsStackActions orchestration service.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\StackActions\SodaScsStackActions
 */
class SodaScsStackActionsTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActions
   */
  protected SodaScsStackActions $stackActions;

  /**
   * The mocked Jupyter stack actions.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $jupyterStackActions;

  /**
   * The mocked Nextcloud stack actions.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $nextcloudStackActions;

  /**
   * The mocked WissKI stack actions.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $wisskiStackActions;

  /**
   * The mocked string translation.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $stringTranslation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create mock objects.
    $this->jupyterStackActions = $this->prophesize(SodaScsStackActionsInterface::class);
    $this->nextcloudStackActions = $this->prophesize(SodaScsStackActionsInterface::class);
    $this->wisskiStackActions = $this->prophesize(SodaScsStackActionsInterface::class);
    $this->stringTranslation = $this->prophesize(TranslationInterface::class);

    // Create the service instance.
    $this->stackActions = new SodaScsStackActions(
      $this->jupyterStackActions->reveal(),
      $this->nextcloudStackActions->reveal(),
      $this->wisskiStackActions->reveal(),
      $this->stringTranslation->reveal()
    );
  }

  /**
   * Tests createStack delegates to WissKI stack actions.
   *
   * @covers ::createStack
   */
  public function testCreateStackWisski(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('soda_scs_wisski_stack');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'WissKI stack created',
      'data' => [],
    ];

    $this->wisskiStackActions
      ->createStack($stack->reveal())
      ->willReturn($expectedResult);

    $result = $this->stackActions->createStack($stack->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests createStack delegates to Jupyter stack actions.
   *
   * @covers ::createStack
   */
  public function testCreateStackJupyter(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('soda_scs_jupyter_stack');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Jupyter stack created',
      'data' => [],
    ];

    $this->jupyterStackActions
      ->createStack($stack->reveal())
      ->willReturn($expectedResult);

    $result = $this->stackActions->createStack($stack->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests createStack delegates to Nextcloud stack actions.
   *
   * @covers ::createStack
   */
  public function testCreateStackNextcloud(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('soda_scs_nextcloud_stack');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Nextcloud stack created',
      'data' => [],
    ];

    $this->nextcloudStackActions
      ->createStack($stack->reveal())
      ->willReturn($expectedResult);

    $result = $this->stackActions->createStack($stack->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests createStack throws exception for unknown bundle.
   *
   * @covers ::createStack
   */
  public function testCreateStackUnknownBundle(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('unknown_stack');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Stack type not supported for creation.');

    $this->stackActions->createStack($stack->reveal());
  }

  /**
   * Tests deleteStack delegates to WissKI stack actions.
   *
   * @covers ::deleteStack
   */
  public function testDeleteStackWisski(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('soda_scs_wisski_stack');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'WissKI stack deleted',
      'data' => [],
      'error' => NULL,
    ];

    $this->wisskiStackActions
      ->deleteStack($stack->reveal())
      ->willReturn($expectedResult);

    $result = $this->stackActions->deleteStack($stack->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests deleteStack delegates to Jupyter stack actions.
   *
   * @covers ::deleteStack
   */
  public function testDeleteStackJupyter(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('soda_scs_jupyter_stack');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Jupyter stack deleted',
      'data' => [],
      'error' => NULL,
    ];

    $this->jupyterStackActions
      ->deleteStack($stack->reveal())
      ->willReturn($expectedResult);

    $result = $this->stackActions->deleteStack($stack->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests deleteStack delegates to Nextcloud stack actions.
   *
   * @covers ::deleteStack
   */
  public function testDeleteStackNextcloud(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('soda_scs_nextcloud_stack');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Nextcloud stack deleted',
      'data' => [],
      'error' => NULL,
    ];

    $this->nextcloudStackActions
      ->deleteStack($stack->reveal())
      ->willReturn($expectedResult);

    $result = $this->stackActions->deleteStack($stack->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests deleteStack throws exception for unknown bundle.
   *
   * @covers ::deleteStack
   */
  public function testDeleteStackUnknownBundle(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('unknown_stack');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Component type not supported.');

    $this->stackActions->deleteStack($stack->reveal());
  }

  /**
   * Tests createSnapshot delegates to WissKI stack actions.
   *
   * @covers ::createSnapshot
   */
  public function testCreateSnapshotWisski(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('soda_scs_wisski_stack');

    $snapshotMachineName = 'test-snapshot';
    $timestamp = time();

    $expectedResult = SodaScsResult::success(
      message: 'Snapshot created',
      data: []
    );

    $this->wisskiStackActions
      ->createSnapshot($stack->reveal(), $snapshotMachineName, $timestamp)
      ->willReturn($expectedResult);

    $result = $this->stackActions->createSnapshot($stack->reveal(), $snapshotMachineName, $timestamp);

    $this->assertTrue($result->success);
  }

  /**
   * Tests createSnapshot delegates to Jupyter stack actions.
   *
   * @covers ::createSnapshot
   */
  public function testCreateSnapshotJupyter(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('soda_scs_jupyter_stack');

    $snapshotMachineName = 'test-snapshot';
    $timestamp = time();

    $expectedResult = SodaScsResult::success(
      message: 'Snapshot created',
      data: []
    );

    $this->jupyterStackActions
      ->createSnapshot($stack->reveal(), $snapshotMachineName, $timestamp)
      ->willReturn($expectedResult);

    $result = $this->stackActions->createSnapshot($stack->reveal(), $snapshotMachineName, $timestamp);

    $this->assertTrue($result->success);
  }

  /**
   * Tests createSnapshot throws exception for unknown bundle.
   *
   * @covers ::createSnapshot
   */
  public function testCreateSnapshotUnknownBundle(): void {
    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->bundle()->willReturn('unknown_stack');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Component type not supported for snapshot creation.');

    $this->stackActions->createSnapshot($stack->reveal(), 'test', time());
  }

  /**
   * Tests getStacks delegates to WissKI stack actions.
   *
   * @covers ::getStacks
   */
  public function testGetStacksWisski(): void {
    $expectedResult = [
      'success' => TRUE,
      'data' => [],
    ];

    $this->wisskiStackActions
      ->getStacks('soda_scs_wisski_stack', [])
      ->willReturn($expectedResult);

    $result = $this->stackActions->getStacks('soda_scs_wisski_stack', []);

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests getStacks throws exception for unknown bundle.
   *
   * @covers ::getStacks
   */
  public function testGetStacksUnknownBundle(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Component type not supported.');

    $this->stackActions->getStacks('unknown_bundle', []);
  }

  /**
   * Tests getStack delegates to WissKI stack actions.
   *
   * @covers ::getStack
   */
  public function testGetStackWisski(): void {
    $component = $this->prophesize(SodaScsStackInterface::class);
    $component->bundle()->willReturn('soda_scs_wisski_stack');

    $expectedResult = [
      'success' => TRUE,
      'data' => [],
    ];

    $this->wisskiStackActions
      ->getStack($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->stackActions->getStack($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests getStack throws exception for unknown bundle.
   *
   * @covers ::getStack
   */
  public function testGetStackUnknownBundle(): void {
    $component = $this->prophesize(SodaScsStackInterface::class);
    $component->bundle()->willReturn('unknown_bundle');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Component type not supported.');

    $this->stackActions->getStack($component->reveal());
  }

  /**
   * Tests updateStack delegates to WissKI stack actions.
   *
   * @covers ::updateStack
   */
  public function testUpdateStackWisski(): void {
    $component = $this->prophesize(SodaScsStackInterface::class);
    $component->bundle()->willReturn('soda_scs_wisski_stack');

    $expectedResult = [
      'success' => TRUE,
      'data' => [],
    ];

    $this->wisskiStackActions
      ->updateStack($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->stackActions->updateStack($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests updateStack throws exception for unknown bundle.
   *
   * @covers ::updateStack
   */
  public function testUpdateStackUnknownBundle(): void {
    $component = $this->prophesize(SodaScsStackInterface::class);
    $component->bundle()->willReturn('unknown_bundle');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Component type not supported.');

    $this->stackActions->updateStack($component->reveal());
  }

}
