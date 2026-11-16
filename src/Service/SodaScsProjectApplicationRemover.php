<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes a project's WissKI stacks and loose databases and triplestores.
 *
 * Runs before a project is deleted, from the delete form and from the account
 * delete. An application of the project owner is deleted with its service
 * data; one of another member only loses the link to this project and stays.
 * An application belongs to at most one project
 * (soda_scs_manager_entity_presave()). JupyterHub and WebProtégé exist once
 * per project and are removed by the callers.
 */
final class SodaScsProjectApplicationRemover {

  use StringTranslationTrait;

  /**
   * Component bundles that exist on their own, outside a stack.
   */
  private const LOOSE_COMPONENT_BUNDLES = [
    'soda_scs_sql_component',
    'soda_scs_triplestore_component',
  ];

  /**
   * Stack bundles removed with the project.
   */
  private const STACK_BUNDLES = ['soda_scs_wisski_stack'];

  /**
   * Constructs the remover.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.component.actions')]
    private readonly SodaScsComponentActionsInterface $componentActions,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    private readonly LoggerChannelInterface $logger,
    #[Autowire(service: 'soda_scs_manager.stack.actions')]
    private readonly SodaScsStackActionsInterface $stackActions,
  ) {}

  /**
   * Deletes or detaches the project's WissKI stacks and loose components.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project about to be deleted.
   * @param bool $stopOnFailure
   *   TRUE stops at the first application that fails, so the caller can keep
   *   the project; FALSE logs the failure and goes on with the others.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Failure names the first application that could not be removed; its
   *   data 'message' holds the translatable user message.
   */
  public function removeApplications(SodaScsProjectInterface $project, bool $stopOnFailure): SodaScsResult {
    try {
      $this->dropUnlinkedComponents($project);
      return $this->removeLinked($project, $stopOnFailure);
    }
    catch (\Throwable $throwable) {
      return $this->lookupFailure($project, $throwable);
    }
  }

  /**
   * Deletes or detaches one application of the project: a stack first.
   *
   * Lets the delete form remove the applications one per batch request, so a
   * project with many WissKI instances does not run into the request timeout.
   * A removed application no longer lists the project, so the next call goes
   * on with the next one.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project about to be deleted.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success with data 'application' holding the label of the removed
   *   application, or NULL when none is left, and 'key' its entity type and
   *   id. Failure as in removeApplications().
   */
  public function removeNextApplication(SodaScsProjectInterface $project): SodaScsResult {
    try {
      foreach ($this->loadLinked($project, 'soda_scs_stack', self::STACK_BUNDLES) as $stack) {
        if ($stack instanceof SodaScsStackInterface) {
          return $this->removeStack($project, $stack) ?? $this->removedResult($stack);
        }
      }
      foreach ($this->loadLinked($project, 'soda_scs_component', self::LOOSE_COMPONENT_BUNDLES) as $component) {
        if ($component instanceof SodaScsComponentInterface && !$this->hasExistingStack($component)) {
          return $this->removeComponent($project, $component) ?? $this->removedResult($component);
        }
      }
      return SodaScsResult::success(data: ['application' => NULL], message: '');
    }
    catch (\Throwable $throwable) {
      return $this->lookupFailure($project, $throwable);
    }
  }

  /**
   * Logs an application that could not be deleted and builds the result.
   */
  private function deleteFailure(SodaScsProjectInterface $project, ContentEntityInterface $entity, string $reason): SodaScsResult {
    $this->logFailure($project, $entity, $reason);
    return $this->failureResult($this->t('Could not delete the application @application of project @project. The project was not deleted.', [
      '@application' => $entity->label(),
      '@project' => $project->label(),
    ]));
  }

  /**
   * Takes the project off an application that stays.
   *
   * The project's connectedComponents loses it too: the caller saves and
   * deletes this project object later, and the project hooks
   * (_soda_scs_manager_sync_project_component_relationship()) would link every
   * component still listed there to the project again.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult|null
   *   NULL on success, otherwise the failure.
   */
  private function detach(SodaScsProjectInterface $project, ContentEntityInterface $entity): ?SodaScsResult {
    $projectId = (int) $project->id();
    $links = array_values(array_filter(
      $entity->get('partOfProjects')->getValue(),
      static fn (array $item): bool => (int) ($item['target_id'] ?? 0) !== $projectId,
    ));
    $entity->set('partOfProjects', $links);
    try {
      $entity->save();
    }
    catch (\Throwable $throwable) {
      $this->logFailure($project, $entity, $throwable->getMessage());
      return $this->failureResult($this->t('Could not detach the application @application from project @project. The project was not deleted.', [
        '@application' => $entity->label(),
        '@project' => $project->label(),
      ]));
    }
    if ($entity instanceof SodaScsComponentInterface && $project->hasField('connectedComponents')) {
      $componentId = (int) $entity->id();
      $project->set('connectedComponents', array_values(array_filter(
        $project->get('connectedComponents')->getValue(),
        static fn (array $item): bool => (int) ($item['target_id'] ?? 0) !== $componentId,
      )));
    }
    return NULL;
  }

  /**
   * Drops components from connectedComponents that no longer list the project.
   *
   * The detach() call changes the project object only in memory. When the
   * applications were removed in earlier requests (the delete form's batch),
   * the project loaded now still lists them, and its hooks would link them
   * again.
   */
  private function dropUnlinkedComponents(SodaScsProjectInterface $project): void {
    if (!$project->hasField('connectedComponents')) {
      return;
    }
    $kept = [];
    foreach ($project->get('connectedComponents')->referencedEntities() as $component) {
      if ($component instanceof ContentEntityInterface && $this->listsProject($component, $project)) {
        $kept[] = ['target_id' => $component->id()];
      }
    }
    $project->set('connectedComponents', $kept);
  }

  /**
   * Builds a failure result that keeps the markup for the messenger.
   *
   * The message stays TranslatableMarkup in data: a string cast of an escaped
   * placeholder is escaped once more when shown ("R&amp;D").
   */
  private function failureResult(TranslatableMarkup $message): SodaScsResult {
    return new SodaScsResult(
      success: FALSE,
      data: ['message' => $message],
      error: 'application_not_removed',
      message: (string) $message,
    );
  }

  /**
   * Whether a component is part of a stack that still exists.
   *
   * Such a component goes or stays with its stack. One whose stack is gone
   * (e.g. after a forced stack delete) is handled as a loose component.
   */
  private function hasExistingStack(SodaScsComponentInterface $component): bool {
    return $component->get('partOfStack')->entity !== NULL;
  }

  /**
   * Whether the application is deleted with the project: the owner's only.
   */
  private function isDeletedWithProject(SodaScsProjectInterface $project, ContentEntityInterface $entity): bool {
    return (int) $entity->get('owner')->target_id === (int) $project->getOwnerId();
  }

  /**
   * Whether the application lists the project.
   */
  private function listsProject(ContentEntityInterface $entity, SodaScsProjectInterface $project): bool {
    $projectIds = array_map('intval', array_column($entity->get('partOfProjects')->getValue(), 'target_id'));
    return in_array((int) $project->id(), $projectIds, TRUE);
  }

  /**
   * Loads applications of the given bundles that list the project.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param string $entityTypeId
   *   Either soda_scs_stack or soda_scs_component.
   * @param list<string> $bundles
   *   The bundles to load.
   *
   * @return list<\Drupal\Core\Entity\EntityInterface>
   *   The applications, ordered by id.
   */
  private function loadLinked(SodaScsProjectInterface $project, string $entityTypeId, array $bundles): array {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $bundles, 'IN')
      ->condition('partOfProjects', $project->id())
      ->sort('id')
      ->execute();
    return $ids === [] ? [] : array_values($storage->loadMultiple($ids));
  }

  /**
   * Logs an application that could not be removed.
   */
  private function logFailure(SodaScsProjectInterface $project, ContentEntityInterface $entity, string $reason): void {
    $this->logger->error('Could not remove @type @id from project @project before its deletion: @message', [
      '@id' => $entity->id(),
      '@message' => $reason,
      '@project' => $project->id(),
      '@type' => $entity->getEntityTypeId(),
    ]);
  }

  /**
   * Logs a failed lookup of the project's applications and builds the result.
   *
   * A failed lookup must not let the caller go on to delete the project.
   */
  private function lookupFailure(SodaScsProjectInterface $project, \Throwable $throwable): SodaScsResult {
    $this->logger->error('Could not look up the applications of project @project before its deletion: @message', [
      '@message' => $throwable->getMessage(),
      '@project' => $project->id(),
    ]);
    return $this->failureResult($this->t('Could not delete the applications of project @project. The project was not deleted.', [
      '@project' => $project->label(),
    ]));
  }

  /**
   * Deletes or detaches one loose component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult|null
   *   NULL on success, otherwise the failure.
   */
  private function removeComponent(SodaScsProjectInterface $project, SodaScsComponentInterface $component): ?SodaScsResult {
    if (!$this->isDeletedWithProject($project, $component)) {
      return $this->detach($project, $component);
    }
    try {
      $result = $this->componentActions->deleteComponent($component);
    }
    catch (\Throwable $throwable) {
      return $this->deleteFailure($project, $component, $throwable->getMessage());
    }
    if (empty($result['success'])) {
      return $this->deleteFailure($project, $component, (string) ($result['error'] ?? $result['message'] ?? ''));
    }
    return NULL;
  }

  /**
   * Removes the stacks first, then the loose components.
   *
   * A stack takes its database and triplestore along, so the loose
   * components are looked up afterwards.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   See removeApplications().
   */
  private function removeLinked(SodaScsProjectInterface $project, bool $stopOnFailure): SodaScsResult {
    $firstFailure = NULL;
    foreach ($this->loadLinked($project, 'soda_scs_stack', self::STACK_BUNDLES) as $stack) {
      if (!$stack instanceof SodaScsStackInterface) {
        continue;
      }
      $failure = $this->removeStack($project, $stack);
      if ($failure !== NULL) {
        $firstFailure ??= $failure;
        if ($stopOnFailure) {
          return $failure;
        }
      }
    }

    foreach ($this->loadLinked($project, 'soda_scs_component', self::LOOSE_COMPONENT_BUNDLES) as $component) {
      if (!$component instanceof SodaScsComponentInterface || $this->hasExistingStack($component)) {
        continue;
      }
      $failure = $this->removeComponent($project, $component);
      if ($failure !== NULL) {
        $firstFailure ??= $failure;
        if ($stopOnFailure) {
          return $failure;
        }
      }
    }

    return $firstFailure ?? SodaScsResult::success(data: [], message: '');
  }

  /**
   * Deletes or detaches one stack; a kept stack keeps its components too.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult|null
   *   NULL on success, otherwise the failure.
   */
  private function removeStack(SodaScsProjectInterface $project, SodaScsStackInterface $stack): ?SodaScsResult {
    if (!$this->isDeletedWithProject($project, $stack)) {
      $failure = $this->detach($project, $stack);
      if ($failure !== NULL) {
        return $failure;
      }
      foreach ($stack->get('includedComponents')->referencedEntities() as $component) {
        if ($component instanceof SodaScsComponentInterface && $this->listsProject($component, $project)) {
          $failure = $this->detach($project, $component);
          if ($failure !== NULL) {
            return $failure;
          }
        }
      }
      return NULL;
    }
    try {
      $result = $this->stackActions->deleteStack($stack);
    }
    catch (\Throwable $throwable) {
      return $this->deleteFailure($project, $stack, $throwable->getMessage());
    }
    if (empty($result['success'])) {
      return $this->deleteFailure($project, $stack, (string) ($result['error'] ?? $result['message'] ?? ''));
    }
    return NULL;
  }

  /**
   * Builds the success result of removeNextApplication().
   */
  private function removedResult(ContentEntityInterface $application): SodaScsResult {
    return SodaScsResult::success(data: [
      'application' => (string) $application->label(),
      'key' => $application->getEntityTypeId() . ':' . $application->id(),
    ], message: '');
  }

}
