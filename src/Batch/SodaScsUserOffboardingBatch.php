<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Batch;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsProject;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Service\SodaScsUserOffboarding;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Batch steps that remove an account's applications before the account goes.
 *
 * The account delete itself (_user_cancel(), then hook_user_predelete) runs
 * in one request. Removing the WissKI instances, databases and triplestores
 * of the owned projects there first, one per request, keeps it inside the
 * request time limit. The project locks taken for that are held until the
 * account is deleted. Batch callbacks are called statically and cannot get
 * services injected, so they take them from the container.
 */
final class SodaScsUserOffboardingBatch {

  /**
   * Account cancel methods that delete the account.
   */
  private const DELETING_METHODS = ['user_cancel_delete', 'user_cancel_reassign'];

  /**
   * Requests of one account before the step gives up.
   *
   * Guards against an application that is reported as removed but stays.
   */
  public const MAX_STEPS = 200;

  /**
   * Puts the application removal in front of each account delete of a batch.
   *
   * Called from hook_batch_alter(), so it covers every way core deletes an
   * account through user_cancel(): the account cancel forms, the link of the
   * confirmation e-mail, the profile delete form and drush. The core
   * operation _user_cancel is replaced by deleteAccount(), which calls it.
   *
   * @param array $batch
   *   The batch, before its operations are queued.
   */
  public static function addToAccountDeletes(array &$batch): void {
    foreach ($batch['sets'] ?? [] as $key => $set) {
      $operations = [];
      $added = FALSE;
      foreach ($set['operations'] ?? [] as $operation) {
        $userId = self::deletedUserId($operation);
        if ($userId === NULL) {
          $operations[] = $operation;
          continue;
        }
        $operations[] = [[self::class, 'removeApplications'], [$userId]];
        $operations[] = [[self::class, 'deleteAccount'], $operation[1]];
        $added = TRUE;
      }
      if ($added) {
        $batch['sets'][$key]['operations'] = $operations;
        $batch['sets'][$key]['total'] = count($operations);
        $batch['sets'][$key]['count'] = count($operations);
      }
    }
  }

  /**
   * Batch step: deletes the account through core, or refuses.
   *
   * Refuses while a project of the account was being set up or deleted when
   * removeApplications() started: its applications would be deleted without
   * the project lock. Otherwise runs _user_cancel() with the project locks
   * still held (purge() takes them as its own) and releases them afterwards,
   * so no demo job attempt fits between the removal and the delete.
   *
   * @param array $edit
   *   The cancel form values, as passed to _user_cancel().
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param string $method
   *   The cancel method.
   * @param array $context
   *   The batch context.
   */
  public static function deleteAccount(array $edit, UserInterface $account, string $method, array &$context): void {
    $userId = (int) $account->id();
    $busy = $context['results']['soda_scs_busy_projects'][$userId] ?? [];
    $locked = $context['results']['soda_scs_locked_projects'][$userId] ?? [];
    $offboarding = \Drupal::service('soda_scs_manager.user_offboarding');
    if ($busy !== []) {
      $labels = array_map(
        static fn (SodaScsProjectInterface $project): string => (string) $project->label(),
        array_filter(SodaScsProject::loadMultiple($busy), static fn ($project): bool => $project instanceof SodaScsProjectInterface),
      );
      \Drupal::logger('soda_scs_manager')->warning('Account @user was not deleted: projects @projects were being set up or deleted.', [
        '@projects' => implode(', ', $busy),
        '@user'     => $userId,
      ]);
      \Drupal::messenger()->addError(new TranslatableMarkup('The account %name was not deleted: a project is being set up or deleted right now (@projects). Try again when that is done.', [
        '%name'     => $account->getDisplayName(),
        '@projects' => implode(', ', $labels),
      ]));
      return;
    }

    $offboarding->holdProjectLocks($locked);
    try {
      _user_cancel($edit, $account, $method);
    }
    finally {
      $offboarding->holdProjectLocks([]);
      $offboarding->releaseProjectLocks($locked);
    }
  }

  /**
   * Batch step: removes one application of the account's own projects.
   *
   * Takes the project lock of every owned project first and holds it across
   * the requests, then goes through the projects one after the other. When a
   * lock is busy, it removes nothing: deleteAccount() refuses. A project
   * whose application fails is left as it is: hook_user_predelete tries its
   * applications again and logs what still fails, as before. The locks are
   * handed to deleteAccount() in the batch results.
   *
   * @param int $userId
   *   The account about to be deleted.
   * @param array $context
   *   The batch context.
   */
  public static function removeApplications(int $userId, array &$context): void {
    $offboarding = \Drupal::service('soda_scs_manager.user_offboarding');
    $sandbox = &$context['sandbox'];
    if (!isset($sandbox['projects'])) {
      $user = User::load($userId);
      $owned = $user instanceof UserInterface && $userId > 1 ? $offboarding->ownedProjectIds($user) : [];
      $locked = $offboarding->lockProjectsForAccountDelete($owned);
      $busy = array_values(array_diff($owned, $locked));
      if ($busy !== []) {
        $offboarding->releaseProjectLocks($locked);
        $locked = [];
        $context['results']['soda_scs_busy_projects'][$userId] = $busy;
      }
      $context['results']['soda_scs_locked_projects'][$userId] = $locked;
      $sandbox['projects'] = $locked;
      $sandbox['total'] = count($sandbox['projects']);
      $sandbox['steps'] = 0;
      $sandbox['removed'] = [];
    }
    $sandbox['steps']++;
    if ($sandbox['steps'] > self::MAX_STEPS) {
      \Drupal::logger('soda_scs_manager')->error('Gave up removing the applications of user @user before the account delete after @steps steps; the account delete removes the rest.', [
        '@steps' => self::MAX_STEPS,
        '@user'  => $userId,
      ]);
      $sandbox['projects'] = [];
    }
    if ($sandbox['projects'] !== []) {
      self::removeFromCurrentProject($offboarding, $context);
    }
    $context['finished'] = $sandbox['projects'] === []
      ? 1
      : ($sandbox['total'] - count($sandbox['projects'])) / max(1, $sandbox['total']);
  }

  /**
   * The account a batch operation deletes, if it is _user_cancel() deleting.
   */
  private static function deletedUserId(mixed $operation): ?int {
    if (!is_array($operation) || ($operation[0] ?? NULL) !== '_user_cancel') {
      return NULL;
    }
    $arguments = $operation[1] ?? [];
    $account = $arguments[1] ?? NULL;
    if (!$account instanceof UserInterface || !in_array($arguments[2] ?? NULL, self::DELETING_METHODS, TRUE)) {
      return NULL;
    }
    return (int) $account->id();
  }

  /**
   * Removes one application of the first project left in the sandbox.
   *
   * @param \Drupal\soda_scs_manager\Service\SodaScsUserOffboarding $offboarding
   *   The offboarding service.
   * @param array $context
   *   The batch context.
   */
  private static function removeFromCurrentProject(SodaScsUserOffboarding $offboarding, array &$context): void {
    $sandbox = &$context['sandbox'];
    $projectId = $sandbox['projects'][0];
    $project = SodaScsProject::load($projectId);
    if (!$project instanceof SodaScsProjectInterface) {
      array_shift($sandbox['projects']);
      return;
    }

    $removed = $offboarding->removeNextProjectApplication($project);
    $application = $removed->data['application'] ?? NULL;
    $key = $removed->data['key'] ?? NULL;
    if (!$removed->success || $application === NULL) {
      // The remover logged the failure; the account delete tries again.
      array_shift($sandbox['projects']);
      return;
    }
    if (in_array($key, $sandbox['removed'], TRUE)) {
      \Drupal::logger('soda_scs_manager')->error('Application @key of project @project is still there after it was removed; the account delete tries again.', [
        '@key'     => $key,
        '@project' => $projectId,
      ]);
      array_shift($sandbox['projects']);
      return;
    }
    $sandbox['removed'][] = $key;
    $context['message'] = new TranslatableMarkup('Removed @application.', ['@application' => $application]);
  }

}
