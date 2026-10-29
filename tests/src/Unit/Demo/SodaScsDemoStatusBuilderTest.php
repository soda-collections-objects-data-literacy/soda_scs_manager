<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder;
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
   * Busy status lists template apps in setup order and flags existing ones.
   *
   * @covers ::data
   */
  public function testDataListsPresentAppsInSetupOrder(): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getManifest')->willReturn([
      'apps' => [
        'webprotege' => [],
        'database' => [],
        'wisski' => [],
        'jupyter' => [],
      ],
    ]);

    $demoManager = $this->createMock(SodaScsDemoManager::class);
    $demoManager->method('projectOperationId')->willReturn(NULL);
    $demoManager->method('activeTemplate')->willReturn($template);

    $projectApps = $this->createMock(SodaScsDemoProjectApps::class);
    $projectApps->method('wisskiStack')->willReturn($this->createMock(SodaScsStackInterface::class));
    $projectApps->method('jupyterStack')->willReturn(NULL);
    $projectApps->method('standaloneComponents')->willReturn([]);
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
    $this->assertSame(
      [
        ['current' => FALSE, 'key' => 'wisski', 'label' => 'WissKI', 'present' => TRUE],
        ['current' => TRUE, 'key' => 'jupyter', 'label' => 'JupyterHub', 'present' => FALSE],
        ['current' => FALSE, 'key' => 'database', 'label' => 'SQL Database', 'present' => FALSE],
        ['current' => FALSE, 'key' => 'webprotege', 'label' => 'WebProtégé', 'present' => FALSE],
      ],
      $data['apps'],
    );
  }

}
