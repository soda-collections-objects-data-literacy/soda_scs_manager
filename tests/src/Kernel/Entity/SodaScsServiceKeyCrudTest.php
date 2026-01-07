<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Entity;

use Drupal\soda_scs_manager\Entity\SodaScsServiceKey;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests CRUD operations for SODa SCS ServiceKey entities.
 *
 * @group soda_scs_manager
 */
class SodaScsServiceKeyCrudTest extends SodaScsKernelTestBase {

  /**
   * Tests creating a service key entity.
   */
  public function testCreateServiceKey(): void {
    $label = 'Test Service Key ' . $this->randomMachineName();
    $servicePassword = $this->randomMachineName(32);

    $serviceKey = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->create([
        'bundle' => 'default',
        'label' => $label,
        'owner' => $this->testUser->id(),
        'servicePassword' => $servicePassword,
        'scsComponentBundle' => 'soda_scs_sql_component',
        'type' => 'password',
      ]);

    $this->assertInstanceOf(SodaScsServiceKey::class, $serviceKey);
    $this->assertEquals('default', $serviceKey->bundle());
    $this->assertEquals($label, $serviceKey->get('label')->value);
    $this->assertEquals($servicePassword, $serviceKey->get('servicePassword')->value);
    $this->assertEquals('soda_scs_sql_component', $serviceKey->get('scsComponentBundle')->value);
    $this->assertEquals('password', $serviceKey->get('type')->value);

    // Save and verify ID is assigned.
    $serviceKey->save();
    $this->assertNotNull($serviceKey->id());
    $this->assertIsNumeric($serviceKey->id());
  }

  /**
   * Tests reading a service key entity.
   */
  public function testReadServiceKey(): void {
    // Create a service key first.
    $serviceKey = $this->createTestServiceKey();
    $serviceKeyId = $serviceKey->id();

    // Clear entity cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_service_key')->resetCache([$serviceKeyId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $loadedServiceKey */
    $loadedServiceKey = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->load($serviceKeyId);

    $this->assertNotNull($loadedServiceKey);
    $this->assertEquals($serviceKey->get('label')->value, $loadedServiceKey->get('label')->value);
    $this->assertEquals($serviceKey->get('servicePassword')->value, $loadedServiceKey->get('servicePassword')->value);
    $this->assertEquals('default', $loadedServiceKey->bundle());
  }

  /**
   * Tests updating a service key entity.
   */
  public function testUpdateServiceKey(): void {
    // Create a service key first.
    $serviceKey = $this->createTestServiceKey();
    $serviceKeyId = $serviceKey->id();

    // Update the service password.
    $newPassword = $this->randomMachineName(32);
    $serviceKey->set('servicePassword', $newPassword);
    $serviceKey->save();

    // Clear cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_service_key')->resetCache([$serviceKeyId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $loadedServiceKey */
    $loadedServiceKey = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->load($serviceKeyId);

    $this->assertEquals($newPassword, $loadedServiceKey->get('servicePassword')->value);
  }

  /**
   * Tests deleting a service key entity.
   */
  public function testDeleteServiceKey(): void {
    // Create a service key first.
    $serviceKey = $this->createTestServiceKey();
    $serviceKeyId = $serviceKey->id();

    // Verify it exists.
    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_service_key')->load($serviceKeyId)
    );

    // Delete the service key.
    $serviceKey->delete();

    // Clear cache and verify deletion.
    $this->entityTypeManager->getStorage('soda_scs_service_key')->resetCache([$serviceKeyId]);
    $this->assertNull(
      $this->entityTypeManager->getStorage('soda_scs_service_key')->load($serviceKeyId)
    );
  }

  /**
   * Tests the loadByOwner static method.
   */
  public function testLoadByOwner(): void {
    // Create service keys for the test user.
    $serviceKey1 = $this->createTestServiceKey();
    $serviceKey2 = $this->createTestServiceKey();

    // Create another user with a service key.
    $otherUser = $this->createTestUser();
    $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->create([
        'bundle' => 'default',
        'label' => 'Other User Service Key',
        'owner' => $otherUser->id(),
        'servicePassword' => $this->randomMachineName(32),
        'scsComponentBundle' => 'soda_scs_sql_component',
        'type' => 'password',
      ])
      ->save();

    // Load service keys by owner.
    $serviceKeys = SodaScsServiceKey::loadByOwner($this->testUser->id());

    $this->assertCount(2, $serviceKeys);
    $serviceKeyIds = array_map(fn($s) => $s->id(), $serviceKeys);
    $this->assertContains($serviceKey1->id(), $serviceKeyIds);
    $this->assertContains($serviceKey2->id(), $serviceKeyIds);
  }

  /**
   * Tests service key with component reference.
   */
  public function testServiceKeyWithComponent(): void {
    // Create a component.
    $component = $this->createTestComponent('soda_scs_sql_component');

    // Create a service key with component reference.
    $serviceKey = $this->createTestServiceKey('default', [
      'scsComponent' => [$component->id()],
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_service_key')->resetCache([$serviceKey->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $loadedServiceKey */
    $loadedServiceKey = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->load($serviceKey->id());

    $components = $loadedServiceKey->get('scsComponent')->referencedEntities();
    $this->assertCount(1, $components);
    $this->assertEquals($component->id(), $components[0]->id());
  }

  /**
   * Tests service key type field - password.
   */
  public function testServiceKeyTypePassword(): void {
    $serviceKey = $this->createTestServiceKey('default', [
      'type' => 'password',
    ]);

    $this->assertEquals('password', $serviceKey->get('type')->value);
  }

  /**
   * Tests service key type field - token.
   */
  public function testServiceKeyTypeToken(): void {
    $serviceKey = $this->createTestServiceKey('default', [
      'type' => 'token',
    ]);

    $this->assertEquals('token', $serviceKey->get('type')->value);
  }

  /**
   * Tests service key component bundle field.
   */
  public function testServiceKeyComponentBundle(): void {
    $bundles = [
      'soda_scs_sql_component',
      'soda_scs_triplestore_component',
      'soda_scs_wisski_component',
    ];

    foreach ($bundles as $bundle) {
      $serviceKey = $this->createTestServiceKey('default', [
        'scsComponentBundle' => $bundle,
      ]);

      $this->assertEquals($bundle, $serviceKey->get('scsComponentBundle')->value);
    }
  }

  /**
   * Tests loading service key by properties.
   */
  public function testLoadByProperties(): void {
    $label = 'Unique Service Key ' . $this->randomMachineName();

    $serviceKey = $this->createTestServiceKey('default', [
      'label' => $label,
    ]);

    $loaded = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->loadByProperties(['label' => $label]);

    $this->assertCount(1, $loaded);
    $this->assertEquals($serviceKey->id(), reset($loaded)->id());
  }

  /**
   * Tests finding service key by owner and bundle combination.
   */
  public function testFindServiceKeyByOwnerAndBundle(): void {
    // Create service keys with different bundles.
    $sqlServiceKey = $this->createTestServiceKey('default', [
      'scsComponentBundle' => 'soda_scs_sql_component',
      'type' => 'password',
    ]);

    $triplestoreServiceKey = $this->createTestServiceKey('default', [
      'scsComponentBundle' => 'soda_scs_triplestore_component',
      'type' => 'password',
    ]);

    // Find by owner and bundle.
    $found = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->loadByProperties([
        'owner' => $this->testUser->id(),
        'scsComponentBundle' => 'soda_scs_sql_component',
        'type' => 'password',
      ]);

    $this->assertCount(1, $found);
    $this->assertEquals($sqlServiceKey->id(), reset($found)->id());
  }

  /**
   * Tests service key owner reference.
   */
  public function testServiceKeyOwner(): void {
    $serviceKey = $this->createTestServiceKey();

    // Verify owner is set correctly.
    $ownerField = $serviceKey->get('owner');
    $this->assertFalse($ownerField->isEmpty());
    $this->assertEquals($this->testUser->id(), $ownerField->target_id);

    // Verify owner entity can be loaded.
    $owner = $ownerField->entity;
    $this->assertNotNull($owner);
    $this->assertEquals($this->testUser->id(), $owner->id());
  }

  /**
   * Tests multiple service keys for the same component.
   */
  public function testMultipleServiceKeysForComponent(): void {
    // Create a component.
    $component = $this->createTestComponent('soda_scs_sql_component');

    // Create multiple service keys for the same component.
    $serviceKey1 = $this->createTestServiceKey('default', [
      'scsComponent' => [$component->id()],
      'type' => 'password',
    ]);

    $serviceKey2 = $this->createTestServiceKey('default', [
      'scsComponent' => [$component->id()],
      'type' => 'token',
    ]);

    // Verify both can be loaded.
    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_service_key')->load($serviceKey1->id())
    );
    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_service_key')->load($serviceKey2->id())
    );
  }

  /**
   * Tests service key getComponent method.
   */
  public function testServiceKeyGetComponent(): void {
    // Create a component.
    $component = $this->createTestComponent('soda_scs_sql_component');

    // Create a service key with component reference.
    $serviceKey = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->create([
        'bundle' => 'default',
        'label' => 'Test Service Key',
        'owner' => $this->testUser->id(),
        'servicePassword' => $this->randomMachineName(32),
        'scsComponentBundle' => 'soda_scs_sql_component',
        'scsComponent' => [$component->id()],
        'type' => 'password',
      ]);
    $serviceKey->save();

    // Test getComponent method.
    $loadedComponent = $serviceKey->getComponent();
    $this->assertNotNull($loadedComponent);
    $this->assertEquals($component->id(), $loadedComponent->id());
  }

  /**
   * Tests service key setComponent method.
   */
  public function testServiceKeySetComponent(): void {
    // Create a service key without component.
    $serviceKey = $this->createTestServiceKey();

    // Create a component.
    $component = $this->createTestComponent('soda_scs_sql_component');

    // Set component using setComponent method.
    $serviceKey->setComponent($component);
    $serviceKey->save();

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_service_key')->resetCache([$serviceKey->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $loadedServiceKey */
    $loadedServiceKey = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->load($serviceKey->id());

    $loadedComponent = $loadedServiceKey->getComponent();
    $this->assertNotNull($loadedComponent);
    $this->assertEquals($component->id(), $loadedComponent->id());
  }

  /**
   * Tests service key password field length.
   */
  public function testServiceKeyPasswordLength(): void {
    // Test with various password lengths.
    $lengths = [16, 32, 64, 128];

    foreach ($lengths as $length) {
      $password = $this->randomMachineName($length);
      $serviceKey = $this->createTestServiceKey('default', [
        'servicePassword' => $password,
      ]);

      $this->assertEquals($password, $serviceKey->get('servicePassword')->value);
      $this->assertEquals($length, strlen($serviceKey->get('servicePassword')->value));
    }
  }

}
