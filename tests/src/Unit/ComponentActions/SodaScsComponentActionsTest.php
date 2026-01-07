<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\ComponentActions;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActions;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\Tests\UnitTestCase;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests the SodaScsComponentActions orchestration service.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActions
 */
class SodaScsComponentActionsTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActions
   */
  protected SodaScsComponentActions $componentActions;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $entityTypeManager;

  /**
   * The mocked filesystem component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $filesystemComponentActions;

  /**
   * The mocked SQL component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $sqlComponentActions;

  /**
   * The mocked triplestore component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $triplestoreComponentActions;

  /**
   * The mocked WissKI component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $wisskiComponentActions;

  /**
   * The mocked WebProtege component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $webprotegeComponentActions;

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
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->filesystemComponentActions = $this->prophesize(SodaScsComponentActionsInterface::class);
    $this->sqlComponentActions = $this->prophesize(SodaScsComponentActionsInterface::class);
    $this->triplestoreComponentActions = $this->prophesize(SodaScsComponentActionsInterface::class);
    $this->wisskiComponentActions = $this->prophesize(SodaScsComponentActionsInterface::class);
    $this->webprotegeComponentActions = $this->prophesize(SodaScsComponentActionsInterface::class);
    $this->stringTranslation = $this->prophesize(TranslationInterface::class);

    // Create the service instance.
    $this->componentActions = new SodaScsComponentActions(
      $this->entityTypeManager->reveal(),
      $this->filesystemComponentActions->reveal(),
      $this->sqlComponentActions->reveal(),
      $this->triplestoreComponentActions->reveal(),
      $this->wisskiComponentActions->reveal(),
      $this->webprotegeComponentActions->reveal(),
      $this->stringTranslation->reveal()
    );
  }

  /**
   * Tests createComponent delegates to filesystem component actions.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentFilesystem(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('soda_scs_filesystem_component');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Filesystem component created',
      'data' => ['filesystemComponent' => $component->reveal()],
    ];

    $this->filesystemComponentActions
      ->createComponent($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->componentActions->createComponent($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests createComponent delegates to SQL component actions.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentSql(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('soda_scs_sql_component');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'SQL component created',
      'data' => ['sqlComponent' => $component->reveal()],
    ];

    $this->sqlComponentActions
      ->createComponent($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->componentActions->createComponent($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests createComponent delegates to triplestore component actions.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentTriplestore(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('soda_scs_triplestore_component');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Triplestore component created',
      'data' => ['triplestoreComponent' => $component->reveal()],
    ];

    $this->triplestoreComponentActions
      ->createComponent($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->componentActions->createComponent($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests createComponent delegates to WissKI component actions.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentWisski(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('soda_scs_wisski_component');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'WissKI component created',
      'data' => ['wisskiComponent' => $component->reveal()],
    ];

    $this->wisskiComponentActions
      ->createComponent($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->componentActions->createComponent($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests createComponent delegates to WebProtege component actions.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentWebprotege(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('soda_scs_webprotege_component');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'WebProtege component created',
      'data' => ['webprotegeComponent' => $component->reveal()],
    ];

    $this->webprotegeComponentActions
      ->createComponent($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->componentActions->createComponent($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests createComponent returns empty for unknown bundle.
   *
   * @covers ::createComponent
   */
  public function testCreateComponentUnknownBundle(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('unknown_bundle');

    $result = $this->componentActions->createComponent($component->reveal());

    $this->assertEquals([], $result);
  }

  /**
   * Tests deleteComponent delegates to correct actions based on bundle.
   *
   * @covers ::deleteComponent
   * @dataProvider componentBundleProvider
   */
  public function testDeleteComponent(string $bundle, string $expectedService): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn($bundle);

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Component deleted',
      'data' => [],
      'error' => NULL,
    ];

    // Set up expectation on the correct service.
    switch ($expectedService) {
      case 'filesystem':
        $this->filesystemComponentActions
          ->deleteComponent($component->reveal())
          ->willReturn($expectedResult);
        break;

      case 'sql':
        $this->sqlComponentActions
          ->deleteComponent($component->reveal())
          ->willReturn($expectedResult);
        break;

      case 'triplestore':
        $this->triplestoreComponentActions
          ->deleteComponent($component->reveal())
          ->willReturn($expectedResult);
        break;

      case 'wisski':
        $this->wisskiComponentActions
          ->deleteComponent($component->reveal())
          ->willReturn($expectedResult);
        break;

      case 'webprotege':
        $this->webprotegeComponentActions
          ->deleteComponent($component->reveal())
          ->willReturn($expectedResult);
        break;
    }

    $result = $this->componentActions->deleteComponent($component->reveal());

    $this->assertTrue($result['success']);
  }

  /**
   * Tests updateComponent for WissKI bundle.
   *
   * @covers ::updateComponent
   */
  public function testUpdateComponentWisski(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('soda_scs_wisski_component');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Component updated',
      'data' => [],
    ];

    $this->wisskiComponentActions
      ->updateComponent($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->componentActions->updateComponent($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests updateComponent for WebProtege bundle.
   *
   * @covers ::updateComponent
   */
  public function testUpdateComponentWebprotege(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('soda_scs_webprotege_component');

    $expectedResult = [
      'success' => TRUE,
      'message' => 'Component updated',
      'data' => [],
    ];

    $this->webprotegeComponentActions
      ->updateComponent($component->reveal())
      ->willReturn($expectedResult);

    $result = $this->componentActions->updateComponent($component->reveal());

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * Tests updateComponent returns empty for unsupported bundles.
   *
   * @covers ::updateComponent
   */
  public function testUpdateComponentUnsupportedBundle(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);
    $component->bundle()->willReturn('soda_scs_sql_component');

    $result = $this->componentActions->updateComponent($component->reveal());

    $this->assertEquals([], $result);
  }

  /**
   * Tests getComponents returns empty array.
   *
   * @covers ::getComponents
   */
  public function testGetComponents(): void {
    $result = $this->componentActions->getComponents();

    $this->assertEquals([], $result);
  }

  /**
   * Tests getComponent returns not implemented response.
   *
   * @covers ::getComponent
   */
  public function testGetComponent(): void {
    $component = $this->prophesize(SodaScsComponentInterface::class);

    $result = $this->componentActions->getComponent($component->reveal());

    $this->assertFalse($result['success']);
    $this->assertEquals('Not yet implemented.', $result['error']);
  }

  /**
   * Data provider for component bundles and their expected services.
   *
   * @return array
   *   Array of bundle and expected service pairs.
   */
  public static function componentBundleProvider(): array {
    return [
      'filesystem' => ['soda_scs_filesystem_component', 'filesystem'],
      'sql' => ['soda_scs_sql_component', 'sql'],
      'triplestore' => ['soda_scs_triplestore_component', 'triplestore'],
      'wisski' => ['soda_scs_wisski_component', 'wisski'],
      'webprotege' => ['soda_scs_webprotege_component', 'webprotege'],
    ];
  }

}
