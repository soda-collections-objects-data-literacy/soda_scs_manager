<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Drush;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoServiceAccount;
use Drupal\soda_scs_manager\Drush\Commands\SodaScsProjectOwnershipCommands;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Service\SodaScsProjectOwnershipTransfer;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Drush\Drush;
use Drush\Log\DrushLoggerManager;
use Drush\Style\DrushStyle;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The drush commands for the demo account and the project transfer.
 *
 * Issue 3640913. The services behind the commands are doubles.
 */
#[CoversMethod(SodaScsProjectOwnershipCommands::class, 'demoAccount')]
#[CoversMethod(SodaScsProjectOwnershipCommands::class, 'projectTransfer')]
#[Group('soda_scs_manager')]
class SodaScsProjectOwnershipCommandsTest extends UnitTestCase {

  /**
   * Messages the command logged, as "level: message".
   *
   * @var list<string>
   */
  private array $logged = [];

  /**
   * Questions the command asked, in order.
   *
   * @var list<string>
   */
  private array $questions = [];

  /**
   * What the user answers to the confirmation.
   */
  private bool $confirmed = TRUE;

  /**
   * The demo service account double.
   */
  private SodaScsDemoServiceAccount&MockObject $serviceAccount;

  /**
   * The ownership transfer double.
   */
  private SodaScsProjectOwnershipTransfer&MockObject $ownershipTransfer;

  /**
   * The project storage double.
   */
  private EntityStorageInterface&MockObject $projectStorage;

  /**
   * The user storage double.
   */
  private EntityStorageInterface&MockObject $userStorage;

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
    $this->questions = [];
    $this->confirmed = TRUE;
    $this->serviceAccount = $this->createMock(SodaScsDemoServiceAccount::class);
    $this->ownershipTransfer = $this->createMock(SodaScsProjectOwnershipTransfer::class);
    // @phpstan-ignore drupal.entityStoragePropertyAssignment (a double the test configures per case, not an injected service)
    $this->projectStorage = $this->createMock(EntityStorageInterface::class);
    // @phpstan-ignore drupal.entityStoragePropertyAssignment (a double the test configures per case, not an injected service)
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
  }

  /**
   * A ready account exits with success and logs the message.
   */
  public function testDemoAccountSucceeds(): void {
    $this->serviceAccount->method('ensure')->willReturn(SodaScsResult::success(data: [], message: 'The account scs_demo is ready.'));

    $this->assertSame(0, $this->command()->demoAccount());
    $this->assertSame(['success: The account scs_demo is ready.'], $this->logged);
  }

  /**
   * A failing setup exits with failure and logs the reason.
   */
  public function testDemoAccountFailureExitsWithFailure(): void {
    $this->serviceAccount->method('ensure')->willReturn(SodaScsResult::failure(error: 'x', message: 'Set the password.', log: FALSE));

    $this->assertSame(1, $this->command()->demoAccount());
    $this->assertSame(['error: Set the password.'], $this->logged);
  }

  /**
   * An unknown project is reported and nothing is transferred.
   */
  public function testTransferOfUnknownProjectFails(): void {
    $this->projectStorage->method('load')->with(99)->willReturn(NULL);
    $this->ownershipTransfer->expects($this->never())->method('transfer');

    $this->assertSame(1, $this->command()->projectTransfer('99', 'scs_demo'));
    $this->assertCount(1, $this->logged);
    $this->assertStringStartsWith('error:', $this->logged[0]);
  }

  /**
   * An unknown account is reported and nothing is transferred.
   */
  public function testTransferToUnknownAccountFails(): void {
    $this->projectStorage->method('load')->willReturn($this->createMock(SodaScsProjectInterface::class));
    $this->userStorage->method('loadByProperties')->with(['name' => 'nobody'])->willReturn([]);
    $this->ownershipTransfer->expects($this->never())->method('transfer');

    $this->assertSame(1, $this->command()->projectTransfer('5', 'nobody'));
    $this->assertStringStartsWith('error:', $this->logged[0]);
  }

  /**
   * The previous owner stays a member unless the option says otherwise.
   */
  public function testTransferKeepsPreviousOwnerByDefault(): void {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $account = $this->createMock(UserInterface::class);
    $this->projectStorage->method('load')->with(5)->willReturn($project);
    $this->userStorage->method('loadByProperties')->with(['name' => 'scs_demo'])->willReturn([9 => $account]);
    $this->ownershipTransfer->expects($this->once())->method('transfer')->with($project, $account, TRUE)
      ->willReturn(SodaScsResult::success(data: ['warnings' => []], message: 'Project Demo now belongs to scs_demo.'));

    $this->assertSame(0, $this->command()->projectTransfer('5', 'scs_demo'));
    $this->assertSame(['success: Project Demo now belongs to scs_demo.'], $this->logged);
  }

  /**
   * The confirmation names project and account; "no" transfers nothing.
   */
  public function testDeclinedConfirmationTransfersNothing(): void {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('label')->willReturn('Demo Projekt');
    $account = $this->createMock(UserInterface::class);
    $account->method('getAccountName')->willReturn('scs_demo');
    $this->projectStorage->method('load')->willReturn($project);
    $this->userStorage->method('loadByProperties')->willReturn([9 => $account]);
    $this->confirmed = FALSE;
    $this->ownershipTransfer->expects($this->never())->method('transfer');

    $this->assertSame(1, $this->command()->projectTransfer('5', 'scs_demo'));
    $this->assertCount(1, $this->questions);
    $this->assertStringContainsString('Demo Projekt', $this->questions[0]);
    $this->assertStringContainsString('scs_demo', $this->questions[0]);
  }

  /**
   * The option drops the previous owner.
   */
  public function testTransferCanRemovePreviousOwner(): void {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $account = $this->createMock(UserInterface::class);
    $this->projectStorage->method('load')->willReturn($project);
    $this->userStorage->method('loadByProperties')->willReturn([9 => $account]);
    $this->ownershipTransfer->expects($this->once())->method('transfer')->with($project, $account, FALSE)
      ->willReturn(SodaScsResult::success(data: ['warnings' => []], message: 'done'));

    $this->assertSame(0, $this->command()->projectTransfer('5', 'scs_demo', ['remove-previous-owner' => TRUE]));
  }

  /**
   * A failed transfer exits with failure and logs the reason.
   */
  public function testFailedTransferExitsWithFailure(): void {
    $this->projectStorage->method('load')->willReturn($this->createMock(SodaScsProjectInterface::class));
    $this->userStorage->method('loadByProperties')->willReturn([9 => $this->createMock(UserInterface::class)]);
    $this->ownershipTransfer->method('transfer')->willReturn(SodaScsResult::failure(error: 'x', message: 'The project is locked by another job.', log: FALSE));

    $this->assertSame(1, $this->command()->projectTransfer('5', 'scs_demo'));
    $this->assertSame(['error: The project is locked by another job.'], $this->logged);
  }

  /**
   * Follow-up warnings are logged before the success message.
   */
  public function testTransferLogsWarnings(): void {
    $this->projectStorage->method('load')->willReturn($this->createMock(SodaScsProjectInterface::class));
    $this->userStorage->method('loadByProperties')->willReturn([9 => $this->createMock(UserInterface::class)]);
    $this->ownershipTransfer->method('transfer')->willReturn(SodaScsResult::success(data: ['warnings' => ['Keycloak groups could not be synced.']], message: 'done'));

    $this->assertSame(0, $this->command()->projectTransfer('5', 'scs_demo'));
    $this->assertSame(['warning: Keycloak groups could not be synced.', 'success: done'], $this->logged);
  }

  /**
   * Builds the command with doubles and a non-interactive console.
   */
  private function command(): SodaScsProjectOwnershipCommands {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['soda_scs_project', $this->projectStorage],
      ['user', $this->userStorage],
    ]);
    $command = new SodaScsProjectOwnershipCommands($this->serviceAccount, $entityTypeManager, $this->ownershipTransfer);

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
    $style = $this->createMock(DrushStyle::class);
    $style->method('confirm')->willReturnCallback(function (string $question): bool {
      $this->questions[] = $question;
      return $this->confirmed;
    });
    $command->restoreState(new ArrayInput([]), new BufferedOutput(), $style);
    return $command;
  }

}
