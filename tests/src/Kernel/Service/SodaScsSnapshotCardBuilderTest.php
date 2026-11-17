<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\soda_scs_manager\Service\SodaScsSnapshotCardBuilder;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * The Snapshots card on the detail page of a stack or a component.
 *
 * The card shows the newest snapshots the viewer may see and the link that
 * starts a new snapshot, or nothing at all when the viewer has neither. No
 * remote service is reached: only entities, routes and access are involved.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Service\SodaScsSnapshotCardBuilder
 * @group soda_scs_manager
 */
class SodaScsSnapshotCardBuilderTest extends SodaScsKernelTestBase {

  /**
   * Fixed creation time of the oldest snapshot built by the helpers.
   */
  private const BASE_TIME = 1700000000;

  /**
   * The card builder under test.
   */
  private SodaScsSnapshotCardBuilder $builder;

  /**
   * The owner of the applications; a regular SCS user.
   */
  private UserInterface $owner;

  /**
   * Another regular SCS user, who owns nothing here.
   */
  private UserInterface $stranger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The first user of a kernel test is uid 1 and bypasses every permission;
    // the viewers below are never that user.
    $role = Role::create(['id' => 'scs_card_user', 'label' => 'SCS card user']);
    $role->grantPermission('soda scs manager user');
    $role->grantPermission('view soda scs stack');
    $role->grantPermission('view soda scs component');
    $role->grantPermission('view soda scs snapshot');
    $role->grantPermission('create soda scs snapshot');
    $role->save();

    $this->owner = $this->createTestUser();
    $this->owner->addRole('scs_card_user');
    $this->owner->save();
    $this->stranger = $this->createTestUser();
    $this->stranger->addRole('scs_card_user');
    $this->stranger->save();

    $this->builder = $this->container->get('soda_scs_manager.snapshot_card_builder');
  }

  /**
   * Builds the card as the given user.
   *
   * @param \Drupal\Core\Entity\EntityInterface $application
   *   The stack or component.
   * @param \Drupal\user\UserInterface $viewer
   *   The user who looks at the detail page.
   *
   * @return array<string, mixed>|null
   *   The card data.
   */
  private function buildAs(EntityInterface $application, UserInterface $viewer): ?array {
    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($viewer);
    try {
      return $this->builder->build($application);
    }
    finally {
      $accountSwitcher->switchBack();
    }
  }

  /**
   * Creates a snapshot that was created $offset seconds after BASE_TIME.
   *
   * @param string $label
   *   The snapshot label.
   * @param int $offset
   *   Seconds after BASE_TIME.
   * @param \Drupal\user\UserInterface|null $snapshotOwner
   *   The snapshot owner, the application owner when omitted.
   *
   * @return int
   *   The snapshot ID.
   */
  private function createSnapshotAt(string $label, int $offset, ?UserInterface $snapshotOwner = NULL): int {
    $snapshot = $this->createTestSnapshot([
      'label' => $label,
      'created' => self::BASE_TIME + $offset,
      'owner' => ($snapshotOwner ?? $this->owner)->id(),
    ]);
    return (int) $snapshot->id();
  }

  /**
   * Turns snapshot IDs into the value of the snapshots field.
   *
   * @param int[] $snapshotIds
   *   The snapshot IDs.
   *
   * @return array<int, array<string, int>>
   *   Field items.
   */
  private function asReferences(array $snapshotIds): array {
    return array_map(static fn (int $id): array => ['target_id' => $id], $snapshotIds);
  }

  /**
   * The service is registered and wired through the container.
   */
  public function testServiceIsRegistered(): void {
    $this->assertInstanceOf(SodaScsSnapshotCardBuilder::class, $this->builder);
  }

  /**
   * The owner of a stack gets the create link and the snapshots, newest first.
   */
  public function testStackOwnerSeesCreateLinkAndSnapshotsNewestFirst(): void {
    $oldest = $this->createSnapshotAt('Oldest', 0);
    $newest = $this->createSnapshotAt('Newest', 200);
    $middle = $this->createSnapshotAt('Middle', 100);
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences([$oldest, $newest, $middle]),
    ]);

    $card = $this->buildAs($stack, $this->owner);

    $this->assertNotNull($card);
    $this->assertSame('/soda-scs-manager/stack/' . $stack->id() . '/snapshot', $card['createUrl']);
    $this->assertSame(3, $card['total']);
    $this->assertSame(['Newest', 'Middle', 'Oldest'], array_column($card['snapshots'], 'label'));
    $this->assertSame('/soda-scs-manager/snapshot/' . $newest, $card['snapshots'][0]['url']);
    $this->assertSame(
      $this->container->get('date.formatter')->format(self::BASE_TIME + 200, 'short'),
      $card['snapshots'][0]['created'],
    );
  }

  /**
   * The same card is built for a component, with the component route.
   */
  public function testComponentOwnerSeesCreateLinkAndSnapshots(): void {
    $snapshotId = $this->createSnapshotAt('Database snapshot', 0);
    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences([$snapshotId]),
    ]);

    $card = $this->buildAs($component, $this->owner);

    $this->assertNotNull($card);
    $this->assertSame('/soda-scs-manager/component/' . $component->id() . '/snapshot', $card['createUrl']);
    $this->assertSame(1, $card['total']);
    $this->assertSame(['Database snapshot'], array_column($card['snapshots'], 'label'));
  }

  /**
   * An owner without snapshots still gets the card, to create the first one.
   */
  public function testOwnerWithoutSnapshotsGetsCardWithCreateLinkOnly(): void {
    $stack = $this->createTestStack('soda_scs_wisski_stack', ['owner' => $this->owner->id()]);

    $card = $this->buildAs($stack, $this->owner);

    $this->assertSame([
      'createUrl' => '/soda-scs-manager/stack/' . $stack->id() . '/snapshot',
      'total' => 0,
      'snapshots' => [],
    ], $card);
  }

  /**
   * The card lists the five newest snapshots and counts all of them.
   */
  public function testCardListsAtMostFiveNewestSnapshotsAndCountsAll(): void {
    $this->assertSame(5, SodaScsSnapshotCardBuilder::MAX_LISTED_SNAPSHOTS);
    $ids = [];
    foreach (range(0, 6) as $index) {
      $ids[] = $this->createSnapshotAt('Snapshot ' . $index, $index * 10);
    }
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences($ids),
    ]);

    $card = $this->buildAs($stack, $this->owner);

    $this->assertNotNull($card);
    $this->assertSame(7, $card['total']);
    $this->assertSame(
      ['Snapshot 6', 'Snapshot 5', 'Snapshot 4', 'Snapshot 3', 'Snapshot 2'],
      array_column($card['snapshots'], 'label'),
    );
  }

  /**
   * A viewer who may neither create nor see a snapshot gets no card.
   */
  public function testStrangerWithoutVisibleSnapshotsGetsNoCard(): void {
    $snapshotId = $this->createSnapshotAt('Private', 0);
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences([$snapshotId]),
    ]);
    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences([$snapshotId]),
    ]);

    $this->assertNull($this->buildAs($stack, $this->stranger));
    $this->assertNull($this->buildAs($component, $this->stranger));
  }

  /**
   * A viewer sees only the snapshots they may view, and cannot create one.
   */
  public function testViewerSeesOnlyOwnSnapshotsOfForeignApplication(): void {
    $foreign = $this->createSnapshotAt('Foreign', 0);
    $mine = $this->createSnapshotAt('Mine', 10, $this->stranger);
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences([$foreign, $mine]),
    ]);

    $card = $this->buildAs($stack, $this->stranger);

    $this->assertNotNull($card);
    $this->assertNull($card['createUrl']);
    $this->assertSame(1, $card['total']);
    $this->assertSame(['Mine'], array_column($card['snapshots'], 'label'));
  }

  /**
   * An SCS Manager admin sees all snapshots and may create one.
   */
  public function testAdminSeesAllSnapshotsAndCreateLink(): void {
    $adminRole = Role::create(['id' => 'scs_card_admin', 'label' => 'SCS card admin']);
    $adminRole->grantPermission('soda scs manager admin');
    $adminRole->grantPermission('soda scs manager user');
    $adminRole->save();
    $admin = $this->createTestUser();
    $admin->addRole('scs_card_admin');
    $admin->save();

    $first = $this->createSnapshotAt('First', 0);
    $second = $this->createSnapshotAt('Second', 10);
    $component = $this->createTestComponent('soda_scs_sql_component', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences([$first, $second]),
    ]);

    $card = $this->buildAs($component, $admin);

    $this->assertNotNull($card);
    $this->assertSame('/soda-scs-manager/component/' . $component->id() . '/snapshot', $card['createUrl']);
    $this->assertSame(['Second', 'First'], array_column($card['snapshots'], 'label'));
  }

  /**
   * An owner without the SCS user permission cannot create, only look.
   */
  public function testOwnerWithoutManagerUserPermissionGetsNoCreateLink(): void {
    $role = Role::create(['id' => 'scs_card_view_only', 'label' => 'SCS card view only']);
    $role->grantPermission('view soda scs snapshot');
    $role->save();
    $viewOnly = $this->createTestUser();
    // Every new user gets scs_user, which carries the permission.
    $viewOnly->removeRole('scs_user');
    $viewOnly->addRole('scs_card_view_only');
    $viewOnly->save();

    $snapshotId = $this->createSnapshotAt('Own', 0, $viewOnly);
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $viewOnly->id(),
      'snapshots' => $this->asReferences([$snapshotId]),
    ]);

    $card = $this->buildAs($stack, $viewOnly);

    $this->assertNotNull($card);
    $this->assertNull($card['createUrl']);
    $this->assertSame(['Own'], array_column($card['snapshots'], 'label'));
  }

  /**
   * Applications whose snapshot task is hidden get no empty card.
   */
  public function testHiddenSnapshotBundlesGetNoCardWithoutSnapshots(): void {
    $jupyter = $this->createTestStack('soda_scs_jupyter_stack', ['owner' => $this->owner->id()]);
    $webprotege = $this->createTestComponent('soda_scs_webprotege_component', ['owner' => $this->owner->id()]);

    $this->assertNull($this->buildAs($jupyter, $this->owner));
    $this->assertNull($this->buildAs($webprotege, $this->owner));
  }

  /**
   * Existing snapshots of a hidden-task bundle are listed without create link.
   */
  public function testHiddenSnapshotBundleStillListsItsSnapshots(): void {
    $snapshotId = $this->createSnapshotAt('Old snapshot', 0);
    $jupyter = $this->createTestStack('soda_scs_jupyter_stack', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences([$snapshotId]),
    ]);

    $card = $this->buildAs($jupyter, $this->owner);

    $this->assertNotNull($card);
    $this->assertNull($card['createUrl']);
    $this->assertSame(['Old snapshot'], array_column($card['snapshots'], 'label'));
  }

  /**
   * Entities that are neither stack nor component get no card.
   */
  public function testOtherEntityTypesGetNoCard(): void {
    $project = $this->createTestProject();
    $snapshot = $this->createTestSnapshot();

    $this->assertNull($this->buildAs($project, $this->owner));
    $this->assertNull($this->buildAs($snapshot, $this->owner));
  }

  /**
   * A reference to a deleted snapshot does not break the card.
   */
  public function testDeletedSnapshotIsLeftOut(): void {
    $kept = $this->createSnapshotAt('Kept', 0);
    $deleted = $this->createSnapshotAt('Deleted', 10);
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'owner' => $this->owner->id(),
      'snapshots' => $this->asReferences([$kept, $deleted]),
    ]);
    $this->entityTypeManager->getStorage('soda_scs_snapshot')->load($deleted)->delete();

    $card = $this->buildAs($this->entityTypeManager->getStorage('soda_scs_stack')->loadUnchanged($stack->id()), $this->owner);

    $this->assertNotNull($card);
    $this->assertSame(1, $card['total']);
    $this->assertSame(['Kept'], array_column($card['snapshots'], 'label'));
  }

}
