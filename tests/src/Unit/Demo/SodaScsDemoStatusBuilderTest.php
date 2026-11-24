<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder
 * @group soda_scs_manager
 */
class SodaScsDemoStatusBuilderTest extends UnitTestCase {

  /**
   * Busy status lists only applications that are not ready yet.
   *
   * @covers ::data
   */
  public function testDataListsPendingApps(): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getManifest')->willReturn([
      'apps' => [
        'webprotege' => [],
        'database' => [],
        'wisski' => [],
        'jupyter' => [],
        'triplestore' => [],
      ],
    ]);

    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('projectOperationId')->willReturn(NULL);
    $demoManager->method('activeTemplate')->willReturn($template);

    $projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $projectApps->method('wisskiStack')->willReturn($this->createMock(SodaScsStackInterface::class));
    $projectApps->method('jupyterStack')->willReturn(NULL);
    $projectApps->method('webprotegeComponent')->willReturn(NULL);

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('get')->willReturnCallback(static function (string $field): object {
      return match ($field) {
        'demoStatus' => new class() {
          public string $value = 'provisioning';
        },
        'demoTemplate' => new class() {
          public mixed $entity = NULL;
        },
        default => new \stdClass(),
      };
    });

    $builder = new SodaScsDemoStatusBuilder(
      $this->createMock(DateFormatterInterface::class),
      $demoManager,
      $this->createMock(SodaScsProgressHelper::class),
      $projectApps,
      $this->clock(),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());

    $data = $builder->data($project);

    $this->assertTrue($data['isBusy']);
    $this->assertSame(1, $data['createdCount']);
    $this->assertSame(
      [
        ['detail' => 'Started', 'key' => 'wisski', 'label' => 'WissKI'],
        ['detail' => 'Being created', 'key' => 'jupyter', 'label' => 'JupyterHub'],
        ['detail' => 'Being created', 'key' => 'webprotege', 'label' => 'WebProtégé'],
      ],
      $data['apps'],
    );
    $this->assertSame('', $data['jobLine']);
  }

  /**
   * An application that already exists and whose step has finished is omitted.
   *
   * @covers ::data
   */
  public function testDataOmitsReadyApps(): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getManifest')->willReturn([
      'apps' => [
        'database' => [],
        'jupyter' => [],
        'triplestore' => [],
        'webprotege' => [],
        'wisski' => [],
      ],
    ]);

    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('projectOperationId')->willReturn('operation-1');
    $demoManager->method('activeTemplate')->willReturn($template);

    $progress = $this->createMock(SodaScsProgressHelper::class);
    $progress->method('findLatestStepsByOperation')->willReturn([
      ['message' => 'Importing the ontology'],
    ]);

    $projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $projectApps->method('wisskiStack')->willReturn($this->createMock(SodaScsStackInterface::class));
    $projectApps->method('jupyterStack')->willReturn($this->createMock(SodaScsStackInterface::class));
    $projectApps->method('webprotegeComponent')->willReturn($this->createMock(SodaScsComponentInterface::class));

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('get')->willReturnCallback(static function (string $field): object {
      return match ($field) {
        'demoStatus' => new class() {
          public string $value = 'provisioning';
        },
        'demoTemplate' => new class() {
          public mixed $entity = NULL;
        },
        default => new \stdClass(),
      };
    });

    $builder = new SodaScsDemoStatusBuilder($this->createMock(DateFormatterInterface::class), $demoManager, $progress, $projectApps, $this->clock());
    $builder->setStringTranslation($this->getStringTranslationStub());

    $data = $builder->data($project);

    $this->assertSame(
      [
        ['detail' => 'Started', 'key' => 'wisski', 'label' => 'WissKI'],
        ['detail' => 'Importing the ontology', 'key' => 'webprotege', 'label' => 'WebProtégé'],
      ],
      $data['apps'],
    );
  }

  /**
   * Jupyter is done once it exists, and WebProtégé only stays while importing.
   *
   * @covers ::data
   */
  public function testDataTreatsExistingJupyterAndIdleWebprotegeAsReady(): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getManifest')->willReturn([
      'apps' => [
        'jupyter' => [],
        'webprotege' => [],
        'wisski' => [],
      ],
    ]);

    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('projectOperationId')->willReturn('operation-1');
    $demoManager->method('activeTemplate')->willReturn($template);

    $progress = $this->createMock(SodaScsProgressHelper::class);
    $progress->method('findLatestStepsByOperation')->willReturn([
      ['message' => 'Creating missing applications'],
    ]);

    $projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $projectApps->method('wisskiStack')->willReturn($this->createMock(SodaScsStackInterface::class));
    $projectApps->method('jupyterStack')->willReturn($this->createMock(SodaScsStackInterface::class));
    $projectApps->method('webprotegeComponent')->willReturn($this->createMock(SodaScsComponentInterface::class));

    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('get')->willReturnCallback(static function (string $field): object {
      return match ($field) {
        'demoStatus' => new class() {
          public string $value = 'provisioning';
        },
        'demoTemplate' => new class() {
          public mixed $entity = NULL;
        },
        default => new \stdClass(),
      };
    });

    $builder = new SodaScsDemoStatusBuilder($this->createMock(DateFormatterInterface::class), $demoManager, $progress, $projectApps, $this->clock());
    $builder->setStringTranslation($this->getStringTranslationStub());

    $data = $builder->data($project);

    $this->assertSame(
      [
        ['detail' => 'Started', 'key' => 'wisski', 'label' => 'WissKI'],
      ],
      $data['apps'],
    );
  }

  /**
   * A queued job says since when it waits for a worker.
   *
   * @covers ::data
   * @covers ::jobLine
   */
  public function testJobLineQueuedShowsCreationTime(): void {
    $line = $this->jobLine(['created' => 1000, 'status' => 'queued', 'updated' => 1500], []);

    $this->assertSame('Queued since t1000', $line);
  }

  /**
   * A running job shows the last step it reported.
   *
   * @covers ::jobLine
   */
  public function testJobLineRunningShowsLastStep(): void {
    $line = $this->jobLine(['status' => 'running', 'updated' => 2000], [
      ['created' => 1100.5, 'message' => 'Creating missing applications'],
      ['created' => 1200.5, 'message' => 'Importing the ontology'],
    ]);

    $this->assertSame('Running: Importing the ontology', $line);
  }

  /**
   * The stored source step is shown in the viewer's language.
   *
   * @covers ::stepLabel
   */
  public function testJobLineRunningTranslatesStepForViewer(): void {
    $line = $this->jobLine(['status' => 'running', 'updated' => 2000], [
      ['created' => 1200.5, 'message' => 'Importing the ontology'],
    ], translations: ['Importing the ontology' => 'Die Ontologie wird importiert', 'Running: @step' => 'Läuft: @step']);

    $this->assertSame('Läuft: Die Ontologie wird importiert', $line);
  }

  /**
   * A running job without a step shows since when it runs.
   *
   * Blank steps count as none.
   *
   * @covers ::jobLine
   */
  public function testJobLineRunningWithoutStepShowsUpdateTime(): void {
    $line = $this->jobLine(['status' => 'running', 'updated' => 2000], [
      ['created' => 1100.5, 'message' => '  '],
    ]);

    $this->assertSame('Running since t2000', $line);
  }

  /**
   * A waiting job shows its first wait step and the next attempt.
   *
   * The next attempt is a minute after the last update.
   *
   * @covers ::jobLine
   */
  public function testJobLineWaitingShowsFirstWaitStepAndNextAttempt(): void {
    $line = $this->jobLine(['status' => 'waiting', 'updated' => 2000], [
      ['created' => 1500.0, 'message' => 'Creating missing applications'],
      ['created' => 1600.25, 'message' => 'Waiting for the WissKI installation to finish'],
      ['created' => 1900.0, 'message' => 'Waiting for the WissKI installation to finish'],
    ]);

    $this->assertSame('Waiting for the WissKI installation since t1600, next attempt at t' . (2000 + SodaScsDemoStatusBuilder::RETRY_DELAY_SECONDS), $line);
    $this->assertSame(60, SodaScsDemoStatusBuilder::RETRY_DELAY_SECONDS);
  }

  /**
   * Without a wait step the waiting job counts from its last update.
   *
   * @covers ::jobLine
   */
  public function testJobLineWaitingWithoutWaitStepFallsBackToUpdateTime(): void {
    $line = $this->jobLine(['status' => 'waiting', 'updated' => 2000], [
      ['created' => 1500.0, 'message' => 'Creating missing applications'],
    ]);

    $this->assertSame('Waiting for the WissKI installation since t2000, next attempt at t2060', $line);
  }

  /**
   * A wait step stored translated by an older job still counts.
   *
   * @covers ::waitingSince
   */
  public function testJobLineWaitingFindsWaitStepStoredTranslated(): void {
    $line = $this->jobLine(['status' => 'waiting', 'updated' => 2000], [
      ['created' => 1600.0, 'message' => 'Warten auf die WissKI-Installation'],
    ], translations: ['Waiting for the WissKI installation to finish' => 'Warten auf die WissKI-Installation']);

    $this->assertSame('Waiting for the WissKI installation since t1600, next attempt at t2060', $line);
  }

  /**
   * A next attempt that is a full retry delay overdue is left out.
   *
   * @covers ::waitingLine
   */
  public function testJobLineWaitingDropsOverdueNextAttempt(): void {
    $steps = [['created' => 1600.0, 'message' => 'Waiting for the WissKI installation to finish']];

    $this->assertSame(
      'Waiting for the WissKI installation since t1600, next attempt at t2060',
      $this->jobLine(['status' => 'waiting', 'updated' => 2000], $steps, now: 2120),
    );
    $this->assertSame(
      'Waiting for the WissKI installation since t1600',
      $this->jobLine(['status' => 'waiting', 'updated' => 2000], $steps, now: 2121),
    );
  }

  /**
   * A failed job shows the stored reason, translated.
   *
   * @covers ::failureReason
   * @covers ::jobLine
   */
  public function testJobLineFailedShowsStoredReason(): void {
    $translations = [
      SodaScsDemoStatusBuilder::REASON_TEMPLATE_GONE => 'Die Demo-Vorlage existiert nicht mehr.',
      SodaScsDemoStatusBuilder::REASON_TIMEOUT => 'Die WissKI-Installation wurde nicht innerhalb einer Stunde fertig.',
      SodaScsDemoStatusBuilder::REASON_WISSKI_INCOMPLETE => 'Das WissKI dieser Demo ist unvollständig.',
    ];
    foreach ($translations as $reason => $translated) {
      $line = $this->jobLine(['status' => 'failed', 'updated' => 2000], [
        ['created' => 1500.0, 'message' => 'Creating missing applications'],
        ['created' => 1600.0, 'message' => $reason],
      ], translations: $translations);

      $this->assertSame($translated, $line);
    }
  }

  /**
   * A failed job whose last step is a setup step names that step.
   *
   * @covers ::failureReason
   */
  public function testJobLineFailedAtStepNamesTranslatedStep(): void {
    $line = $this->jobLine(['status' => 'failed', 'updated' => 2000], [
      ['created' => 1500.0, 'message' => 'Importing the ontology'],
    ], translations: [
      'Importing the ontology' => 'Die Ontologie wird importiert',
      'Setting up the demo failed at the step: @step' => 'Fehlgeschlagen bei: @step',
    ]);

    $this->assertSame('Fehlgeschlagen bei: Die Ontologie wird importiert', $line);
  }

  /**
   * A reason stored as text by an older job is shown as it is.
   *
   * @covers ::failureReason
   */
  public function testJobLineFailedShowsUnknownLastStepAsIs(): void {
    $line = $this->jobLine(['status' => 'failed', 'updated' => 2000], [
      ['created' => 1500.0, 'message' => 'Das Einrichten der Demo ist fehlgeschlagen.'],
    ]);

    $this->assertSame('Das Einrichten der Demo ist fehlgeschlagen.', $line);
  }

  /**
   * A failed job without a step shows when it failed.
   *
   * @covers ::jobLine
   */
  public function testJobLineFailedWithoutStepShowsUpdateTime(): void {
    $line = $this->jobLine(['status' => 'failed', 'updated' => 2000], []);

    $this->assertSame('Failed at t2000', $line);
  }

  /**
   * A completed job and an unknown status have no job line.
   *
   * @covers ::jobLine
   */
  public function testJobLineIsEmptyForCompletedAndUnknownStatus(): void {
    $steps = [['created' => 1500.0, 'message' => 'The demo project is ready.']];

    $this->assertSame('', $this->jobLine(['status' => 'completed', 'updated' => 2000], $steps));
    $this->assertSame('', $this->jobLine(['status' => 'something-else', 'updated' => 2000], $steps));
    $this->assertSame('', $this->jobLine(['updated' => 2000], $steps));
  }

  /**
   * A project without an operation or with an unreadable one has no job line.
   *
   * @covers ::jobLine
   */
  public function testJobLineIsEmptyWithoutOperation(): void {
    $this->assertSame('', $this->jobLine(NULL, []));
    $this->assertSame('', $this->jobLine(['status' => 'running'], [], NULL));
  }

  /**
   * The panel and badge render array carries the job line.
   *
   * @covers ::build
   */
  public function testBuildPassesJobLineToRenderArray(): void {
    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturn('/demo/url');
    $container = new ContainerBuilder();
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);

    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('isDemoProject')->willReturn(TRUE);
    $demoManager->method('activeTemplate')->willReturn(NULL);
    $demoManager->method('projectOperationId')->willReturn('operation-1');

    $progress = $this->createMock(SodaScsProgressHelper::class);
    $progress->method('readOperation')->willReturn(['status' => 'failed', 'updated' => 2000]);
    $progress->method('findLatestStepsByOperation')->willReturn([]);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);

    $project = $this->createProjectWithStatus('failed');
    $project->method('getOwnerId')->willReturn(7);
    $project->method('id')->willReturn(3);

    $builder = new SodaScsDemoStatusBuilder($this->dateFormatter(), $demoManager, $progress, $this->createMock(SodaScsDemoProjectApps::class), $this->clock());
    $builder->setStringTranslation($this->getStringTranslationStub());

    $build = $builder->build($account, $project, SodaScsDemoStatusBuilder::VARIANT_PANEL);

    $this->assertSame('Failed at t2000', $build['#job_line']);
  }

  /**
   * The reset action is offered when the reset route grants the viewer access.
   *
   * @covers ::build
   */
  public function testBuildOffersResetUrlWhenRouteGrantsAccess(): void {
    $build = $this->buildResetStatus(TRUE);

    $this->assertSame('/demo/url', $build['#reset_url']);
  }

  /**
   * No reset action when the reset route denies the viewer access.
   *
   * @covers ::build
   */
  public function testBuildOmitsResetUrlWhenRouteDeniesAccess(): void {
    $build = $this->buildResetStatus(FALSE);

    $this->assertSame('', $build['#reset_url']);
  }

  /**
   * No reset action while a queue job is running, even with access.
   *
   * @covers ::build
   */
  public function testBuildOmitsResetUrlWhileBusy(): void {
    $build = $this->buildResetStatus(TRUE, 'resetting');

    $this->assertSame('', $build['#reset_url']);
  }

  /**
   * No reset action without an active template version, even with access.
   *
   * @covers ::build
   */
  public function testBuildOmitsResetUrlWithoutActiveTemplate(): void {
    $build = $this->buildResetStatus(TRUE, 'ready', FALSE);

    $this->assertSame('', $build['#reset_url']);
  }

  /**
   * Clock at the given time.
   */
  private function clock(int $now = 2000): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    return $time;
  }

  /**
   * Translation service with the given translations of the viewer's language.
   *
   * @param array<string, string> $translations
   *   Translations by source string; other strings stay as they are.
   */
  private function translator(array $translations): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translateString')->willReturnCallback(static fn (TranslatableMarkup $markup): string => $translations[$markup->getUntranslatedString()] ?? $markup->getUntranslatedString());
    return $translator;
  }

  /**
   * Date formatter that returns "t<timestamp>" and only accepts the H:i format.
   */
  private function dateFormatter(): DateFormatterInterface {
    $test = $this;
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->method('format')->willReturnCallback(static function (int $timestamp, string $type = 'medium', string $format = '') use ($test): string {
      $test->assertSame('custom', $type);
      $test->assertSame('H:i', $format);
      return 't' . $timestamp;
    });
    return $formatter;
  }

  /**
   * Project mock with a demo status.
   */
  private function createProjectWithStatus(string $status): SodaScsProjectInterface {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('get')->willReturnCallback(static function (string $field) use ($status): object {
      return match ($field) {
        'demoStatus' => (object) ['value' => $status],
        'demoTemplate' => (object) ['entity' => NULL],
        default => new \stdClass(),
      };
    });
    return $project;
  }

  /**
   * Job line of data() for an operation row and its steps.
   *
   * @param array<string, mixed>|null $operation
   *   The operation row, NULL when it cannot be read.
   * @param list<array{created: float|int, message: string}> $steps
   *   Steps in chronological order.
   * @param string|null $operationId
   *   Operation ID the project has; NULL for none. Defaults to a fixed ID
   *   unless the operation row is NULL.
   * @param int $now
   *   Current time.
   * @param array<string, string> $translations
   *   Translations of the viewer's language by source string.
   */
  private function jobLine(?array $operation, array $steps, ?string $operationId = 'operation-1', int $now = 2000, array $translations = []): string {
    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('projectOperationId')->willReturn($operation === NULL ? NULL : $operationId);

    $progress = $this->createMock(SodaScsProgressHelper::class);
    $progress->method('readOperation')->willReturn($operation);
    $progress->method('findLatestStepsByOperation')->willReturn($steps);

    $builder = new SodaScsDemoStatusBuilder($this->dateFormatter(), $demoManager, $progress, $this->createMock(SodaScsDemoProjectApps::class), $this->clock($now));
    $builder->setStringTranslation($this->translator($translations));

    return $builder->data($this->createProjectWithStatus('failed'))['jobLine'];
  }

  /**
   * Render array of build() for a demo project and a viewer.
   *
   * @param bool $routeGrantsAccess
   *   Whether the access manager allows the viewer the reset route.
   * @param string $status
   *   Stored demo status of the project.
   * @param bool $hasActiveTemplate
   *   Whether the demo manager has an active template version.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function buildResetStatus(bool $routeGrantsAccess, string $status = 'ready', bool $hasActiveTemplate = TRUE): array {
    $account = $this->createMock(AccountInterface::class);

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturn('/demo/url');
    $accessManager = $this->createMock(AccessManagerInterface::class);
    $accessManager->method('checkNamedRoute')
      ->with('soda_scs_manager.demo_project_reset', ['soda_scs_project' => 3], $account)
      ->willReturn($routeGrantsAccess);
    $container = new ContainerBuilder();
    $container->set('url_generator', $urlGenerator);
    $container->set('access_manager', $accessManager);
    \Drupal::setContainer($container);

    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getVersion')->willReturn('1.0.0');

    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('isDemoProject')->willReturn(TRUE);
    $demoManager->method('activeTemplate')->willReturn($hasActiveTemplate ? $template : NULL);
    $demoManager->method('projectOperationId')->willReturn(NULL);

    $project = $this->createProjectWithStatus($status);
    $project->method('id')->willReturn(3);

    $builder = new SodaScsDemoStatusBuilder(
      $this->dateFormatter(),
      $demoManager,
      $this->createMock(SodaScsProgressHelper::class),
      $this->createMock(SodaScsDemoProjectApps::class),
      $this->clock(),
    );
    $builder->setStringTranslation($this->getStringTranslationStub());

    return $builder->build($account, $project, SodaScsDemoStatusBuilder::VARIANT_PANEL);
  }

}
