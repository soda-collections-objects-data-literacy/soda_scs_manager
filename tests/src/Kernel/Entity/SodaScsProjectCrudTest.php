<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Entity;

use Drupal\soda_scs_manager\Entity\SodaScsProject;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests CRUD operations for SODa SCS Project entities.
 *
 * @group soda_scs_manager
 */
class SodaScsProjectCrudTest extends SodaScsKernelTestBase {

  /**
   * Tests creating a project entity.
   */
  public function testCreateProject(): void {
    $label = 'Test Project ' . $this->randomMachineName();
    $description = 'Test project description';

    $project = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->create([
        'bundle' => 'default',
        'label' => $label,
        'owner' => $this->testUser->id(),
        'description' => $description,
      ]);

    $this->assertInstanceOf(SodaScsProject::class, $project);
    $this->assertEquals('default', $project->bundle());
    $this->assertEquals($label, $project->get('label')->value);
    $this->assertEquals($description, $project->get('description')->value);

    // Save and verify ID is assigned.
    $project->save();
    $this->assertNotNull($project->id());
    $this->assertIsNumeric($project->id());
  }

  /**
   * Tests reading a project entity.
   */
  public function testReadProject(): void {
    // Create a project first.
    $project = $this->createTestProject();
    $projectId = $project->id();

    // Clear entity cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$projectId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $loadedProject */
    $loadedProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($projectId);

    $this->assertNotNull($loadedProject);
    $this->assertEquals($project->get('label')->value, $loadedProject->get('label')->value);
    $this->assertEquals('default', $loadedProject->bundle());
  }

  /**
   * Tests updating a project entity.
   */
  public function testUpdateProject(): void {
    // Create a project first.
    $project = $this->createTestProject();
    $projectId = $project->id();
    $originalLabel = $project->get('label')->value;

    // Update the label.
    $newLabel = 'Updated ' . $originalLabel;
    $project->set('label', $newLabel);
    $project->save();

    // Clear cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$projectId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $loadedProject */
    $loadedProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($projectId);

    $this->assertEquals($newLabel, $loadedProject->get('label')->value);
  }

  /**
   * Tests deleting a project entity.
   */
  public function testDeleteProject(): void {
    // Create a project first.
    $project = $this->createTestProject();
    $projectId = $project->id();

    // Verify it exists.
    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_project')->load($projectId)
    );

    // Delete the project.
    $project->delete();

    // Clear cache and verify deletion.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$projectId]);
    $this->assertNull(
      $this->entityTypeManager->getStorage('soda_scs_project')->load($projectId)
    );
  }

  /**
   * Tests the loadByOwner static method.
   */
  public function testLoadByOwner(): void {
    // Create projects for the test user.
    $project1 = $this->createTestProject();
    $project2 = $this->createTestProject();

    // Create another user with a project.
    $otherUser = $this->createTestUser();
    $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->create([
        'bundle' => 'default',
        'label' => 'Other User Project',
        'owner' => $otherUser->id(),
      ])
      ->save();

    // Load projects by owner.
    $projects = SodaScsProject::loadByOwner($this->testUser->id());

    $this->assertCount(2, $projects);
    $projectIds = array_map(fn($p) => $p->id(), $projects);
    $this->assertContains($project1->id(), $projectIds);
    $this->assertContains($project2->id(), $projectIds);
  }

  /**
   * Tests project with members reference.
   */
  public function testProjectMembers(): void {
    // Create additional users for members.
    $member1 = $this->createTestUser();
    $member2 = $this->createTestUser();

    // Create a project with members.
    $project = $this->createTestProject([
      'members' => [
        $member1->id(),
        $member2->id(),
      ],
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$project->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $loadedProject */
    $loadedProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($project->id());

    $members = $loadedProject->get('members')->referencedEntities();
    $this->assertCount(2, $members);
  }

  /**
   * Tests project with connected components reference.
   */
  public function testProjectWithComponents(): void {
    // Create components.
    $component1 = $this->createTestComponent('soda_scs_sql_component');
    $component2 = $this->createTestComponent('soda_scs_triplestore_component');

    // Create a project with connected components.
    $project = $this->createTestProject([
      'connectedComponents' => [
        $component1->id(),
        $component2->id(),
      ],
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$project->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $loadedProject */
    $loadedProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($project->id());

    $connectedComponents = $loadedProject->get('connectedComponents')->referencedEntities();
    $this->assertCount(2, $connectedComponents);
  }

  /**
   * Tests project created and updated timestamps.
   */
  public function testProjectTimestamps(): void {
    $project = $this->createTestProject();

    // Created timestamp should be set.
    $created = $project->get('created')->value;
    $this->assertNotNull($created);
    $this->assertIsNumeric($created);

    // Update the project to trigger updated timestamp.
    $project->set('label', 'Updated Label');
    $project->save();

    // Updated timestamp should be set and >= created.
    $updated = $project->get('updated')->value;
    $this->assertNotNull($updated);
    $this->assertGreaterThanOrEqual($created, $updated);
  }

  /**
   * Tests loading project by properties.
   */
  public function testLoadByProperties(): void {
    $label = 'Unique Project ' . $this->randomMachineName();

    $project = $this->createTestProject([
      'label' => $label,
    ]);

    $loaded = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->loadByProperties(['label' => $label]);

    $this->assertCount(1, $loaded);
    $this->assertEquals($project->id(), reset($loaded)->id());
  }

  /**
   * Tests project owner reference.
   */
  public function testProjectOwner(): void {
    $project = $this->createTestProject();

    // Verify owner is set correctly.
    $ownerField = $project->get('owner');
    $this->assertFalse($ownerField->isEmpty());
    $this->assertEquals($this->testUser->id(), $ownerField->target_id);

    // Verify owner entity can be loaded.
    $owner = $ownerField->entity;
    $this->assertNotNull($owner);
    $this->assertEquals($this->testUser->id(), $owner->id());
  }

  /**
   * Tests project rights field.
   */
  public function testProjectRights(): void {
    $rights = json_encode(['read' => TRUE, 'write' => FALSE]);

    $project = $this->createTestProject([
      'rights' => $rights,
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$project->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $loadedProject */
    $loadedProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($project->id());

    $this->assertEquals($rights, $loadedProject->get('rights')->value);
  }

  /**
   * Tests project Keycloak UUID field.
   */
  public function testProjectKeycloakUuid(): void {
    $keycloakUuid = $this->randomMachineName(36);

    $project = $this->createTestProject([
      'keycloakUuid' => $keycloakUuid,
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$project->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $loadedProject */
    $loadedProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($project->id());

    $this->assertEquals($keycloakUuid, $loadedProject->get('keycloakUuid')->value);
  }

  /**
   * Tests adding member to existing project.
   */
  public function testAddMemberToProject(): void {
    // Create a project without members.
    $project = $this->createTestProject();

    // Create a user to add as member.
    $newMember = $this->createTestUser();

    // Add member to project.
    $project->set('members', [$newMember->id()]);
    $project->save();

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$project->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $loadedProject */
    $loadedProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($project->id());

    $members = $loadedProject->get('members')->referencedEntities();
    $this->assertCount(1, $members);
    $this->assertEquals($newMember->id(), $members[0]->id());
  }

  /**
   * Tests removing member from project.
   */
  public function testRemoveMemberFromProject(): void {
    // Create users.
    $member1 = $this->createTestUser();
    $member2 = $this->createTestUser();

    // Create a project with members.
    $project = $this->createTestProject([
      'members' => [$member1->id(), $member2->id()],
    ]);

    // Remove one member.
    $project->set('members', [$member1->id()]);
    $project->save();

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_project')->resetCache([$project->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $loadedProject */
    $loadedProject = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($project->id());

    $members = $loadedProject->get('members')->referencedEntities();
    $this->assertCount(1, $members);
    $this->assertEquals($member1->id(), $members[0]->id());
  }

}
