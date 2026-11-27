<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoProjectApps;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which WissKI stacks of a project the demo inventory returns.
 *
 * Only stacks of the project owner that belong to this project alone count
 * as the demo's; they are the ones deleting a demo project removes.
 */
#[CoversMethod(SodaScsDemoProjectApps::class, 'demoWisskiStacks')]
#[CoversMethod(SodaScsDemoProjectApps::class, 'wisskiStack')]
#[Group('soda_scs_manager')]
class SodaScsDemoProjectAppsTest extends UnitTestCase {

  /**
   * Every WissKI stack of the project comes back, not only the first.
   */
  public function testDemoWisskiStacksReturnsEveryStackOfTheProject(): void {
    $first = $this->stack(1, [3]);
    $second = $this->stack(1, [3]);

    $stacks = $this->apps([4 => $first, 9 => $second])->demoWisskiStacks($this->project(3));

    $this->assertSame([$first, $second], $stacks);
  }

  /**
   * A stack of another user in the demo project is not the demo's.
   */
  public function testDemoWisskiStacksSkipsStackOfAnotherOwner(): void {
    $demo = $this->stack(1, [3]);
    $members = $this->stack(8, [3]);

    $this->assertSame([$demo], $this->apps([4 => $members, 9 => $demo])->demoWisskiStacks($this->project(3)));
  }

  /**
   * A stack that is part of another project as well is not the demo's.
   */
  public function testDemoWisskiStacksSkipsStackSharedWithAnotherProject(): void {
    $demo = $this->stack(1, [3]);
    $shared = $this->stack(1, [5, 3]);

    $this->assertSame([$demo], $this->apps([4 => $shared, 9 => $demo])->demoWisskiStacks($this->project(3)));
  }

  /**
   * A project without an owner has no demo stacks.
   */
  public function testDemoWisskiStacksIsEmptyWithoutOwner(): void {
    $this->assertSame([], $this->apps([4 => $this->stack(0, [3])])->demoWisskiStacks($this->project(3, 0)));
  }

  /**
   * The query is limited to the WissKI bundle and the project, ordered by id.
   */
  public function testDemoWisskiStacksQueriesWisskiBundleOfTheProject(): void {
    $conditions = [];
    $sorts = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnCallback(function (string $field, $value) use (&$conditions, $query) {
      $conditions[$field] = $value;
      return $query;
    });
    $query->method('sort')->willReturnCallback(function (string $field) use (&$sorts, $query) {
      $sorts[] = $field;
      return $query;
    });
    $query->method('execute')->willReturn([]);

    $this->apps([], $query)->demoWisskiStacks($this->project(3));

    $this->assertSame([
      'bundle' => 'soda_scs_wisski_stack',
      'partOfProjects' => 3,
    ], $conditions);
    $this->assertSame(['id'], $sorts);
  }

  /**
   * A project without a WissKI stack gives an empty list.
   */
  public function testDemoWisskiStacksIsEmptyWithoutStacks(): void {
    $this->assertSame([], $this->apps([])->demoWisskiStacks($this->project(3)));
  }

  /**
   * The single-stack lookup still returns the first of several stacks.
   */
  public function testWisskiStackReturnsFirstOfSeveral(): void {
    $first = $this->createMock(SodaScsStackInterface::class);
    $second = $this->createMock(SodaScsStackInterface::class);

    $this->assertSame($first, $this->apps([4 => $first, 9 => $second])->wisskiStack($this->project(3)));
  }

  /**
   * Builds the inventory on a stack storage that finds the given stacks.
   *
   * @param array<int, \Drupal\soda_scs_manager\Entity\SodaScsStackInterface> $stacksById
   *   The stacks the storage loads, keyed by id.
   * @param \Drupal\Core\Entity\Query\QueryInterface|null $query
   *   A query double; by default one that returns the ids of the stacks.
   */
  private function apps(array $stacksById, ?QueryInterface $query = NULL): SodaScsDemoProjectApps {
    if ($query === NULL) {
      $query = $this->createMock(QueryInterface::class);
      $query->method('accessCheck')->willReturnSelf();
      $query->method('condition')->willReturnSelf();
      $query->method('sort')->willReturnSelf();
      $query->method('execute')->willReturn(array_combine(array_keys($stacksById), array_keys($stacksById)));
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($stacksById);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('soda_scs_stack')->willReturn($storage);
    return new SodaScsDemoProjectApps($entityTypeManager);
  }

  /**
   * A project double with the given id and owner.
   */
  private function project(int $id, int $ownerId = 1): SodaScsProjectInterface {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $project->method('id')->willReturn($id);
    $project->method('getOwnerId')->willReturn($ownerId);
    return $project;
  }

  /**
   * A stack double with an owner and the projects it is part of.
   *
   * @param int $ownerId
   *   The owner's user id.
   * @param int[] $projectIds
   *   Ids in partOfProjects.
   */
  private function stack(int $ownerId, array $projectIds): SodaScsStackInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn(array_map(fn(int $id): array => ['target_id' => (string) $id], $projectIds));
    $stack = $this->createMock(SodaScsStackInterface::class);
    $stack->method('getOwnerId')->willReturn((string) $ownerId);
    $stack->method('get')->with('partOfProjects')->willReturn($field);
    return $stack;
  }

}
