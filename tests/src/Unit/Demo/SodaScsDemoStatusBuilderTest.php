<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

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
      $demoManager,
      $this->createMock(SodaScsProgressHelper::class),
      $projectApps,
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

    $builder = new SodaScsDemoStatusBuilder($demoManager, $progress, $projectApps);
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

    $builder = new SodaScsDemoStatusBuilder($demoManager, $progress, $projectApps);
    $builder->setStringTranslation($this->getStringTranslationStub());

    $data = $builder->data($project);

    $this->assertSame(
      [
        ['detail' => 'Started', 'key' => 'wisski', 'label' => 'WissKI'],
      ],
      $data['apps'],
    );
  }

}
