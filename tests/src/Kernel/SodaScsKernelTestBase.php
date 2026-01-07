<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Base class for SODa SCS Manager kernel tests.
 *
 * Provides common setup for entity schema installation and helper methods
 * for creating test entities.
 */
abstract class SodaScsKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'file',
    'options',
    'content_translation',
    'language',
    'soda_scs_manager',
  ];

  /**
   * A test user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $testUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install required schemas.
    $this->installSchema('system', ['sequences']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('soda_scs_manager', ['keycloak_user_registration']);

    // Install entity schemas.
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('soda_scs_component');
    $this->installEntitySchema('soda_scs_stack');
    $this->installEntitySchema('soda_scs_project');
    $this->installEntitySchema('soda_scs_snapshot');
    $this->installEntitySchema('soda_scs_service_key');

    // Install config.
    $this->installConfig(['system', 'field', 'user', 'soda_scs_manager']);

    // Get the entity type manager.
    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Create a test user.
    $this->testUser = $this->createTestUser();
  }

  /**
   * Creates a test user.
   *
   * @param array $values
   *   Optional values to override defaults.
   *
   * @return \Drupal\user\UserInterface
   *   The created user entity.
   */
  protected function createTestUser(array $values = []) {
    $values += [
      'name' => 'test_user_' . $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ];

    $user = User::create($values);
    $user->save();

    return $user;
  }

  /**
   * Creates a test component entity.
   *
   * @param string $bundle
   *   The component bundle.
   * @param array $values
   *   Optional values to override defaults.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface
   *   The created component entity.
   */
  protected function createTestComponent(string $bundle, array $values = []) {
    $values += [
      'bundle' => $bundle,
      'label' => 'Test Component ' . $this->randomMachineName(),
      'machineName' => 'test-component-' . strtolower($this->randomMachineName()),
      'owner' => $this->testUser->id(),
      'health' => 'Unknown',
    ];

    $component = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->create($values);
    $component->save();

    return $component;
  }

  /**
   * Creates a test stack entity.
   *
   * @param string $bundle
   *   The stack bundle.
   * @param array $values
   *   Optional values to override defaults.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface
   *   The created stack entity.
   */
  protected function createTestStack(string $bundle, array $values = []) {
    $values += [
      'bundle' => $bundle,
      'label' => 'Test Stack ' . $this->randomMachineName(),
      'machineName' => 'test-stack-' . strtolower($this->randomMachineName()),
      'owner' => $this->testUser->id(),
      'health' => 'Unknown',
    ];

    $stack = $this->entityTypeManager
      ->getStorage('soda_scs_stack')
      ->create($values);
    $stack->save();

    return $stack;
  }

  /**
   * Creates a test project entity.
   *
   * @param array $values
   *   Optional values to override defaults.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface
   *   The created project entity.
   */
  protected function createTestProject(array $values = []) {
    $values += [
      'bundle' => 'default',
      'label' => 'Test Project ' . $this->randomMachineName(),
      'owner' => $this->testUser->id(),
    ];

    $project = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->create($values);
    $project->save();

    return $project;
  }

  /**
   * Creates a test snapshot entity.
   *
   * @param array $values
   *   Optional values to override defaults.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface
   *   The created snapshot entity.
   */
  protected function createTestSnapshot(array $values = []) {
    $values += [
      'label' => 'Test Snapshot ' . $this->randomMachineName(),
      'machineName' => 'test-snapshot-' . strtolower($this->randomMachineName()),
      'owner' => $this->testUser->id(),
      'checksum' => hash('sha256', $this->randomMachineName()),
      'dir' => '/tmp/snapshots',
    ];

    $snapshot = $this->entityTypeManager
      ->getStorage('soda_scs_snapshot')
      ->create($values);
    $snapshot->save();

    return $snapshot;
  }

  /**
   * Creates a test service key entity.
   *
   * @param string $bundle
   *   The service key bundle.
   * @param array $values
   *   Optional values to override defaults.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface
   *   The created service key entity.
   */
  protected function createTestServiceKey(string $bundle = 'default', array $values = []) {
    $values += [
      'bundle' => $bundle,
      'label' => 'Test Service Key ' . $this->randomMachineName(),
      'owner' => $this->testUser->id(),
      'servicePassword' => $this->randomMachineName(32),
      'scsComponentBundle' => 'soda_scs_sql_component',
      'type' => 'password',
    ];

    $serviceKey = $this->entityTypeManager
      ->getStorage('soda_scs_service_key')
      ->create($values);
    $serviceKey->save();

    return $serviceKey;
  }

}
