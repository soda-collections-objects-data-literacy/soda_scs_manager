<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Install;

use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests soda_scs_manager_update_11044() (origin of demo templates).
 *
 * Issue 3640915. A site that was installed before the field existed has no
 * installed definition for it; the test removes it to get that state.
 *
 * @group soda_scs_manager
 */
final class SodaScsUpdate11044Test extends SodaScsKernelTestBase {

  private const ENTITY_TYPE_ID = 'soda_scs_demo_template';

  /**
   * {@inheritdoc}
   */
  protected function installSchemas(): void {
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('soda_scs_manager', ['keycloak_user_registration']);
    $this->installEntitySchema('user');
    $this->installEntitySchema(self::ENTITY_TYPE_ID);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('soda_scs_manager', 'install');
  }

  /**
   * The update installs the field storage.
   */
  public function testUpdateInstallsTheOriginField(): void {
    $this->uninstallOriginField();
    $this->assertNull($this->installedDefinition(), 'Precondition: no origin field.');

    $message = soda_scs_manager_update_11044();

    $this->assertNotNull($this->installedDefinition());
    $this->assertStringContainsString('Added the origin field', (string) $message);
  }

  /**
   * Versions that existed before the update count as built here.
   */
  public function testExistingVersionsCountAsBuilt(): void {
    $id = $this->createTemplate()->id();
    $this->uninstallOriginField();

    soda_scs_manager_update_11044();

    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE_ID);
    $storage->resetCache([$id]);
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template */
    $template = $storage->load($id);
    $this->assertSame('built', $template->get('origin')->value, 'The stored value is the initial value, not the getter fallback.');
    $this->assertSame('built', $template->getOrigin());
    $this->assertFalse($template->isImported());
  }

  /**
   * The imported origin can be stored after the update.
   */
  public function testImportedOriginCanBeStoredAfterTheUpdate(): void {
    $this->uninstallOriginField();
    soda_scs_manager_update_11044();

    $template = $this->createTemplate(['origin' => SodaScsDemoTemplateInterface::ORIGIN_IMPORTED]);

    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE_ID);
    $storage->resetCache([$template->id()]);
    $this->assertTrue($storage->load($template->id())->isImported());
  }

  /**
   * A second run finds the field and changes nothing.
   */
  public function testUpdateTwiceKeepsTheField(): void {
    $this->uninstallOriginField();
    soda_scs_manager_update_11044();

    $message = soda_scs_manager_update_11044();

    $this->assertNotNull($this->installedDefinition());
    $this->assertStringContainsString('exists already', (string) $message);
  }

  /**
   * Removes the installed field storage definition.
   */
  private function uninstallOriginField(): void {
    $updateManager = $this->container->get('entity.definition_update_manager');
    $definition = $updateManager->getFieldStorageDefinition('origin', self::ENTITY_TYPE_ID);
    if ($definition) {
      $updateManager->uninstallFieldStorageDefinition($definition);
    }
  }

  /**
   * The installed field storage definition, if any.
   */
  private function installedDefinition(): mixed {
    return $this->container->get('entity.definition_update_manager')->getFieldStorageDefinition('origin', self::ENTITY_TYPE_ID);
  }

  /**
   * Creates and saves a version.
   */
  private function createTemplate(array $values = []): SodaScsDemoTemplateInterface {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template */
    $template = $this->entityTypeManager->getStorage(self::ENTITY_TYPE_ID)->create($values + [
      'label' => 'Demo v1.0.0',
      'status' => SodaScsDemoTemplateInterface::STATUS_READY,
      'version' => '1.0.0',
    ]);
    $template->save();
    return $template;
  }

}
