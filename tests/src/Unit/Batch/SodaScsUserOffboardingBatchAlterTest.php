<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Batch;

use Drupal\soda_scs_manager\Batch\SodaScsUserOffboardingBatch;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Where hook_batch_alter() puts the application removal.
 *
 * Issue 3638781: the removal of an account's applications runs in batch
 * requests of its own in front of each deleting _user_cancel() operation. The
 * operation itself is replaced by deleteAccount(), which refuses when a
 * project was busy and calls _user_cancel() otherwise.
 */
#[CoversMethod(SodaScsUserOffboardingBatch::class, 'addToAccountDeletes')]
#[Group('soda_scs_manager')]
class SodaScsUserOffboardingBatchAlterTest extends UnitTestCase {

  /**
   * The removal step is inserted and the delete operation is replaced.
   */
  #[DataProvider('provideDeletingMethods')]
  public function testRemovalStepIsInsertedAndDeletingCancelIsReplaced(string $method): void {
    $cancel = $this->cancelOperation(42, $method);
    $batch = ['sets' => [['operations' => [$cancel], 'total' => 1, 'count' => 1]]];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    $this->assertSame([
      [[SodaScsUserOffboardingBatch::class, 'removeApplications'], [42]],
      [[SodaScsUserOffboardingBatch::class, 'deleteAccount'], $cancel[1]],
    ], $batch['sets'][0]['operations']);
  }

  /**
   * The methods of user_cancel() that delete the account.
   *
   * @return array<string, array{0: string}>
   *   The method names.
   */
  public static function provideDeletingMethods(): array {
    return [
      'delete' => ['user_cancel_delete'],
      'reassign' => ['user_cancel_reassign'],
    ];
  }

  /**
   * The totals of the set count the inserted step.
   */
  public function testTotalsIncludeInsertedStep(): void {
    $batch = [
      'sets' => [[
        'operations' => [$this->cancelOperation(42, 'user_cancel_delete')],
        'total' => 1,
        'count' => 1,
      ],
      ],
    ];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    $this->assertSame(2, $batch['sets'][0]['total']);
    $this->assertSame(2, $batch['sets'][0]['count']);
  }

  /**
   * The original _user_cancel operation is gone, only deleteAccount() remains.
   */
  public function testNoPlainCancelOperationRemainsForDeletingMethod(): void {
    $batch = [
      'sets' => [[
        'operations' => [$this->cancelOperation(42, 'user_cancel_delete')],
        'total' => 1,
        'count' => 1,
      ],
      ],
    ];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    $callbacks = array_column($batch['sets'][0]['operations'], 0);
    $this->assertNotContains('_user_cancel', $callbacks);
  }

  /**
   * The delete operation keeps the account, the method and the form values.
   */
  public function testDeleteOperationKeepsOriginalArguments(): void {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn(42);
    $edit = ['user_cancel_notify' => TRUE];
    $batch = [
      'sets' => [[
        'operations' => [['_user_cancel', [$edit, $account, 'user_cancel_reassign']]],
        'total' => 1,
        'count' => 1,
      ],
      ],
    ];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    [$callback, $arguments] = $batch['sets'][0]['operations'][1];
    $this->assertSame([SodaScsUserOffboardingBatch::class, 'deleteAccount'], $callback);
    $this->assertSame($edit, $arguments[0]);
    $this->assertSame($account, $arguments[1]);
    $this->assertSame('user_cancel_reassign', $arguments[2]);
  }

  /**
   * Operations of other callbacks keep their place between the new steps.
   */
  public function testOtherOperationsKeepTheirOrderAroundTheNewSteps(): void {
    $before = ['node_mass_update', [[1], []]];
    $cancel = $this->cancelOperation(42, 'user_cancel_delete');
    $after = ['node_mass_update', [[2], []]];
    $batch = ['sets' => [['operations' => [$before, $cancel, $after], 'total' => 3, 'count' => 3]]];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    $this->assertSame([
      $before,
      [[SodaScsUserOffboardingBatch::class, 'removeApplications'], [42]],
      [[SodaScsUserOffboardingBatch::class, 'deleteAccount'], $cancel[1]],
      $after,
    ], $batch['sets'][0]['operations']);
    $this->assertSame(4, $batch['sets'][0]['total']);
  }

  /**
   * Every account of a bulk cancel gets its own step and its own delete.
   */
  public function testEachAccountOfBulkCancelGetsItsOwnStep(): void {
    $first = $this->cancelOperation(5, 'user_cancel_delete');
    $second = $this->cancelOperation(6, 'user_cancel_reassign');
    $batch = ['sets' => [['operations' => [$first, $second], 'total' => 2, 'count' => 2]]];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    $this->assertSame([
      [[SodaScsUserOffboardingBatch::class, 'removeApplications'], [5]],
      [[SodaScsUserOffboardingBatch::class, 'deleteAccount'], $first[1]],
      [[SodaScsUserOffboardingBatch::class, 'removeApplications'], [6]],
      [[SodaScsUserOffboardingBatch::class, 'deleteAccount'], $second[1]],
    ], $batch['sets'][0]['operations']);
    $this->assertSame(4, $batch['sets'][0]['total']);
  }

  /**
   * Methods that keep the account get no removal step.
   */
  #[DataProvider('provideKeepingMethods')]
  public function testKeepingMethodsAreLeftAlone(string $method): void {
    $set = [
      'operations' => [$this->cancelOperation(42, $method)],
      'total' => 1,
      'count' => 1,
    ];
    $batch = ['sets' => [$set]];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    $this->assertSame(['sets' => [$set]], $batch);
  }

  /**
   * The methods of user_cancel() that keep the account.
   *
   * @return array<string, array{0: string}>
   *   The method names.
   */
  public static function provideKeepingMethods(): array {
    return [
      'block' => ['user_cancel_block'],
      'block and unpublish' => ['user_cancel_block_unpublish'],
    ];
  }

  /**
   * Operations of other callbacks are left alone, and no step is added.
   */
  public function testOtherOperationsAreLeftAlone(): void {
    $set = [
      'operations' => [
        ['node_mass_update', [[1], ['status' => 0]]],
        ['_user_cancel', ['not an account']],
        ['_user_cancel', [[], 'not an account', 'user_cancel_delete']],
        'not an operation',
      ],
      'total' => 4,
      'count' => 4,
    ];
    $batch = ['sets' => [$set]];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    $this->assertSame(['sets' => [$set]], $batch);
  }

  /**
   * Only the set with an account delete changes; other sets stay as they are.
   */
  public function testOnlySetWithAccountDeleteChanges(): void {
    $unrelated = ['operations' => [['node_mass_update', [[1], []]]], 'total' => 1, 'count' => 1];
    $batch = [
      'sets' => [
        $unrelated,
      ['operations' => [$this->cancelOperation(42, 'user_cancel_delete')], 'total' => 1, 'count' => 1],
      ],
    ];

    SodaScsUserOffboardingBatch::addToAccountDeletes($batch);

    $this->assertSame($unrelated, $batch['sets'][0]);
    $this->assertSame(2, $batch['sets'][1]['total']);
  }

  /**
   * A batch without sets or operations is no problem.
   */
  public function testBatchWithoutSetsOrOperationsIsLeftAlone(): void {
    $empty = [];
    SodaScsUserOffboardingBatch::addToAccountDeletes($empty);
    $this->assertSame([], $empty);

    $noOperations = ['sets' => [['title' => 'Nothing']]];
    SodaScsUserOffboardingBatch::addToAccountDeletes($noOperations);
    $this->assertSame(['sets' => [['title' => 'Nothing']]], $noOperations);
  }

  /**
   * Builds the operation user_cancel() queues.
   *
   * @return array{0: string, 1: array}
   *   The callback and its arguments.
   */
  private function cancelOperation(int $userId, string $method): array {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn($userId);
    return ['_user_cancel', [[], $account, $method]];
  }

}
