<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Entity;

use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests CRUD operations for SODa SCS Snapshot entities.
 *
 * @group soda_scs_manager
 */
class SodaScsSnapshotCrudTest extends SodaScsKernelTestBase {

  /**
   * Tests creating a snapshot entity.
   */
  public function testCreateSnapshot(): void {
    $label = 'Test Snapshot ' . $this->randomMachineName();
    $machineName = 'test-snapshot-' . strtolower($this->randomMachineName());
    $checksum = hash('sha256', $this->randomMachineName());
    $dir = '/tmp/snapshots/test';

    $snapshot = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->create([
        'label' => $label,
        'machineName' => $machineName,
        'owner' => $this->testUser->id(),
        'checksum' => $checksum,
        'dir' => $dir,
      ]);

    $this->assertInstanceOf(SodaScsSnapshot::class, $snapshot);
    $this->assertEquals($label, $snapshot->get('label')->value);
    $this->assertEquals($machineName, $snapshot->get('machineName')->value);
    $this->assertEquals($checksum, $snapshot->get('checksum')->value);
    $this->assertEquals($dir, $snapshot->get('dir')->value);

    // Save and verify ID is assigned.
    $snapshot->save();
    $this->assertNotNull($snapshot->id());
    $this->assertIsNumeric($snapshot->id());
  }

  /**
   * Tests reading a snapshot entity.
   */
  public function testReadSnapshot(): void {
    // Create a snapshot first.
    $snapshot = $this->createTestSnapshot();
    $snapshotId = $snapshot->id();

    // Clear entity cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$snapshotId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $loadedSnapshot */
    $loadedSnapshot = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->load($snapshotId);

    $this->assertNotNull($loadedSnapshot);
    $this->assertEquals($snapshot->get('label')->value, $loadedSnapshot->get('label')->value);
    $this->assertEquals($snapshot->get('machineName')->value, $loadedSnapshot->get('machineName')->value);
    $this->assertEquals($snapshot->get('checksum')->value, $loadedSnapshot->get('checksum')->value);
  }

  /**
   * Tests updating a snapshot entity.
   */
  public function testUpdateSnapshot(): void {
    // Create a snapshot first.
    $snapshot = $this->createTestSnapshot();
    $snapshotId = $snapshot->id();
    $originalLabel = $snapshot->get('label')->value;

    // Update the label.
    $newLabel = 'Updated ' . $originalLabel;
    $snapshot->set('label', $newLabel);
    $snapshot->save();

    // Clear cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$snapshotId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $loadedSnapshot */
    $loadedSnapshot = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->load($snapshotId);

    $this->assertEquals($newLabel, $loadedSnapshot->get('label')->value);
  }

  /**
   * Tests deleting a snapshot entity.
   */
  public function testDeleteSnapshot(): void {
    // Create a snapshot first.
    $snapshot = $this->createTestSnapshot();
    $snapshotId = $snapshot->id();

    // Verify it exists.
    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_snapshot')->load($snapshotId)
    );

    // Delete the snapshot.
    $snapshot->delete();

    // Clear cache and verify deletion.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$snapshotId]);
    $this->assertNull(
      $this->entityTypeManager->getStorage('soda_scs_snapshot')->load($snapshotId)
    );
  }

  /**
   * Tests the loadByOwner static method.
   */
  public function testLoadByOwner(): void {
    // Create snapshots for the test user.
    $snapshot1 = $this->createTestSnapshot();
    $snapshot2 = $this->createTestSnapshot();

    // Create another user with a snapshot.
    $otherUser = $this->createTestUser();
    $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->create([
        'label' => 'Other User Snapshot',
        'machineName' => 'other-user-snapshot',
        'owner' => $otherUser->id(),
        'checksum' => hash('sha256', 'other'),
        'dir' => '/tmp/other',
      ])
      ->save();

    // Load snapshots by owner.
    $snapshots = SodaScsSnapshot::loadByOwner($this->testUser->id());

    $this->assertCount(2, $snapshots);
    $snapshotIds = array_map(fn($s) => $s->id(), $snapshots);
    $this->assertContains($snapshot1->id(), $snapshotIds);
    $this->assertContains($snapshot2->id(), $snapshotIds);
  }

  /**
   * Tests snapshot with component reference.
   */
  public function testSnapshotOfComponent(): void {
    // Create a component.
    $component = $this->createTestComponent('soda_scs_wisski_component');

    // Create a snapshot of the component.
    $snapshot = $this->createTestSnapshot([
      'snapshotOfComponent' => $component->id(),
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$snapshot->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $loadedSnapshot */
    $loadedSnapshot = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->load($snapshot->id());

    $snapshotOfComponent = $loadedSnapshot->get('snapshotOfComponent')->entity;
    $this->assertNotNull($snapshotOfComponent);
    $this->assertEquals($component->id(), $snapshotOfComponent->id());
  }

  /**
   * Tests snapshot with stack reference.
   */
  public function testSnapshotOfStack(): void {
    // Create a stack.
    $stack = $this->createTestStack('soda_scs_wisski_stack');

    // Create a snapshot of the stack.
    $snapshot = $this->createTestSnapshot([
      'snapshotOfStack' => $stack->id(),
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$snapshot->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $loadedSnapshot */
    $loadedSnapshot = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->load($snapshot->id());

    $snapshotOfStack = $loadedSnapshot->get('snapshotOfStack')->entity;
    $this->assertNotNull($snapshotOfStack);
    $this->assertEquals($stack->id(), $snapshotOfStack->id());
  }

  /**
   * Tests snapshot created and changed timestamps.
   */
  public function testSnapshotTimestamps(): void {
    $snapshot = $this->createTestSnapshot();

    // Created timestamp should be set.
    $created = $snapshot->get('created')->value;
    $this->assertNotNull($created);
    $this->assertIsNumeric($created);

    // Update the snapshot to trigger changed timestamp.
    $snapshot->set('label', 'Updated Label');
    $snapshot->save();

    // Changed timestamp should be set and >= created.
    $changed = $snapshot->get('changed')->value;
    $this->assertNotNull($changed);
    $this->assertGreaterThanOrEqual($created, $changed);
  }

  /**
   * Tests snapshot getLabel and setLabel methods.
   */
  public function testSnapshotLabelMethods(): void {
    $snapshot = $this->createTestSnapshot();
    $originalLabel = $snapshot->getLabel();

    $newLabel = 'New Label ' . $this->randomMachineName();
    $snapshot->setLabel($newLabel);

    $this->assertEquals($newLabel, $snapshot->getLabel());
    $this->assertNotEquals($originalLabel, $snapshot->getLabel());
  }

  /**
   * Tests snapshot getMachineName and setMachineName methods.
   */
  public function testSnapshotMachineNameMethods(): void {
    $snapshot = $this->createTestSnapshot();
    $originalMachineName = $snapshot->getMachineName();

    $newMachineName = 'new-machine-name-' . strtolower($this->randomMachineName());
    $snapshot->setMachineName($newMachineName);

    $this->assertEquals($newMachineName, $snapshot->getMachineName());
    $this->assertNotEquals($originalMachineName, $snapshot->getMachineName());
  }

  /**
   * Tests snapshot getChecksum and setChecksum methods.
   */
  public function testSnapshotChecksumMethods(): void {
    $snapshot = $this->createTestSnapshot();
    $originalChecksum = $snapshot->getChecksum();

    $newChecksum = hash('sha256', $this->randomMachineName());
    $snapshot->setChecksum($newChecksum);

    $this->assertEquals($newChecksum, $snapshot->getChecksum());
    $this->assertNotEquals($originalChecksum, $snapshot->getChecksum());
  }

  /**
   * Tests snapshot getCreatedTime and setCreatedTime methods.
   */
  public function testSnapshotCreatedTimeMethods(): void {
    $snapshot = $this->createTestSnapshot();
    $originalCreatedTime = $snapshot->getCreatedTime();

    $newCreatedTime = time() - 3600;
    $snapshot->setCreatedTime($newCreatedTime);

    $this->assertEquals($newCreatedTime, $snapshot->getCreatedTime());
  }

  /**
   * Tests loading snapshot by properties.
   */
  public function testLoadByProperties(): void {
    $machineName = 'unique-snapshot-name-' . $this->randomMachineName();

    $snapshot = $this->createTestSnapshot([
      'machineName' => $machineName,
    ]);

    $loaded = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->loadByProperties(['machineName' => $machineName]);

    $this->assertCount(1, $loaded);
    $this->assertEquals($snapshot->id(), reset($loaded)->id());
  }

  /**
   * Tests snapshot checksum uniqueness validation.
   */
  public function testSnapshotChecksumField(): void {
    $checksum = hash('sha256', $this->randomMachineName());

    $snapshot = $this->createTestSnapshot([
      'checksum' => $checksum,
    ]);

    // Reload and verify checksum is preserved.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$snapshot->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $loadedSnapshot */
    $loadedSnapshot = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->load($snapshot->id());

    $this->assertEquals($checksum, $loadedSnapshot->get('checksum')->value);
  }

  /**
   * Tests snapshot directory field.
   */
  public function testSnapshotDirField(): void {
    $dir = '/custom/snapshot/path';

    $snapshot = $this->createTestSnapshot([
      'dir' => $dir,
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$snapshot->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $loadedSnapshot */
    $loadedSnapshot = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->load($snapshot->id());

    $this->assertEquals($dir, $loadedSnapshot->get('dir')->value);
  }

  /**
   * Tests snapshot owner reference.
   */
  public function testSnapshotOwner(): void {
    $snapshot = $this->createTestSnapshot();

    // Verify owner is set correctly.
    $ownerField = $snapshot->get('owner');
    $this->assertFalse($ownerField->isEmpty());
    $this->assertEquals($this->testUser->id(), $ownerField->target_id);

    // Verify owner entity can be loaded.
    $owner = $ownerField->entity;
    $this->assertNotNull($owner);
    $this->assertEquals($this->testUser->id(), $owner->id());
  }

  /**
   * Tests creating multiple snapshots for the same component.
   */
  public function testMultipleSnapshotsForComponent(): void {
    // Create a component.
    $component = $this->createTestComponent('soda_scs_wisski_component');

    // Create multiple snapshots for the same component.
    $snapshot1 = $this->createTestSnapshot([
      'snapshotOfComponent' => $component->id(),
    ]);
    $snapshot2 = $this->createTestSnapshot([
      'snapshotOfComponent' => $component->id(),
    ]);
    $snapshot3 = $this->createTestSnapshot([
      'snapshotOfComponent' => $component->id(),
    ]);

    // Load all snapshots and verify they exist.
    $loaded = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->loadByProperties(['snapshotOfComponent' => $component->id()]);

    $this->assertCount(3, $loaded);
  }

}
