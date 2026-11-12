<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Install;

use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests soda_scs_manager_update_11040() (withDefaultDataModel on WissKI).
 *
 * @group soda_scs_manager
 */
final class SodaScsUpdate11040Test extends SodaScsKernelTestBase {

  private const FIELD_NAME = 'withDefaultDataModel';

  private const ENTITY_TYPE_IDS = ['soda_scs_stack', 'soda_scs_component'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('soda_scs_manager', 'install');
  }

  /**
   * The update installs the field storage on stacks and components.
   *
   * A site that was installed before the field existed has no installed
   * definition for it; the test removes it to get that state.
   */
  public function testUpdateInstallsFieldStorageOnStackAndComponent(): void {
    $this->uninstallFieldStorage();
    foreach (self::ENTITY_TYPE_IDS as $entityTypeId) {
      $this->assertNull($this->installedDefinition($entityTypeId), "Precondition: $entityTypeId has no field.");
    }

    $message = soda_scs_manager_update_11040();

    foreach (self::ENTITY_TYPE_IDS as $entityTypeId) {
      $definition = $this->installedDefinition($entityTypeId);
      $this->assertNotNull($definition, "$entityTypeId has the field after the update.");
      $this->assertSame('boolean', $definition->getType());
      $this->assertStringContainsString("Installed " . self::FIELD_NAME . " on $entityTypeId.", $message);
    }
  }

  /**
   * Running the update twice skips what is installed and fails on nothing.
   */
  public function testUpdateSkipsInstalledFieldStorage(): void {
    $this->uninstallFieldStorage();
    soda_scs_manager_update_11040();

    $message = soda_scs_manager_update_11040();

    foreach (self::ENTITY_TYPE_IDS as $entityTypeId) {
      $this->assertNotNull($this->installedDefinition($entityTypeId));
      $this->assertStringContainsString('already exists on ' . $entityTypeId . ', skipping.', $message);
    }
    $this->assertStringNotContainsString('Installed', $message);
  }

  /**
   * Removes the installed field storage definitions.
   */
  private function uninstallFieldStorage(): void {
    $updateManager = $this->container->get('entity.definition_update_manager');
    foreach (self::ENTITY_TYPE_IDS as $entityTypeId) {
      $definition = $updateManager->getFieldStorageDefinition(self::FIELD_NAME, $entityTypeId);
      if ($definition) {
        $updateManager->uninstallFieldStorageDefinition($definition);
      }
    }
  }

  /**
   * Returns the installed field storage definition, if any.
   */
  private function installedDefinition(string $entityTypeId): mixed {
    return $this->container->get('entity.definition_update_manager')
      ->getFieldStorageDefinition(self::FIELD_NAME, $entityTypeId);
  }

}
