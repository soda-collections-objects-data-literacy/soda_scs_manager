<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Entity;

use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Tests CRUD operations for SODa SCS Component entities.
 *
 * @group soda_scs_manager
 */
class SodaScsComponentCrudTest extends SodaScsKernelTestBase {

  /**
   * Component bundles to test.
   *
   * @var array
   */
  protected array $componentBundles = [
    'soda_scs_filesystem_component',
    'soda_scs_sql_component',
    'soda_scs_triplestore_component',
    'soda_scs_webprotege_component',
    'soda_scs_wisski_component',
  ];

  /**
   * Tests creating a component entity for each bundle.
   *
   * @dataProvider componentBundleProvider
   */
  public function testCreateComponent(string $bundle): void {
    $label = 'Test ' . $bundle;
    $machineName = 'test-' . str_replace('_', '-', $bundle);

    $component = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->create([
        'bundle' => $bundle,
        'label' => $label,
        'machineName' => $machineName,
        'owner' => $this->testUser->id(),
        'health' => 'Unknown',
      ]);

    $this->assertInstanceOf(SodaScsComponent::class, $component);
    $this->assertEquals($bundle, $component->bundle());
    $this->assertEquals($label, $component->get('label')->value);
    $this->assertEquals($machineName, $component->get('machineName')->value);

    // Save and verify ID is assigned.
    $component->save();
    $this->assertNotNull($component->id());
    $this->assertIsNumeric($component->id());
  }

  /**
   * Tests reading a component entity for each bundle.
   *
   * @dataProvider componentBundleProvider
   */
  public function testReadComponent(string $bundle): void {
    // Create a component first.
    $component = $this->createTestComponent($bundle);
    $componentId = $component->id();

    // Clear entity cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$componentId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $loadedComponent */
    $loadedComponent = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->load($componentId);

    $this->assertNotNull($loadedComponent);
    $this->assertEquals($component->get('label')->value, $loadedComponent->get('label')->value);
    $this->assertEquals($component->get('machineName')->value, $loadedComponent->get('machineName')->value);
    $this->assertEquals($bundle, $loadedComponent->bundle());
  }

  /**
   * Tests updating a component entity for each bundle.
   *
   * @dataProvider componentBundleProvider
   */
  public function testUpdateComponent(string $bundle): void {
    // Create a component first.
    $component = $this->createTestComponent($bundle);
    $componentId = $component->id();
    $originalLabel = $component->get('label')->value;

    // Update the label.
    $newLabel = 'Updated ' . $originalLabel;
    $component->set('label', $newLabel);
    $component->save();

    // Clear cache and reload.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$componentId]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $loadedComponent */
    $loadedComponent = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->load($componentId);

    $this->assertEquals($newLabel, $loadedComponent->get('label')->value);
  }

  /**
   * Tests deleting a component entity for each bundle.
   *
   * @dataProvider componentBundleProvider
   */
  public function testDeleteComponent(string $bundle): void {
    // Create a component first.
    $component = $this->createTestComponent($bundle);
    $componentId = $component->id();

    // Verify it exists.
    $this->assertNotNull(
      $this->entityTypeManager->getStorage('soda_scs_component')->load($componentId)
    );

    // Delete the component.
    $component->delete();

    // Clear cache and verify deletion.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$componentId]);
    $this->assertNull(
      $this->entityTypeManager->getStorage('soda_scs_component')->load($componentId)
    );
  }

  /**
   * Tests the loadByOwner static method.
   */
  public function testLoadByOwner(): void {
    // Create components for the test user.
    $component1 = $this->createTestComponent('soda_scs_sql_component');
    $component2 = $this->createTestComponent('soda_scs_triplestore_component');

    // Create another user with a component.
    $otherUser = $this->createTestUser();
    $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->create([
        'bundle' => 'soda_scs_wisski_component',
        'label' => 'Other User Component',
        'machineName' => 'other-user-component',
        'owner' => $otherUser->id(),
        'health' => 'Unknown',
      ])
      ->save();

    // Load components by owner.
    $components = SodaScsComponent::loadByOwner($this->testUser->id());

    $this->assertCount(2, $components);
    $componentIds = array_map(fn($c) => $c->id(), $components);
    $this->assertContains($component1->id(), $componentIds);
    $this->assertContains($component2->id(), $componentIds);
  }

  /**
   * Tests component with connected components reference.
   */
  public function testConnectedComponents(): void {
    // Create a SQL component.
    $sqlComponent = $this->createTestComponent('soda_scs_sql_component');

    // Create a triplestore component.
    $triplestoreComponent = $this->createTestComponent('soda_scs_triplestore_component');

    // Create a WissKI component with connected components.
    $wisskiComponent = $this->createTestComponent('soda_scs_wisski_component', [
      'connectedComponents' => [
        $sqlComponent->id(),
        $triplestoreComponent->id(),
      ],
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$wisskiComponent->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $loadedWisski */
    $loadedWisski = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->load($wisskiComponent->id());

    $connectedComponents = $loadedWisski->get('connectedComponents')->referencedEntities();
    $this->assertCount(2, $connectedComponents);
  }

  /**
   * Tests component with project reference.
   */
  public function testComponentWithProject(): void {
    // Create a project.
    $project = $this->createTestProject();

    // Create a component with project reference.
    $component = $this->createTestComponent('soda_scs_sql_component', [
      'partOfProjects' => [$project->id()],
    ]);

    // Reload and verify.
    $this->entityTypeManager->getStorage('soda_scs_component')->resetCache([$component->id()]);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $loadedComponent */
    $loadedComponent = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->load($component->id());

    $projects = $loadedComponent->get('partOfProjects')->referencedEntities();
    $this->assertCount(1, $projects);
    $this->assertEquals($project->id(), $projects[0]->id());
  }

  /**
   * Tests component entity getters.
   */
  public function testComponentGetters(): void {
    $label = 'Test Label';
    $description = 'Test Description';
    $imageUrl = 'public://test-image.png';

    $component = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->create([
        'bundle' => 'soda_scs_wisski_component',
        'label' => $label,
        'machineName' => 'test-getters',
        'owner' => $this->testUser->id(),
        'health' => 'Unknown',
        'description' => ['value' => $description, 'format' => 'plain_text'],
        'imageUrl' => $imageUrl,
      ]);
    $component->save();

    $this->assertEquals($label, $component->getLabel());
  }

  /**
   * Tests loading component by properties.
   */
  public function testLoadByProperties(): void {
    $machineName = 'unique-machine-name-' . $this->randomMachineName();

    $component = $this->createTestComponent('soda_scs_sql_component', [
      'machineName' => $machineName,
    ]);

    $loaded = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->loadByProperties(['machineName' => $machineName]);

    $this->assertCount(1, $loaded);
    $this->assertEquals($component->id(), reset($loaded)->id());
  }

  /**
   * Data provider for component bundles.
   *
   * @return array
   *   Array of bundle names.
   */
  public static function componentBundleProvider(): array {
    return [
      'filesystem component' => ['soda_scs_filesystem_component'],
      'sql component' => ['soda_scs_sql_component'],
      'triplestore component' => ['soda_scs_triplestore_component'],
      'webprotege component' => ['soda_scs_webprotege_component'],
      'wisski component' => ['soda_scs_wisski_component'],
    ];
  }

}
