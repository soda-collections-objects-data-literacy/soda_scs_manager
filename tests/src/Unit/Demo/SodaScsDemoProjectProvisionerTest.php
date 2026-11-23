<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoApiCredentials;
use Drupal\soda_scs_manager\Demo\SodaScsDemoException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoIncompleteAppException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoLabelBuilder;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectProvisioner;
use Drupal\soda_scs_manager\Demo\SodaScsDemoWisskiTransfer;
use Drupal\soda_scs_manager\Demo\SodaScsTeamFolderCopier;
use Drupal\soda_scs_manager\Demo\SodaScsWisskiUriRewriter;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsVolumeArchiveHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

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
   * The logger double.
   */
  private LoggerInterface&MockObject $logger;

  /**
   * The URI rewriter double.
   */
  private SodaScsWisskiUriRewriter&MockObject $uriRewriter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $this->stackActions = $this->createMock(SodaScsStackActionsInterface::class);
    $this->project = $this->createMock(SodaScsProjectInterface::class);
    $this->project->method('id')->willReturn(5);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->uriRewriter = $this->createMock(SodaScsWisskiUriRewriter::class);
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
   * The bases the rewriter finds are handed on.
   *
   * @covers ::sourceUriBases
   */
  public function testSourceUriBasesAreThoseOfTheRewriter(): void {
    $bases = ['graph' => 'https://source.example.org/contents/', 'site' => 'https://source.example.org/'];
    $template = $this->template(imported: FALSE);
    $this->uriRewriter->expects($this->once())->method('templateSourceBases')->with($template)->willReturn($bases);
    $this->logger->expects($this->never())->method('notice');

    $this->assertSame($bases, $this->sourceUriBases($template));
  }

  /**
   * Unknown bases of a built version keep the URIs and log a notice.
   *
   * @covers ::sourceUriBases
   */
  public function testUnknownSourceUriBasesOfBuiltVersionLogThatTheSourceIsGone(): void {
    $this->uriRewriter->method('templateSourceBases')->willReturn(NULL);
    $this->logger->expects($this->once())->method('notice')
      ->with($this->stringContains('its source WissKI is gone'), ['@version' => '1.2.0']);

    $this->assertNull($this->sourceUriBases($this->template(imported: FALSE)));
  }

  /**
   * Unknown bases of an imported version are logged as imported without them.
   *
   * @covers ::sourceUriBases
   */
  public function testUnknownSourceUriBasesOfAnImportedVersionLogThatNoneWereImported(): void {
    $this->uriRewriter->method('templateSourceBases')->willReturn(NULL);
    $this->logger->expects($this->once())->method('notice')
      ->with($this->stringContains('was imported without base URIs'), ['@version' => '1.2.0']);

    $this->assertNull($this->sourceUriBases($this->template(imported: TRUE)));
  }

  /**
   * A template file that is missing or changed is named.
   *
   * @covers ::verifyChecksums
   */
  public function testVerifyChecksumsNamesTheFirstDamagedFile(): void {
    $directory = sys_get_temp_dir() . '/scs-provisioner-test-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/a.bin', 'a');
    try {
      $this->expectException(SodaScsDemoException::class);
      $this->expectExceptionMessage('Template file b.bin is missing or damaged.');

      $this->verifyChecksums(['checksums' => ['a.bin' => hash('sha256', 'a'), 'b.bin' => hash('sha256', 'b')]], $directory);
    }
    finally {
      unlink($directory . '/a.bin');
      rmdir($directory);
    }
  }

  /**
   * Matching files pass; a manifest without checksums passes nothing to check.
   *
   * @covers ::verifyChecksums
   */
  public function testVerifyChecksumsAcceptsMatchingFiles(): void {
    $directory = sys_get_temp_dir() . '/scs-provisioner-test-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/a.bin', 'a');
    try {
      $this->verifyChecksums(['checksums' => ['a.bin' => hash('sha256', 'a')]], $directory);
      $this->verifyChecksums([], $directory);
      $this->addToAssertionCount(1);
    }
    finally {
      unlink($directory . '/a.bin');
      rmdir($directory);
    }
  }

  /**
   * Calls the protected verifyChecksums().
   */
  private function verifyChecksums(array $manifest, string $directory): void {
    $provisioner = $this->provisioner();
    (new \ReflectionMethod($provisioner, 'verifyChecksums'))->invoke($provisioner, $manifest, $directory);
  }

  /**
   * Calls the protected sourceUriBases().
   */
  private function sourceUriBases(SodaScsDemoTemplateInterface $template): ?array {
    $provisioner = $this->provisioner();
    return (new \ReflectionMethod($provisioner, 'sourceUriBases'))->invoke($provisioner, $template);
  }

  /**
   * A template double.
   */
  private function template(bool $imported): SodaScsDemoTemplateInterface&MockObject {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('isImported')->willReturn($imported);
    $template->method('getVersion')->willReturn('1.2.0');
    return $template;
  }

  /**
   * Builds the provisioner on the doubles.
   */
  private function provisioner(): SodaScsDemoProjectProvisioner {
    return new SodaScsDemoProjectProvisioner(
      $this->createMock(SodaScsDemoApiCredentials::class),
      $this->createMock(SodaScsComponentActionsInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(SodaScsJupyterHelpers::class),
      $this->createMock(SodaScsDemoLabelBuilder::class),
      $this->logger,
      $this->projectApps,
      $this->stackActions,
      $this->createMock(SodaScsTeamFolderCopier::class),
      $this->uriRewriter,
      $this->createMock(SodaScsVolumeArchiveHelpers::class),
      $this->createMock(SodaScsWebprotegeHelpers::class),
      $this->createMock(SodaScsDemoWisskiTransfer::class),
    );
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
    $provisioner = $this->provisioner();
    $method = new \ReflectionMethod($provisioner, 'ensureWisskiStack');
    return $method->invoke($provisioner, $this->project, 'alice');
  }

}
