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
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

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

  /**
   * @covers ::startNotebookContainerForUser
   */
  public function testStartNotebookContainerWithoutExistingContainer(): void {
    $docker = $this->createMock(SodaScsDockerRunServiceActions::class);
    $docker->method('buildInspectRequest')->willReturn(['routeParams' => ['containerId' => 'jupyter-peter']]);
    $docker->method('makeRequest')->willReturn([
      'success' => FALSE,
      'statusCode' => 404,
      'error' => 'Not found',
    ]);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('jupyterhub.generalSettings.containerNamePrefix')
      ->willReturn('jupyter-');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('soda_scs_manager.settings')->willReturn($config);

    $helper = new SodaScsJupyterHelpers(
      $docker,
      $this->createMock(SodaScsContainerHelpers::class),
      $configFactory,
      $loggerFactory,
    );

    $user = $this->createMock(UserInterface::class);
    $user->method('getAccountName')->willReturn('peter');

    $result = $helper->startNotebookContainerForUser($user);
    $this->assertFalse($result['success']);
    $this->assertFalse($result['started']);
    $this->assertSame('jupyter-peter', $result['containerName']);
  }

  /**
   * @covers ::buildProjectLabUrl
   */
  public function testBuildProjectLabUrl(): void {
    $this->assertSame(
      'https://code.dev-scs.sammlungen.io/user/rnsrk/lab/tree/nextcloud/rnsrk%20default%20project',
      SodaScsJupyterHelpers::buildProjectLabUrl(
        'https://code.dev-scs.sammlungen.io/',
        'rnsrk',
        'rnsrk default project',
      ),
    );
  }

}
