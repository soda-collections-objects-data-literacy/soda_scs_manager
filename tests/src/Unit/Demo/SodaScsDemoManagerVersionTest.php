<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsDemoManager
 * @group soda_scs_manager
 */
class SodaScsDemoManagerVersionTest extends UnitTestCase {

  /**
   * @covers ::validateNewVersion
   */
  public function testFailedVersionCanBeRebuilt(): void {
    $manager = $this->manager($this->template(SodaScsDemoTemplateInterface::STATUS_FAILED), '1.0.0');

    $this->assertNull($manager->validateNewVersion('1.0.0'));
  }

  /**
   * @covers ::validateNewVersion
   */
  public function testReadyVersionKeepsItsNumber(): void {
    $manager = $this->manager($this->template(SodaScsDemoTemplateInterface::STATUS_READY), '1.0.0');

    $this->assertNotNull($manager->validateNewVersion('1.0.0'));
  }

  /**
   * @covers ::validateNewVersion
   */
  public function testBuildingVersionKeepsItsNumber(): void {
    $manager = $this->manager($this->template(SodaScsDemoTemplateInterface::STATUS_BUILDING), '1.0.0');

    $this->assertNotNull($manager->validateNewVersion('1.0.0'));
  }

  /**
   * @covers ::validateNewVersion
   */
  public function testNewNumberMustBeHigherThanTheLatest(): void {
    $manager = $this->manager(NULL, '1.0.0');

    $this->assertNotNull($manager->validateNewVersion('1.0.0'));
    $this->assertNull($manager->validateNewVersion('1.0.1'));
  }

  /**
   * Manager with a stubbed lookup of one version.
   */
  protected function manager(?SodaScsDemoTemplateInterface $existing, ?string $latest): SodaScsDemoManager {
    $manager = $this->getMockBuilder(SodaScsDemoManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['templateWithVersion', 'latestVersion'])
      ->getMock();
    $manager->setStringTranslation($this->getStringTranslationStub());
    $manager->method('templateWithVersion')->willReturn($existing);
    $manager->method('latestVersion')->willReturn($latest);
    return $manager;
  }

  /**
   * Template stub with a status.
   */
  protected function template(string $status): SodaScsDemoTemplateInterface {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('getStatus')->willReturn($status);
    return $template;
  }

}
