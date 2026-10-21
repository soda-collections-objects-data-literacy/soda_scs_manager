<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Service\NextcloudMountCredentialsStore;
use Drupal\soda_scs_manager\Service\NextcloudMounterClient;
use Drupal\soda_scs_manager\Service\NextcloudMountManager;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\NextcloudMountManager
 * @group soda_scs_manager
 */
class NextcloudMountManagerTeamFolderTest extends UnitTestCase {

  /**
   * Temporary directory that stands in for the user's Drive mount.
   */
  private string $mountPath;

  /**
   * The mounter client mock.
   */
  private NextcloudMounterClient&MockObject $mounterClient;

  /**
   * The Nextcloud helpers mock.
   */
  private SodaScsNextcloudHelpers&MockObject $nextcloudHelpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mountPath = sys_get_temp_dir() . '/scs-mount-test-' . bin2hex(random_bytes(4));
    mkdir($this->mountPath);

    $this->mounterClient = $this->createMock(NextcloudMounterClient::class);
    $this->mounterClient->method('isListed')->willReturn(TRUE);
    $this->mounterClient->method('probeMount')->willReturn('mounted');
    $this->mounterClient->method('hostMountPath')->with('rnsrk')->willReturn($this->mountPath);

    $this->nextcloudHelpers = $this->createMock(SodaScsNextcloudHelpers::class);
    $this->nextcloudHelpers->method('getMountCredentials')->willReturn([
      'username'    => 'sub-rnsrk',
      'appPassword' => 'secret',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @rmdir($this->mountPath . '/wat26-workshop');
    @rmdir($this->mountPath);
    parent::tearDown();
  }

  /**
   * A Team Folder created after the last Drive login gets granted and shown.
   *
   * Regression: Nextcloud adds Keycloak groups only at Drive login, so a
   * WissKI created right after its project failed with "Team Folder is not
   * available under the owner mount yet".
   *
   * @covers ::ensureProjectTeamFolderReady
   */
  public function testMissingTeamFolderIsGrantedAndCacheDropped(): void {
    $user = $this->createUser();
    $this->nextcloudHelpers->expects($this->once())
      ->method('addUserToGroup')
      ->with('10069', $user)
      ->willReturnCallback(function (): bool {
        mkdir($this->mountPath . '/wat26-workshop');
        return TRUE;
      });
    $this->mounterClient->expects($this->once())
      ->method('forgetDirectoryCache')
      ->with('rnsrk');

    $this->assertTrue($this->createManager()->ensureProjectTeamFolderReady($user, $this->createProject(), 1));
  }

  /**
   * A visible Team Folder needs no Nextcloud call.
   *
   * @covers ::ensureProjectTeamFolderReady
   */
  public function testVisibleTeamFolderSkipsGrant(): void {
    mkdir($this->mountPath . '/wat26-workshop');
    $this->nextcloudHelpers->expects($this->never())->method('addUserToGroup');
    $this->mounterClient->expects($this->never())->method('forgetDirectoryCache');

    $this->assertTrue($this->createManager()->ensureProjectTeamFolderReady($this->createUser(), $this->createProject(), 1));
  }

  /**
   * Builds the manager with the shared mocks.
   */
  private function createManager(): NextcloudMountManager {
    $serviceHelpers = $this->createMock(SodaScsServiceHelpers::class);
    $serviceHelpers->method('initNextcloudSettings')->willReturn(['baseUrl' => 'https://drive.test']);

    return new NextcloudMountManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->mounterClient,
      $this->createMock(NextcloudMountCredentialsStore::class),
      $this->nextcloudHelpers,
      $serviceHelpers,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a project with Keycloak gid 10069.
   */
  private function createProject(): SodaScsProjectInterface {
    $groupId = $this->createMock(FieldItemListInterface::class);
    $groupId->method('__get')->with('value')->willReturn('10069');

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('label')->willReturn('wat26-workshop');
    $project->method('get')->with('groupId')->willReturn($groupId);
    return $project;
  }

  /**
   * Builds the project owner.
   */
  private function createUser(): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('getAccountName')->willReturn('rnsrk');
    $user->method('id')->willReturn(2);
    return $user;
  }

}
