<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Entity;

use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests CRUD operations for SODa SCS Stack entities.
 *
 * @group soda_scs_manager
 */
class SodaScsStackCrudTest extends SodaScsKernelTestBase {

  /**
   * Tests creating a stack entity for each bundle.
   *
   * @dataProvider stackBundleProvider
   */
  public function testCreateStack(string $bundle): void {
    $label = 'Test ' . $bundle;
    $machineName = 'test-' . str_replace('_', '-', $bundle);

    $stack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->create([
        'bundle' => $bundle,
        'label' => $label,
        'machineName' => $machineName,
        'owner' => $this->testUser->id(),
        'health' => 'Unknown',
      ]);

    $this->assertInstanceOf(SodaScsStack::class, $stack);
    $this->assertEquals($bundle, $stack->bundle());
    $this->assertEquals($label, $stack->get('label')->value);
    $this->assertEquals($machineName, $stack->get('machineName')->value);

    // Save and verify ID is assigned.
    $stack->save();
    $this->assertNotNull($stack->id());
    $this->assertIsNumeric($stack->id());
  }

  /**
   * Tests reading a stack entity for each bundle.
   *
   * @dataProvider stackBundleProvider
   */
  public function testReadStack(string $bundle): void {
    // Create a stack first.
    $stack = $this->createTestStack($bundle);
    $stackId = $stack->id();

    // Clear entity cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache([$stackId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $loadedStack */
    $loadedStack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->load($stackId);

    $this->assertNotNull($loadedStack);
    $this->assertEquals($stack->get('label')->value, $loadedStack->get('label')->value);
    $this->assertEquals($stack->get('machineName')->value, $loadedStack->get('machineName')->value);
    $this->assertEquals($bundle, $loadedStack->bundle());
  }

  /**
   * Tests updating a stack entity for each bundle.
   *
   * @dataProvider stackBundleProvider
   */
  public function testUpdateStack(string $bundle): void {
    // Create a stack first.
    $stack = $this->createTestStack($bundle);
    $stackId = $stack->id();
    $originalLabel = $stack->get('label')->value;

    // Update the label.
    $newLabel = 'Updated ' . $originalLabel;
    $stack->set('label', $newLabel);
    $stack->save();

    // Clear cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache([$stackId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $loadedStack */
    $loadedStack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->load($stackId);

    $this->assertEquals($newLabel, $loadedStack->get('label')->value);
  }

  /**
   * Tests deleting a stack entity for each bundle.
   *
   * @dataProvider stackBundleProvider
   */
  public function testDeleteStack(string $bundle): void {
    // Create a stack first.
    $stack = $this->createTestStack($bundle);
    $stackId = $stack->id();

    // Verify it exists.
    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_stack')->load($stackId)
    );

    // Delete the stack.
    $stack->delete();

    // Clear cache and verify deletion.
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache([$stackId]);
    $this->assertNull(
      $this->entityTypeManager->getStorage('soda_scs_stack')->load($stackId)
    );
  }

  /**
   * Tests the loadByOwner static method.
   */
  public function testLoadByOwner(): void {
    // Create stacks for the test user.
    $stack1 = $this->createTestStack('soda_scs_wisski_stack');
    $stack2 = $this->createTestStack('soda_scs_jupyter_stack');

    // Create another user with a stack.
    $otherUser = $this->createTestUser();
    $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->create([
        'bundle' => 'soda_scs_nextcloud_stack',
        'label' => 'Other User Stack',
        'machineName' => 'other-user-stack',
        'owner' => $otherUser->id(),
        'health' => 'Unknown',
      ])
      ->save();

    // Load stacks by owner.
    $stacks = SodaScsStack::loadByOwner($this->testUser->id());

    $this->assertCount(2, $stacks);
    $stackIds = array_map(fn($s) => $s->id(), $stacks);
    $this->assertContains($stack1->id(), $stackIds);
    $this->assertContains($stack2->id(), $stackIds);
  }

  /**
   * Tests stack with included components reference.
   */
  public function testIncludedComponents(): void {
    // Create components to include.
    $sqlComponent = $this->createTestComponent('soda_scs_sql_component');
    $triplestoreComponent = $this->createTestComponent('soda_scs_triplestore_component');
    $wisskiComponent = $this->createTestComponent('soda_scs_wisski_component');

    // Create a WissKI stack with included components.
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'includedComponents' => [
        $sqlComponent->id(),
        $triplestoreComponent->id(),
        $wisskiComponent->id(),
      ],
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache([$stack->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $loadedStack */
    $loadedStack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->load($stack->id());

    $includedComponents = $loadedStack->get('includedComponents')->referencedEntities();
    $this->assertCount(3, $includedComponents);
  }

  /**
   * Tests stack with project reference.
   */
  public function testStackWithProject(): void {
    // Create a project.
    $project = $this->createTestProject();

    // Create a stack with project reference.
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'partOfProjects' => [$project->id()],
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache([$stack->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $loadedStack */
    $loadedStack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->load($stack->id());

    $projects = $loadedStack->get('partOfProjects')->referencedEntities();
    $this->assertCount(1, $projects);
    $this->assertEquals($project->id(), $projects[0]->id());
  }

  /**
   * Tests stack getLabel and setLabel methods.
   */
  public function testStackLabelMethods(): void {
    $stack = $this->createTestStack('soda_scs_wisski_stack');
    $originalLabel = $stack->getLabel();

    $newLabel = 'New Label ' . $this->randomMachineName();
    $stack->setLabel($newLabel);

    $this->assertEquals($newLabel, $stack->getLabel());
    $this->assertNotEquals($originalLabel, $stack->getLabel());
  }

  /**
   * Tests stack getValue method.
   */
  public function testStackGetValue(): void {
    // Create components to include.
    $sqlComponent = $this->createTestComponent('soda_scs_sql_component');
    $triplestoreComponent = $this->createTestComponent('soda_scs_triplestore_component');

    // Create a stack with included components.
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'includedComponents' => [
        $sqlComponent->id(),
        $triplestoreComponent->id(),
      ],
    ]);

    // Test getValue method.
    $includedComponents = $stack->getValue($stack, 'includedComponents');
    $this->assertCount(2, $includedComponents);
  }

  /**
   * Tests stack setValue method.
   */
  public function testStackSetValue(): void {
    // Create a stack without included components.
    $stack = $this->createTestStack('soda_scs_wisski_stack');

    // Create a component to add.
    $component = $this->createTestComponent('soda_scs_sql_component');

    // Add component using setValue.
    SodaScsStack::setValue($stack, 'includedComponents', (string) $component->id());
    $stack->save();

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache([$stack->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $loadedStack */
    $loadedStack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->load($stack->id());

    $includedComponents = $loadedStack->get('includedComponents')->getValue();
    $this->assertCount(1, $includedComponents);
    $this->assertEquals($component->id(), $includedComponents[0]['target_id']);
  }

  /**
   * Tests loading stack by properties.
   */
  public function testLoadByProperties(): void {
    $machineName = 'unique-stack-name-' . $this->randomMachineName();

    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'machineName' => $machineName,
    ]);

    $loaded = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->loadByProperties(['machineName' => $machineName]);

    $this->assertCount(1, $loaded);
    $this->assertEquals($stack->id(), reset($loaded)->id());
  }

  /**
   * Tests stack with snapshot reference.
   */
  public function testStackWithSnapshots(): void {
    // Create a stack.
    $stack = $this->createTestStack('soda_scs_wisski_stack');

    // Create snapshots.
    $snapshot1 = $this->createTestSnapshot([
      'snapshotOfStack' => $stack->id(),
    ]);
    $snapshot2 = $this->createTestSnapshot([
      'snapshotOfStack' => $stack->id(),
    ]);

    // Update stack with snapshots.
    $stack->set('snapshots', [$snapshot1->id(), $snapshot2->id()]);
    $stack->save();

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_stack')->resetCache([$stack->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $loadedStack */
    $loadedStack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->load($stack->id());

    $snapshots = $loadedStack->get('snapshots')->referencedEntities();
    $this->assertCount(2, $snapshots);
  }

  /**
   * Data provider for stack bundles.
   *
   * @return array
   *   Array of bundle names.
   */
  public static function stackBundleProvider(): array {
    return [
      'wisski stack' => ['soda_scs_wisski_stack'],
      'jupyter stack' => ['soda_scs_jupyter_stack'],
      'nextcloud stack' => ['soda_scs_nextcloud_stack'],
    ];
  }

}
