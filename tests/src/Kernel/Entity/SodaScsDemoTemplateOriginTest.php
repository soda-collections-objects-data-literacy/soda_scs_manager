<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Entity;

use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the origin of demo template versions (built here or imported).
 *
 * Issue 3640915.
 */
#[Group('soda_scs_manager')]
final class SodaScsDemoTemplateOriginTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function installSchemas(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('soda_scs_manager', ['keycloak_user_registration']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('soda_scs_demo_template');
  }

  /**
   * A version created without an origin is built here.
   */
  public function testOriginDefaultsToBuilt(): void {
    $template = $this->createTemplate('1.0.0');

    $this->assertSame(SodaScsDemoTemplateInterface::ORIGIN_BUILT, $template->getOrigin());
    $this->assertFalse($template->isImported());
  }

  /**
   * An imported version keeps its origin through save and load.
   */
  public function testImportedOriginSurvivesSaveAndLoad(): void {
    $id = $this->createTemplate('1.1.0', ['origin' => SodaScsDemoTemplateInterface::ORIGIN_IMPORTED])->id();
    $storage = $this->entityTypeManager->getStorage('soda_scs_demo_template');
    $storage->resetCache([$id]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $loaded */
    $loaded = $storage->load($id);

    $this->assertSame('imported', $loaded->getOrigin());
    $this->assertTrue($loaded->isImported());
  }

  /**
   * A stored empty origin counts as built, as for rows from before the field.
   */
  public function testEmptyOriginCountsAsBuilt(): void {
    $template = $this->createTemplate('1.2.0');
    $template->set('origin', '');

    $this->assertSame('built', $template->getOrigin());
    $this->assertFalse($template->isImported());
  }

  /**
   * An origin that is neither value is not imported.
   */
  public function testUnknownOriginIsNotImported(): void {
    $template = $this->createTemplate('1.3.0', ['origin' => 'something']);

    $this->assertFalse($template->isImported());
  }

  /**
   * Creates and saves a version.
   *
   * @param string $version
   *   The version.
   * @param array $values
   *   Further field values.
   */
  private function createTemplate(string $version, array $values = []): SodaScsDemoTemplateInterface {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template */
    $template = $this->entityTypeManager->getStorage('soda_scs_demo_template')->create($values + [
      'label' => 'Demo v' . $version,
      'status' => SodaScsDemoTemplateInterface::STATUS_READY,
      'version' => $version,
    ]);
    $template->save();
    return $template;
  }

}
