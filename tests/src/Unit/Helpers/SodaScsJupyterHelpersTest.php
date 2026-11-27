<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Service\SodaScsJupyterHubApiClient;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests SodaScsJupyterHelpers.
 */
#[CoversMethod(SodaScsJupyterHelpers::class, 'buildProjectLauncherUrl')]
#[CoversMethod(SodaScsJupyterHelpers::class, 'projectServerUserName')]
#[CoversMethod(SodaScsJupyterHelpers::class, 'projectVolumeName')]
#[CoversMethod(SodaScsJupyterHelpers::class, 'startProjectServer')]
#[CoversMethod(SodaScsJupyterHelpers::class, 'stopProjectServer')]
#[CoversMethod(SodaScsJupyterHelpers::class, 'removeProjectServerUser')]
#[Group('soda_scs_manager')]
class SodaScsJupyterHelpersTest extends UnitTestCase {

  /**
   * Builds a project mock with the given group id.
   */
  private function createProject(string $gid = '10001'): SodaScsProjectInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('value')->willReturn($gid);
    // The code reads ->value ?? '', which calls __isset() first.
    $field->method('__isset')->with('value')->willReturn(TRUE);

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
   * Tests buildProjectLauncherUrl().
   */
  public function testBuildProjectLauncherUrl(): void {
    $this->assertSame(
      'https://code.dev-scs.sammlungen.io/hub/scs/projects/10001',
      SodaScsJupyterHelpers::buildProjectLauncherUrl('https://code.dev-scs.sammlungen.io/', $this->createProject()),
    );
  }

  /**
   * Tests project names.
   */
  public function testProjectNames(): void {
    $project = $this->createProject('10027');
    $this->assertSame('project-10027', SodaScsJupyterHelpers::projectServerUserName($project));
    $this->assertSame('jupyterhub-user-project-10027', SodaScsJupyterHelpers::projectVolumeName($project));
  }

  /**
   * Tests that startProjectServer() starts the project user.
   */
  public function testStartProjectServerStartsTheProjectUser(): void {
    $hubApi = $this->createMock(SodaScsJupyterHubApiClient::class);
    $hubApi->expects($this->once())->method('startServer')->with('project-10001');

    $result = $this->createHelper($hubApi)->startProjectServer($this->createProject());

    $this->assertTrue($result->success);
  }

  /**
   * Tests hub errors become failures.
   */
  public function testHubErrorsBecomeFailures(): void {
    $hubApi = $this->createMock(SodaScsJupyterHubApiClient::class);
    $hubApi->method('stopServer')->willThrowException(new \RuntimeException('JupyterHub API returned HTTP 500.'));

    $result = $this->createHelper($hubApi)->stopProjectServer($this->createProject());

    $this->assertFalse($result->success);
    $this->assertSame('JupyterHub API returned HTTP 500.', $result->error);
  }

  /**
   * Tests remove keeps the user when the server does not stop.
   */
  public function testRemoveKeepsTheUserWhenTheServerDoesNotStop(): void {
    $hubApi = $this->createMock(SodaScsJupyterHubApiClient::class);
    $hubApi->method('stopServerAndWait')->willReturn(FALSE);
    $hubApi->expects($this->never())->method('deleteUser');

    $result = $this->createHelper($hubApi)->removeProjectServerUser($this->createProject());

    $this->assertFalse($result->success);
  }

}
