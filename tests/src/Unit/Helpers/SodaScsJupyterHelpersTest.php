<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Service\SodaScsJupyterHubApiClient;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers
 * @group soda_scs_manager
 */
class SodaScsJupyterHelpersTest extends UnitTestCase {

  /**
   * Builds a project mock with the given group id.
   */
  private function createProject(string $gid = '10001'): SodaScsProjectInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('value')->willReturn($gid);

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('get')->with('groupId')->willReturn($field);
    $project->method('id')->willReturn((int) $gid - 10000);
    $project->method('label')->willReturn('rnsrk default project');
    return $project;
  }

  /**
   * Builds a helper around the given hub API client.
   */
  private function createHelper(SodaScsJupyterHubApiClient $hubApi): SodaScsJupyterHelpers {
    $helper = new SodaScsJupyterHelpers($hubApi, $this->createMock(LoggerInterface::class));
    $helper->setStringTranslation($this->getStringTranslationStub());
    return $helper;
  }

  /**
   * @covers ::buildProjectLauncherUrl
   */
  public function testBuildProjectLauncherUrl(): void {
    $this->assertSame(
      'https://code.dev-scs.sammlungen.io/hub/scs/projects/10001',
      SodaScsJupyterHelpers::buildProjectLauncherUrl('https://code.dev-scs.sammlungen.io/', $this->createProject()),
    );
  }

  /**
   * @covers ::projectServerUserName
   * @covers ::projectVolumeName
   */
  public function testProjectNames(): void {
    $project = $this->createProject('10027');
    $this->assertSame('project-10027', SodaScsJupyterHelpers::projectServerUserName($project));
    $this->assertSame('jupyterhub-user-project-10027', SodaScsJupyterHelpers::projectVolumeName($project));
  }

  /**
   * @covers ::startProjectServer
   */
  public function testStartProjectServerStartsTheProjectUser(): void {
    $hubApi = $this->createMock(SodaScsJupyterHubApiClient::class);
    $hubApi->expects($this->once())->method('startServer')->with('project-10001');

    $result = $this->createHelper($hubApi)->startProjectServer($this->createProject());

    $this->assertTrue($result->success);
  }

  /**
   * @covers ::stopProjectServer
   */
  public function testHubErrorsBecomeFailures(): void {
    $hubApi = $this->createMock(SodaScsJupyterHubApiClient::class);
    $hubApi->method('stopServer')->willThrowException(new \RuntimeException('JupyterHub API returned HTTP 500.'));

    $result = $this->createHelper($hubApi)->stopProjectServer($this->createProject());

    $this->assertFalse($result->success);
    $this->assertSame('JupyterHub API returned HTTP 500.', $result->error);
  }

  /**
   * @covers ::removeProjectServerUser
   */
  public function testRemoveKeepsTheUserWhenTheServerDoesNotStop(): void {
    $hubApi = $this->createMock(SodaScsJupyterHubApiClient::class);
    $hubApi->method('stopServerAndWait')->willReturn(FALSE);
    $hubApi->expects($this->never())->method('deleteUser');

    $result = $this->createHelper($hubApi)->removeProjectServerUser($this->createProject());

    $this->assertFalse($result->success);
  }

}
