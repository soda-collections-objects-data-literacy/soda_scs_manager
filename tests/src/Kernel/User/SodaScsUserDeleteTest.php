<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\User;

use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\soda_scs_manager\Entity\SodaScsProject;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKey;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests user deletion cleanup for SODa SCS Manager.
 *
 * This tests the hook_user_delete() implementation which cleans up
 * all entities owned by a user when they are deleted.
 *
 * @group soda_scs_manager
 */
class SodaScsUserDeleteTest extends SodaScsKernelTestBase {

  /**
   * Tests that owned components are deleted when user is deleted.
   */
  public function testUserDeleteCleansUpComponents(): void {
    // Create components for the test user.
    $component1 = $this->createTestComponent('soda_scs_sql_component');
    $component2 = $this->createTestComponent('soda_scs_triplestore_component');
    $component3 = $this->createTestComponent('soda_scs_wisski_component');

    $componentIds = [
      $component1->id(),
      $component2->id(),
      $component3->id(),
    ];

    // Verify components exist.
    foreach ($componentIds as $id) {
      $this->assertNotNull(
        $this->entityTypeManager->getStorage('soda_scs_component')->load($id)
      );
    }

    // Delete all components owned by user (simulating part of hook_user_delete).
    $components = SodaScsComponent::loadByOwner($this->testUser->id());
    foreach ($components as $component) {
      $component->delete();
    }

    // Reset cache and verify components are deleted.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache($componentIds);

    foreach ($componentIds as $id) {
      $this->assertNull(
        $this->entityTypeManager->getStorage('soda_scs_component')->load($id),
        "Component with ID $id should be deleted."
      );
    }
  }

  /**
   * Tests that owned stacks are deleted when user is deleted.
   */
  public function testUserDeleteCleansUpStacks(): void {
    // Create stacks for the test user.
    $stack1 = $this->createTestStack('soda_scs_wisski_stack');
    $stack2 = $this->createTestStack('soda_scs_jupyter_stack');

    $stackIds = [
      $stack1->id(),
      $stack2->id(),
    ];

    // Verify stacks exist.
    foreach ($stackIds as $id) {
      $this->assertNotNull(
        $this->entityTypeManager->getStorage('soda_scs_stack')->load($id)
      );
    }

    // Delete all stacks owned by user (simulating part of hook_user_delete).
    $stacks = SodaScsStack::loadByOwner($this->testUser->id());
    foreach ($stacks as $stack) {
      $stack->delete();
    }

    // Reset cache and verify stacks are deleted.
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache($stackIds);

    foreach ($stackIds as $id) {
      $this->assertNull(
        $this->entityTypeManager->getStorage('soda_scs_stack')->load($id),
        "Stack with ID $id should be deleted."
      );
    }
  }

  /**
   * Tests that owned projects are deleted when user is deleted.
   */
  public function testUserDeleteCleansUpProjects(): void {
    // Create projects for the test user.
    $project1 = $this->createTestProject();
    $project2 = $this->createTestProject();

    $projectIds = [
      $project1->id(),
      $project2->id(),
    ];

    // Verify projects exist.
    foreach ($projectIds as $id) {
      $this->assertNotNull(
        $this->entityTypeManager->getStorage('soda_scs_project')->load($id)
      );
    }

    // Delete all projects owned by user (simulating part of hook_user_delete).
    $projects = SodaScsProject::loadByOwner($this->testUser->id());
    foreach ($projects as $project) {
      $project->delete();
    }

    // Reset cache and verify projects are deleted.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache($projectIds);

    foreach ($projectIds as $id) {
      $this->assertNull(
        $this->entityTypeManager->getStorage('soda_scs_project')->load($id),
        "Project with ID $id should be deleted."
      );
    }
  }

  /**
   * Tests that owned snapshots are deleted when user is deleted.
   */
  public function testUserDeleteCleansUpSnapshots(): void {
    // Create snapshots for the test user.
    $snapshot1 = $this->createTestSnapshot();
    $snapshot2 = $this->createTestSnapshot();

    $snapshotIds = [
      $snapshot1->id(),
      $snapshot2->id(),
    ];

    // Verify snapshots exist.
    foreach ($snapshotIds as $id) {
      $this->assertNotNull(
        $this->entityTypeManager->getStorage('soda_scs_snapshot')->load($id)
      );
    }

    // Delete all snapshots owned by user (simulating part of hook_user_delete).
    $snapshots = SodaScsSnapshot::loadByOwner($this->testUser->id());
    foreach ($snapshots as $snapshot) {
      $snapshot->delete();
    }

    // Reset cache and verify snapshots are deleted.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache($snapshotIds);

    foreach ($snapshotIds as $id) {
      $this->assertNull(
        $this->entityTypeManager->getStorage('soda_scs_snapshot')->load($id),
        "Snapshot with ID $id should be deleted."
      );
    }
  }

  /**
   * Tests that owned service keys are deleted when user is deleted.
   */
  public function testUserDeleteCleansUpServiceKeys(): void {
    // Create service keys for the test user.
    $serviceKey1 = $this->createTestServiceKey();
    $serviceKey2 = $this->createTestServiceKey();

    $serviceKeyIds = [
      $serviceKey1->id(),
      $serviceKey2->id(),
    ];

    // Verify service keys exist.
    foreach ($serviceKeyIds as $id) {
      $this->assertNotNull(
        $this->entityTypeManager->getStorage('soda_scs_service_key')->load($id)
      );
    }

    // Delete all service keys owned by user (simulating part of hook_user_delete).
    $serviceKeys = SodaScsServiceKey::loadByOwner($this->testUser->id());
    foreach ($serviceKeys as $serviceKey) {
      $serviceKey->delete();
    }

    // Reset cache and verify service keys are deleted.
    $this->entityTypeManager->getStorage('soda_scs_service_key')->resetCache($serviceKeyIds);

    foreach ($serviceKeyIds as $id) {
      $this->assertNull(
        $this->entityTypeManager->getStorage('soda_scs_service_key')->load($id),
        "Service key with ID $id should be deleted."
      );
    }
  }

  /**
   * Tests full cleanup of all entity types for a user.
   */
  public function testFullUserCleanup(): void {
    // Create various entities for the test user.
    $component = $this->createTestComponent('soda_scs_sql_component');
    $stack = $this->createTestStack('soda_scs_wisski_stack');
    $project = $this->createTestProject();
    $snapshot = $this->createTestSnapshot();
    $serviceKey = $this->createTestServiceKey();

    $entityIds = [
      'component' => $component->id(),
      'stack' => $stack->id(),
      'project' => $project->id(),
      'snapshot' => $snapshot->id(),
      'serviceKey' => $serviceKey->id(),
    ];

    // Verify all entities exist.
    $this->assertNotNull($this->entityTypeManager->getStorage('soda_scs_component')->load($entityIds['component']));
    $this->assertNotNull($this->entityTypeManager->getStorage('soda_scs_stack')->load($entityIds['stack']));
    $this->assertNotNull($this->entityTypeManager->getStorage('soda_scs_project')->load($entityIds['project']));
    $this->assertNotNull($this->entityTypeManager->getStorage('soda_scs_snapshot')->load($entityIds['snapshot']));
    $this->assertNotNull($this->entityTypeManager->getStorage('soda_scs_service_key')->load($entityIds['serviceKey']));

    // Simulate full user cleanup (as done in hook_user_delete).
    $snapshots = SodaScsSnapshot::loadByOwner($this->testUser->id());
    foreach ($snapshots as $s) {
      $s->delete();
    }

    $components = SodaScsComponent::loadByOwner($this->testUser->id());
    foreach ($components as $c) {
      $c->delete();
    }

    $stacks = SodaScsStack::loadByOwner($this->testUser->id());
    foreach ($stacks as $st) {
      $st->delete();
    }

    $projects = SodaScsProject::loadByOwner($this->testUser->id());
    foreach ($projects as $p) {
      $p->delete();
    }

    $serviceKeys = SodaScsServiceKey::loadByOwner($this->testUser->id());
    foreach ($serviceKeys as $sk) {
      $sk->delete();
    }

    // Reset all caches.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$entityIds['component']]);
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache([$entityIds['stack']]);
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$entityIds['project']]);
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$entityIds['snapshot']]);
    $this->entityTypeManager->getStorage('soda_scs_service_key')->resetCache([$entityIds['serviceKey']]);

    // Verify all entities are deleted.
    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_component')->load($entityIds['component']));
    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_stack')->load($entityIds['stack']));
    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_project')->load($entityIds['project']));
    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_snapshot')->load($entityIds['snapshot']));
    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_service_key')->load($entityIds['serviceKey']));
  }

  /**
   * Tests that other users' entities are not affected by user deletion.
   */
  public function testOtherUsersEntitiesNotAffected(): void {
    // Create a second user.
    $otherUser = $this->createTestUser();

    // Create entities for the other user.
    $otherComponent = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->create([
        'bundle' => 'soda_scs_sql_component',
        'label' => 'Other User Component',
        'machineName' => 'other-user-component',
        'owner' => $otherUser->id(),
        'health' => 'Unknown',
      ]);
    $otherComponent->save();

    $otherProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->create([
        'bundle' => 'default',
        'label' => 'Other User Project',
        'owner' => $otherUser->id(),
      ]);
    $otherProject->save();

    // Create entities for the test user.
    $testComponent = $this->createTestComponent('soda_scs_sql_component');
    $testProject = $this->createTestProject();

    // Delete test user's entities.
    $components = SodaScsComponent::loadByOwner($this->testUser->id());
    foreach ($components as $c) {
      $c->delete();
    }

    $projects = SodaScsProject::loadByOwner($this->testUser->id());
    foreach ($projects as $p) {
      $p->delete();
    }

    // Verify test user's entities are deleted.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$testComponent->id()]);
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$testProject->id()]);

    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_component')->load($testComponent->id()));
    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_project')->load($testProject->id()));

    // Verify other user's entities still exist.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$otherComponent->id()]);
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$otherProject->id()]);

    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_component')->load($otherComponent->id()),
      "Other user's component should not be deleted."
    );
    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_project')->load($otherProject->id()),
      "Other user's project should not be deleted."
    );
  }

  /**
   * Tests loadByOwner returns empty array for user without entities.
   */
  public function testLoadByOwnerEmptyResults(): void {
    // Create a new user with no entities.
    $emptyUser = $this->createTestUser();

    // Verify loadByOwner returns empty arrays.
    $this->assertEmpty(SodaScsComponent::loadByOwner($emptyUser->id()));
    $this->assertEmpty(SodaScsStack::loadByOwner($emptyUser->id()));
    $this->assertEmpty(SodaScsProject::loadByOwner($emptyUser->id()));
    $this->assertEmpty(SodaScsSnapshot::loadByOwner($emptyUser->id()));
    $this->assertEmpty(SodaScsServiceKey::loadByOwner($emptyUser->id()));
  }

  /**
   * Tests cleanup order - snapshots before components.
   */
  public function testCleanupOrderSnapshotsBeforeComponents(): void {
    // Create a component.
    $component = $this->createTestComponent('soda_scs_wisski_component');

    // Create snapshots referencing the component.
    $snapshot1 = $this->createTestSnapshot([
      'snapshotOfComponent' => $component->id(),
    ]);
    $snapshot2 = $this->createTestSnapshot([
      'snapshotOfComponent' => $component->id(),
    ]);

    // Delete snapshots first (proper order).
    $snapshots = SodaScsSnapshot::loadByOwner($this->testUser->id());
    foreach ($snapshots as $s) {
      $s->delete();
    }

    // Now delete the component.
    $component->delete();

    // Verify all are deleted.
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->resetCache([$snapshot1->id(), $snapshot2->id()]);
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$component->id()]);

    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_snapshot')->load($snapshot1->id()));
    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_snapshot')->load($snapshot2->id()));
    $this->assertNull($this->entityTypeManager->getStorage('soda_scs_component')->load($component->id()));
  }

  /**
   * Tests entity counts before and after cleanup.
   */
  public function testEntityCountsAfterCleanup(): void {
    // Create multiple entities.
    $this->createTestComponent('soda_scs_sql_component');
    $this->createTestComponent('soda_scs_triplestore_component');
    $this->createTestStack('soda_scs_wisski_stack');
    $this->createTestProject();
    $this->createTestSnapshot();
    $this->createTestServiceKey();

    // Verify counts before cleanup.
    $this->assertCount(2, SodaScsComponent::loadByOwner($this->testUser->id()));
    $this->assertCount(1, SodaScsStack::loadByOwner($this->testUser->id()));
    $this->assertCount(1, SodaScsProject::loadByOwner($this->testUser->id()));
    $this->assertCount(1, SodaScsSnapshot::loadByOwner($this->testUser->id()));
    $this->assertCount(1, SodaScsServiceKey::loadByOwner($this->testUser->id()));

    // Perform cleanup.
    foreach (SodaScsSnapshot::loadByOwner($this->testUser->id()) as $e) {
      $e->delete();
    }
    foreach (SodaScsComponent::loadByOwner($this->testUser->id()) as $e) {
      $e->delete();
    }
    foreach (SodaScsStack::loadByOwner($this->testUser->id()) as $e) {
      $e->delete();
    }
    foreach (SodaScsProject::loadByOwner($this->testUser->id()) as $e) {
      $e->delete();
    }
    foreach (SodaScsServiceKey::loadByOwner($this->testUser->id()) as $e) {
      $e->delete();
    }

    // Verify counts after cleanup.
    $this->assertCount(0, SodaScsComponent::loadByOwner($this->testUser->id()));
    $this->assertCount(0, SodaScsStack::loadByOwner($this->testUser->id()));
    $this->assertCount(0, SodaScsProject::loadByOwner($this->testUser->id()));
    $this->assertCount(0, SodaScsSnapshot::loadByOwner($this->testUser->id()));
    $this->assertCount(0, SodaScsServiceKey::loadByOwner($this->testUser->id()));
  }

}
