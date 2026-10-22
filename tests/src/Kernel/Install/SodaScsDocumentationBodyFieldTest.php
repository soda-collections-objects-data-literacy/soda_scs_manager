<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Install;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests the optional body field config of the Documentation content type.
 *
 * Drupal 11.3+ no longer creates field.storage.node.body on install, so the
 * module ships it as optional config; a site that already has it keeps it.
 *
 * @group soda_scs_manager
 */
final class SodaScsDocumentationBodyFieldTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['filter'];

  /**
   * Tests that installing the module config creates the body storage.
   *
   * The parent setUp() installs the module config on a site without
   * field.storage.node.body; before the fix that aborted the install.
   */
  public function testCreatesMissingBodyStorage(): void {
    $this->assertNotNull(FieldStorageConfig::loadByName('node', 'body'));
    $this->assertNotNull(FieldConfig::loadByName('node', 'documentation', 'body'));
  }

  /**
   * Tests that an existing body storage is kept.
   */
  public function testKeepsExistingBodyStorage(): void {
    FieldConfig::loadByName('node', 'documentation', 'body')->delete();
    FieldStorageConfig::loadByName('node', 'body')?->delete();
    $existing = FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name'  => 'body',
      'type'        => 'text_with_summary',
    ]);
    $existing->save();

    $this->container->get('config.installer')->installOptionalConfig();

    $this->assertSame($existing->uuid(), FieldStorageConfig::loadByName('node', 'body')->uuid());
    $this->assertNotNull(FieldConfig::loadByName('node', 'documentation', 'body'));
  }

}
