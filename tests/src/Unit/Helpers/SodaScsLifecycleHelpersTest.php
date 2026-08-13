<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsLifecycleHelpers;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsLifecycleHelpers
 * @group soda_scs_manager
 */
class SodaScsLifecycleHelpersTest extends UnitTestCase {

  /**
   * Builds a helper with mocked collaborators.
   */
  private function createHelper(): SodaScsLifecycleHelpers {
    $helper = new SodaScsLifecycleHelpers(
      $this->createMock(SodaScsContainerHelpers::class),
      $this->createMock(SodaScsJupyterHelpers::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
    );
    $helper->setStringTranslation($this->getStringTranslationStub());
    return $helper;
  }

  /**
   * @covers ::supportsEntity
   *
   * @dataProvider componentBundleProvider
   */
  public function testSupportsComponentBundles(string $bundle, bool $expected): void {
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('bundle')->willReturn($bundle);

    $this->assertSame($expected, $this->createHelper()->supportsEntity($component));
  }

  /**
   * Component bundles and whether lifecycle controls are offered.
   */
  public static function componentBundleProvider(): array {
    return [
      'sql' => ['soda_scs_sql_component', TRUE],
      'triplestore' => ['soda_scs_triplestore_component', TRUE],
      'wisski' => ['soda_scs_wisski_component', TRUE],
      'webprotege shared' => ['soda_scs_webprotege_component', FALSE],
    ];
  }

  /**
   * @covers ::supportsEntity
   *
   * @dataProvider stackBundleProvider
   */
  public function testSupportsStackBundles(string $bundle, bool $expected): void {
    $stack = $this->createMock(SodaScsStackInterface::class);
    $stack->method('bundle')->willReturn($bundle);

    $this->assertSame($expected, $this->createHelper()->supportsEntity($stack));
  }

  /**
   * Stack bundles and whether lifecycle controls are offered.
   */
  public static function stackBundleProvider(): array {
    return [
      'wisski' => ['soda_scs_wisski_stack', TRUE],
      'jupyter notebook' => ['soda_scs_jupyter_stack', TRUE],
      'nextcloud shared' => ['soda_scs_nextcloud_stack', FALSE],
    ];
  }

  /**
   * @covers ::performAction
   */
  public function testPerformActionRejectsUnknownAction(): void {
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('bundle')->willReturn('soda_scs_sql_component');

    $result = $this->createHelper()->performAction($component, 'pause');
    $this->assertFalse($result->success);
    $this->assertSame('Unsupported lifecycle action.', $result->error);
  }

  /**
   * @covers ::performAction
   */
  public function testPerformActionRejectsSharedService(): void {
    $stack = $this->createMock(SodaScsStackInterface::class);
    $stack->method('bundle')->willReturn('soda_scs_nextcloud_stack');

    $result = $this->createHelper()->performAction($stack, 'stop');
    $this->assertFalse($result->success);
  }

}
