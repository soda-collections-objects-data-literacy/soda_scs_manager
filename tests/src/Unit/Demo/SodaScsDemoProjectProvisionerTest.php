<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoApiCredentials;
use Drupal\soda_scs_manager\Demo\SodaScsDemoIncompleteAppException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoLabelBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectProvisioner;
use Drupal\soda_scs_manager\Demo\SodaScsDemoWisskiTransfer;
use Drupal\soda_scs_manager\Demo\SodaScsTeamFolderCopier;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsVolumeArchiveHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests how the demo provisioner treats an existing WissKI stack.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoProjectProvisioner
 * @group soda_scs_manager
 */
class SodaScsDemoProjectProvisionerTest extends UnitTestCase {

  /**
   * The mocked project inventory.
   */
  private SodaScsDemoProjectApps&MockObject $projectApps;

  /**
   * The mocked stack actions; no stack is created in these tests.
   */
  private SodaScsStackActionsInterface&MockObject $stackActions;

  /**
   * The demo project.
   */
  private SodaScsProjectInterface&MockObject $project;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $this->stackActions = $this->createMock(SodaScsStackActionsInterface::class);
    $this->project = $this->createMock(SodaScsProjectInterface::class);
    $this->project->method('id')->willReturn(5);
  }

  /**
   * A stack left without a WissKI component fails the job at once.
   *
   * @covers ::ensureWisskiStack
   */
  public function testExistingWisskiStackWithoutWisskiComponentThrowsIncompleteApp(): void {
    $this->existingWisskiStack(NULL);
    $this->stackActions->expects($this->never())->method('createStack');

    $this->expectException(SodaScsDemoIncompleteAppException::class);
    $this->expectExceptionMessage('WissKI stack 7 of demo project 5 has no WissKI component.');

    $this->ensureWisskiStack();
  }

  /**
   * An existing stack with its WissKI component is reused, not created again.
   *
   * @covers ::ensureWisskiStack
   */
  public function testExistingWisskiStackWithWisskiComponentIsReturned(): void {
    $stack = $this->existingWisskiStack($this->createMock(SodaScsComponentInterface::class));
    $this->stackActions->expects($this->never())->method('createStack');

    $this->assertSame($stack, $this->ensureWisskiStack());
  }

  /**
   * Lets the inventory return a WissKI stack with the given component.
   */
  private function existingWisskiStack(?SodaScsComponentInterface $wisskiComponent): SodaScsStackInterface {
    $stack = $this->createMock(SodaScsStackInterface::class);
    $stack->method('id')->willReturn(7);
    $this->projectApps->method('wisskiStack')->with($this->project)->willReturn($stack);
    $this->projectApps->method('stackComponent')
      ->with($stack, SodaScsDemoProjectApps::WISSKI_COMPONENT_BUNDLE)
      ->willReturn($wisskiComponent);
    return $stack;
  }

  /**
   * Calls the protected ensureWisskiStack() on a provisioner with mocks.
   */
  private function ensureWisskiStack(): SodaScsStackInterface {
    $provisioner = new SodaScsDemoProjectProvisioner(
      $this->createMock(SodaScsDemoApiCredentials::class),
      $this->createMock(SodaScsComponentActionsInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(SodaScsJupyterHelpers::class),
      $this->createMock(SodaScsDemoLabelBuilder::class),
      $this->projectApps,
      $this->stackActions,
      $this->createMock(SodaScsTeamFolderCopier::class),
      $this->createMock(SodaScsVolumeArchiveHelpers::class),
      $this->createMock(SodaScsWebprotegeHelpers::class),
      $this->createMock(SodaScsDemoWisskiTransfer::class),
    );
    $method = new \ReflectionMethod($provisioner, 'ensureWisskiStack');
    return $method->invoke($provisioner, $this->project, 'alice');
  }

}
