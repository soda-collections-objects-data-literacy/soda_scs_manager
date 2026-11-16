<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsDemoApiCredentials;
use Drupal\soda_scs_manager\Demo\SodaScsDemoException;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the API account handling inside a demo WissKI.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoApiCredentials
 * @group soda_scs_manager
 */
class SodaScsDemoApiCredentialsTest extends UnitTestCase {

  /**
   * Password that must never show up in the command line or the log.
   */
  protected const PASSWORD = 'not-a-real-password';

  /**
   * The mocked container helpers.
   */
  protected SodaScsContainerHelpers $containerHelpers;

  /**
   * The mocked logger.
   */
  protected LoggerInterface $logger;

  /**
   * The component the script runs in.
   */
  protected SodaScsComponentInterface $component;

  /**
   * The demo project the component belongs to.
   */
  protected SodaScsProjectInterface $project;

  /**
   * Template id the project reports, or NULL for none.
   */
  protected ?string $templateId = '5';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->containerHelpers = $this->createMock(SodaScsContainerHelpers::class);
    $this->containerHelpers->method('getComponentContainerName')->willReturn('wisski-demo--drupal');
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->component = $this->createMock(SodaScsComponentInterface::class);
    $this->component->method('id')->willReturn('12');
    $this->project = $this->createMock(SodaScsProjectInterface::class);
    $this->project->method('id')->willReturn('34');
    $this->project->method('get')->with('demoTemplate')->willReturnCallback(
      fn (): object => (object) ['target_id' => $this->templateId],
    );
  }

  /**
   * The exec hands the role list to the script through the environment.
   *
   * @covers ::setApiPassword
   */
  public function testExecPassesRolesAndCredentialsAsEnvironment(): void {
    $this->containerHelpers->expects($this->once())
      ->method('executeDockerExecCommand')
      ->with($this->callback(function (array $request): bool {
        $this->assertContains('SCS_DEMO_API_ROLES=wisski_user,wisski_api_import', $request['env']);
        $this->assertContains('SCS_DEMO_API_USER=api-import', $request['env']);
        $this->assertContains('SCS_DEMO_API_PASSWORD=' . self::PASSWORD, $request['env']);
        $this->assertSame('wisski-demo--drupal', $request['containerName']);
        return TRUE;
      }))
      ->willReturn(SodaScsResult::success(['output' => ''], 'ok'));

    $this->setApiPassword();
  }

  /**
   * The password stays out of the command line.
   *
   * @covers ::setApiPassword
   */
  public function testPasswordIsNotPartOfTheCommand(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->with($this->callback(function (array $request): bool {
        $this->assertStringNotContainsString(self::PASSWORD, implode(' ', $request['cmd']));
        return TRUE;
      }))
      ->willReturn(SodaScsResult::success(['output' => ''], 'ok'));

    $this->setApiPassword();
  }

  /**
   * The script prints the marker the PHP side looks for, and only then.
   *
   * @covers ::setApiPassword
   */
  public function testScriptPrintsTheMarkerOnlyWhenItCreatesTheAccount(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->with($this->callback(function (array $request): bool {
        $script = $request['cmd'][2];
        $this->assertStringContainsString('print "account created\n";', $script);
        $this->assertSame(1, substr_count($script, 'account created'));
        $this->assertStringContainsString('if ($created) {', $script);
        $this->assertStringNotContainsString('user missing', $script);
        return TRUE;
      }))
      ->willReturn(SodaScsResult::success(['output' => ''], 'ok'));

    $this->setApiPassword();
  }

  /**
   * An account the template already had changes nothing but the password.
   *
   * @covers ::setApiPassword
   */
  public function testNoWarningWhenTheTemplateHadTheAccount(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => ''], 'ok'));
    $this->logger->expects($this->never())->method('warning');

    $this->setApiPassword();
  }

  /**
   * An exec without output data is no reason to warn.
   *
   * @covers ::setApiPassword
   */
  public function testNoWarningWhenTheExecReturnsNoOutput(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success([], 'ok'));
    $this->logger->expects($this->never())->method('warning');

    $this->setApiPassword();
  }

  /**
   * Creating the account is logged so the template can be fixed.
   *
   * @covers ::setApiPassword
   */
  public function testWarnsWhenTheScriptCreatedTheAccount(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => "account created\n"], 'ok'));
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('lacks the account'),
        $this->callback(function (array $context): bool {
          $this->assertSame('api-import', $context['@account']);
          $this->assertSame('12', (string) $context['@component']);
          $this->assertSame('34', (string) $context['@project']);
          $this->assertSame('5', (string) $context['@template']);
          $this->assertStringNotContainsString(self::PASSWORD, implode(' ', $context));
          return TRUE;
        }),
      );

    $this->setApiPassword();
  }

  /**
   * A project without a template is named with a dash in the warning.
   *
   * @covers ::setApiPassword
   */
  public function testWarningNamesDashWhenProjectHasNoTemplate(): void {
    $this->templateId = NULL;
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(SodaScsResult::success(['output' => 'account created'], 'ok'));
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->anything(),
        $this->callback(function (array $context): bool {
          $this->assertSame('-', $context['@template']);
          $this->assertSame('34', (string) $context['@project']);
          return TRUE;
        }),
      );

    $this->setApiPassword();
  }

  /**
   * A failed exec throws and does not claim the account was created.
   *
   * @covers ::setApiPassword
   */
  public function testFailedExecThrows(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, ['output' => 'account created'], 'credentials missing', 'failed'));
    $this->logger->expects($this->never())->method('warning');

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('Could not set the API password in WissKI 12 for the account api-import: credentials missing');
    $this->setApiPassword();
  }

  /**
   * A failed exec without stderr says so instead of ending in a colon.
   *
   * @covers ::setApiPassword
   */
  public function testFailedExecWithoutErrorTextSaysNoOutput(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, [], '  ', 'failed'));

    $this->expectException(SodaScsDemoException::class);
    $this->expectExceptionMessage('for the account api-import: no output');
    $this->setApiPassword();
  }

  /**
   * The password reaches neither the exception message nor the log.
   *
   * @covers ::setApiPassword
   */
  public function testPasswordIsNeitherInExceptionNorLogContext(): void {
    $this->containerHelpers->method('executeDockerExecCommand')
      ->willReturn(new SodaScsResult(FALSE, [], 'drush exited with 1', 'failed'));
    $this->logger->expects($this->never())->method('warning');
    $this->logger->expects($this->never())->method('error');

    try {
      $this->setApiPassword();
      $this->fail('Expected a SodaScsDemoException.');
    }
    catch (SodaScsDemoException $exception) {
      $this->assertStringNotContainsString(self::PASSWORD, $exception->getMessage());
      $this->assertStringContainsString('drush exited with 1', $exception->getMessage());
    }
  }

  /**
   * Runs the protected method under test.
   */
  protected function setApiPassword(): void {
    $credentials = new SodaScsDemoApiCredentials(
      $this->containerHelpers,
      $this->createMock(SodaScsJupyterHelpers::class),
      $this->logger,
      $this->createMock(SodaScsDemoProjectApps::class),
      $this->createMock(SodaScsServiceHelpers::class),
      $this->createMock(SodaScsSnapshotHelpers::class),
    );
    (new \ReflectionMethod($credentials, 'setApiPassword'))->invoke($credentials, self::PASSWORD, $this->project, $this->component);
  }

}
