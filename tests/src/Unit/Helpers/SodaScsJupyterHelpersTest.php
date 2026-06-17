<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers
 * @group soda_scs_manager
 */
class SodaScsJupyterHelpersTest extends UnitTestCase {

  /**
   * Builds a helper with a fixed container name prefix.
   */
  private function createHelper(string $prefix = 'jupyter-'): SodaScsJupyterHelpers {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('jupyterhub.generalSettings.containerNamePrefix')
      ->willReturn($prefix);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('soda_scs_manager.settings')->willReturn($config);

    return new SodaScsJupyterHelpers(
      $this->createMock(SodaScsDockerRunServiceActions::class),
      $this->createMock(SodaScsContainerHelpers::class),
      $configFactory,
      $this->createMock(LoggerChannelFactoryInterface::class),
    );
  }

  /**
   * @covers ::getNotebookContainerName
   */
  public function testGetNotebookContainerName(): void {
    $helper = $this->createHelper();
    $this->assertSame('jupyter-peter', $helper->getNotebookContainerName('peter'));
  }

  /**
   * @covers ::getContainerNamePrefix
   */
  public function testGetContainerNamePrefixDefault(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('jupyterhub.generalSettings.containerNamePrefix')
      ->willReturn('');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('soda_scs_manager.settings')->willReturn($config);

    $helper = new SodaScsJupyterHelpers(
      $this->createMock(SodaScsDockerRunServiceActions::class),
      $this->createMock(SodaScsContainerHelpers::class),
      $configFactory,
      $this->createMock(LoggerChannelFactoryInterface::class),
    );

    $this->assertSame('jupyter-', $helper->getContainerNamePrefix());
  }

}
