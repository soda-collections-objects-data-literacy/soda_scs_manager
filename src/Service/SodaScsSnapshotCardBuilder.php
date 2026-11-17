<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Builds the data of the snapshots card on the application detail page.
 */
final class SodaScsSnapshotCardBuilder {

  /**
   * How many of the newest snapshots the card lists.
   */
  public const MAX_LISTED_SNAPSHOTS = 5;

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Builds the card for a stack or a component.
   *
   * @param \Drupal\Core\Entity\EntityInterface $application
   *   The stack or component whose detail page is built.
   *
   * @return array<string, mixed>|null
   *   Keys: createUrl (string|null), total (int), snapshots (list of
   *   label, url and created). NULL when the viewer can neither create a
   *   snapshot nor see one, so the page shows no empty card.
   */
  public function build(EntityInterface $application): ?array {
    $routeName = match (TRUE) {
      $application instanceof SodaScsStackInterface => 'entity.soda_scs_stack.snapshot',
      $application instanceof SodaScsComponentInterface => 'entity.soda_scs_component.snapshot',
      default => NULL,
    };
    if ($routeName === NULL || !$application->hasField('snapshots')) {
      return NULL;
    }

    $createUrl = Url::fromRoute($routeName, [$application->getEntityTypeId() => $application->id()]);
    $canCreate = $createUrl->access();

    $visibleSnapshots = [];
    foreach ($application->get('snapshots')->referencedEntities() as $snapshot) {
      if ($snapshot instanceof SodaScsSnapshotInterface && $snapshot->access('view')) {
        $visibleSnapshots[] = $snapshot;
      }
    }
    if (!$canCreate && $visibleSnapshots === []) {
      return NULL;
    }

    usort($visibleSnapshots, static fn (SodaScsSnapshotInterface $a, SodaScsSnapshotInterface $b): int => $b->getCreatedTime() <=> $a->getCreatedTime());

    $listed = [];
    foreach (array_slice($visibleSnapshots, 0, self::MAX_LISTED_SNAPSHOTS) as $snapshot) {
      $listed[] = [
        'label' => (string) $snapshot->label(),
        'url' => $snapshot->toUrl()->toString(),
        'created' => $this->dateFormatter->format($snapshot->getCreatedTime(), 'short'),
      ];
    }

    return [
      'createUrl' => $canCreate ? $createUrl->toString() : NULL,
      'total' => count($visibleSnapshots),
      'snapshots' => $listed,
    ];
  }

}
