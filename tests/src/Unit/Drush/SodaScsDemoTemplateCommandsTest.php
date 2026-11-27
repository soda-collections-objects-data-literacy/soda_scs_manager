<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Drush;

use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateArchive;
use Drupal\soda_scs_manager\Drush\Commands\SodaScsDemoTemplateCommands;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Drush\Drush;
use Drush\Log\DrushLoggerManager;
use Psr\Log\AbstractLogger;

/**
 * The drush commands that export, import and list demo versions.
 *
 * Issue 3640915. The demo manager and the archive service are doubles.
 */
#[CoversMethod(SodaScsDemoTemplateCommands::class, 'demoExport')]
#[CoversMethod(SodaScsDemoTemplateCommands::class, 'demoImport')]
#[CoversMethod(SodaScsDemoTemplateCommands::class, 'demoVersions')]
#[Group('soda_scs_manager')]
class SodaScsDemoTemplateCommandsTest extends UnitTestCase {

  /**
   * Messages the command logged, as "level: message".
   *
   * @var list<string>
   */
  private array $logged = [];

  /**
   * The demo manager double.
   */
  private SodaScsDemoManager&MockObject $demoManager;

  /**
   * The archive service double.
   */
  private SodaScsDemoTemplateArchive&MockObject $templateArchive;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!function_exists('dt')) {
      // The helper drush defines when it runs; the commands call it.
      require_once dirname((new \ReflectionClass(Drush::class))->getFileName(), 2) . '/includes/output.inc';
    }
    $this->logged = [];
    $this->demoManager = $this->createMock(SodaScsDemoManager::class);
    $this->templateArchive = $this->createMock(SodaScsDemoTemplateArchive::class);
  }

  /**
   * An unknown version is reported and nothing is exported.
   */
  public function testExportOfUnknownVersionFails(): void {
    $this->demoManager->method('templateWithVersion')->with('9.9.9')->willReturn(NULL);
    $this->templateArchive->expects($this->never())->method('export');

    $this->assertSame(1, $this->command()->demoExport('9.9.9'));
    $this->assertSame(['error: Demo version 9.9.9 does not exist.'], $this->logged);
  }

  /**
   * The export uses the default directory without the option.
   */
  public function testExportUsesTheDefaultOutputDirectory(): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $this->demoManager->method('templateWithVersion')->with('1.2.5')->willReturn($template);
    $this->templateArchive->expects($this->once())->method('export')->with($template, NULL)
      ->willReturn(SodaScsResult::success(['archive' => '/x/demo-v1.2.5.tar.gz'], 'Version 1.2.5 exported to /x/demo-v1.2.5.tar.gz.'));

    $this->assertSame(0, $this->command()->demoExport('1.2.5'));
    $this->assertSame(['success: Version 1.2.5 exported to /x/demo-v1.2.5.tar.gz.'], $this->logged);
  }

  /**
   * The output option reaches the archive service.
   */
  public function testExportPassesTheOutputOption(): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $this->demoManager->method('templateWithVersion')->willReturn($template);
    $this->templateArchive->expects($this->once())->method('export')->with($template, '/srv/out')
      ->willReturn(SodaScsResult::success([], 'ok'));

    $this->assertSame(0, $this->command()->demoExport('1.2.5', ['output' => '/srv/out']));
  }

  /**
   * An empty output option counts as not given.
   */
  public function testExportTreatsAnEmptyOutputOptionAsNotGiven(): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $this->demoManager->method('templateWithVersion')->willReturn($template);
    $this->templateArchive->expects($this->once())->method('export')->with($template, NULL)
      ->willReturn(SodaScsResult::success([], 'ok'));

    $this->assertSame(0, $this->command()->demoExport('1.2.5', ['output' => '']));
  }

  /**
   * A failed export exits with failure and logs the reason.
   */
  public function testFailedExportExitsWithFailure(): void {
    $this->demoManager->method('templateWithVersion')->willReturn($this->createMock(SodaScsDemoTemplateInterface::class));
    $this->templateArchive->method('export')
      ->willReturn(SodaScsResult::failure(error: 'x', message: 'Version 1.2.5 is not ready.', log: FALSE));

    $this->assertSame(1, $this->command()->demoExport('1.2.5'));
    $this->assertSame(['error: Version 1.2.5 is not ready.'], $this->logged);
  }

  /**
   * The import does not activate unless asked.
   */
  public function testImportDoesNotActivateByDefault(): void {
    $this->templateArchive->expects($this->once())->method('import')->with('/in/demo-v1.2.5.tar.gz', FALSE)
      ->willReturn(SodaScsResult::success([], 'Version 1.2.5 imported.'));

    $this->assertSame(0, $this->command()->demoImport('/in/demo-v1.2.5.tar.gz'));
    $this->assertSame(['success: Version 1.2.5 imported.'], $this->logged);
  }

  /**
   * The activate option reaches the archive service.
   */
  public function testImportPassesTheActivateOption(): void {
    $this->templateArchive->expects($this->once())->method('import')->with('/in/demo-v1.2.5.tar.gz', TRUE)
      ->willReturn(SodaScsResult::success([], 'ok'));

    $this->assertSame(0, $this->command()->demoImport('/in/demo-v1.2.5.tar.gz', ['activate' => TRUE]));
  }

  /**
   * A refused archive exits with failure and logs the reason.
   */
  public function testFailedImportExitsWithFailure(): void {
    $this->templateArchive->method('import')
      ->willReturn(SodaScsResult::failure(error: 'x', message: 'The checksum of the archive does not match.', log: FALSE));

    $this->assertSame(1, $this->command()->demoImport('/in/demo-v1.2.5.tar.gz'));
    $this->assertSame(['error: The checksum of the archive does not match.'], $this->logged);
  }

  /**
   * The list shows version, status, origin, active flag and usage.
   */
  public function testVersionsListsEveryVersionInTheOrderOfTheManager(): void {
    $imported = $this->template('1.3.0', 'ready', 'imported');
    $built = $this->template('1.2.0', 'ready', 'built');
    $failed = $this->template('1.1.0', 'failed', 'built');
    $this->demoManager->method('templatesByVersion')->willReturn([$imported, $built, $failed]);
    $this->demoManager->method('isActiveTemplate')->willReturnCallback(fn (SodaScsDemoTemplateInterface $template): bool => $template === $imported);
    $this->demoManager->method('templateUsageCount')->willReturnMap([[$imported, 2], [$built, 0], [$failed, 0]]);

    $rows = $this->command()->demoVersions()->getArrayCopy();

    $this->assertSame([
      ['active' => 'yes', 'demos' => 2, 'origin' => 'imported', 'status' => 'ready', 'version' => '1.3.0'],
      ['active' => '', 'demos' => 0, 'origin' => 'built', 'status' => 'ready', 'version' => '1.2.0'],
      ['active' => '', 'demos' => 0, 'origin' => 'built', 'status' => 'failed', 'version' => '1.1.0'],
    ], $rows);
  }

  /**
   * Without versions the list is empty.
   */
  public function testVersionsIsEmptyWithoutVersions(): void {
    $this->demoManager->method('templatesByVersion')->willReturn([]);

    $this->assertSame([], $this->command()->demoVersions()->getArrayCopy());
  }

  /**
   * A version double.
   */
  private function template(string $version, string $status, string $origin): SodaScsDemoTemplateInterface&MockObject {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getVersion')->willReturn($version);
    $template->method('getStatus')->willReturn($status);
    $template->method('getOrigin')->willReturn($origin);
    return $template;
  }

  /**
   * Builds the command with doubles and a collecting logger.
   */
  private function command(): SodaScsDemoTemplateCommands {
    $command = new SodaScsDemoTemplateCommands($this->demoManager, $this->templateArchive);
    $collector = new class ($this->logged) extends AbstractLogger {

      /**
       * Collects the entries as "level: message".
       *
       * @param list<string> $entries
       *   The list the entries go to.
       */
      public function __construct(private array &$entries) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->entries[] = $level . ': ' . $message;
      }

    };
    $logger = new DrushLoggerManager();
    $logger->add('test', $collector);
    $command->setLogger($logger);
    return $command;
  }

}
