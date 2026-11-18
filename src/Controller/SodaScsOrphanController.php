<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Service\SodaScsOrphanDetector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports orphan backend resources and entities without a project.
 */
final class SodaScsOrphanController extends ControllerBase {

  public function __construct(
    protected SodaScsOrphanDetector $orphanDetector,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.orphan_detector'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Scans backends and entities and lists orphan resources.
   *
   * @return array
   *   Render array with summary and tables.
   */
  public function report(): array {
    $report = $this->orphanDetector->detect();
    $total = $this->orphanDetector->countOrphans($report);

    $build = [
      '#cache' => ['max-age' => 0],
    ];

    foreach ($report['errors'] as $error) {
      $this->messenger()->addError($error);
    }

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['soda-scs-manager--orphan-summary']],
      'text' => [
        '#markup' => '<p>' . $this->t('Scanned at @time. Found @count orphan resource(s).', [
          '@time' => $this->dateFormatter->format($report['scannedAt'], 'short'),
          '@count' => $total,
        ]) . '</p>',
      ],
    ];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Orphans are Portainer stacks, MariaDB databases or triplestore repositories without a matching SCS Manager entity, and stacks or components that still point at a deleted project. Shared infrastructure databases are ignored.') . '</p>',
    ];

    $build['dangling_projects'] = $this->buildDanglingProjectTable(
      $report['danglingProjectEntities'] ?? [],
    );
    $build['portainer'] = $this->buildPortainerTable($report['portainerStacks']);
    $build['databases'] = $this->buildSimpleTable(
      $this->t('Orphan databases (@count)', ['@count' => count($report['databases'])]),
      $this->t('Database'),
      $report['databases'],
      $this->t('No orphan databases found.'),
    );
    $build['repositories'] = $this->buildSimpleTable(
      $this->t('Orphan triplestore repositories (@count)', [
        '@count' => count($report['repositories']),
      ]),
      $this->t('Repository'),
      $report['repositories'],
      $this->t('No orphan triplestore repositories found.'),
    );

    return $build;
  }

  /**
   * Builds the table of entities that reference a missing project.
   *
   * @param array $entities
   *   Dangling project entities from the orphan detector.
   *
   * @return array
   *   Render array.
   */
  protected function buildDanglingProjectTable(array $entities): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t('Stacks and components with a deleted project (@count)', [
        '@count' => count($entities),
      ]),
      '#open' => $entities !== [],
    ];

    if ($entities === []) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No stacks or components with a deleted project found.') . '</p>',
      ];
      return $build;
    }

    $rows = [];
    foreach ($entities as $entity) {
      $routeName = $entity['entityType'] === 'soda_scs_stack'
        ? 'entity.soda_scs_stack.canonical'
        : 'entity.soda_scs_component.canonical';
      $routeParam = $entity['entityType'] === 'soda_scs_stack'
        ? 'soda_scs_stack'
        : 'soda_scs_component';
      $link = Link::fromTextAndUrl(
        $entity['label'] !== '' ? $entity['label'] : $entity['machineName'],
        Url::fromRoute($routeName, [$routeParam => $entity['id']]),
      )->toString();
      $rows[] = [
        $entity['entityType'] === 'soda_scs_stack'
          ? $this->t('Stack')
          : $this->t('Component'),
        $entity['bundle'],
        ['data' => ['#markup' => $link]],
        $entity['machineName'],
        implode(', ', $entity['missingProjectIds']),
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Type'),
        $this->t('Bundle'),
        $this->t('Label'),
        $this->t('Machine name'),
        $this->t('Missing project ID'),
      ],
      '#rows' => $rows,
    ];

    return $build;
  }

  /**
   * Builds the Portainer orphan table.
   *
   * @param list<array{id: string, name: string, status: string}> $stacks
   *   Orphan stacks.
   *
   * @return array
   *   Render array.
   */
  protected function buildPortainerTable(array $stacks): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t('Orphan Portainer stacks (@count)', [
        '@count' => count($stacks),
      ]),
      '#open' => $stacks !== [],
    ];

    if ($stacks === []) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No orphan Portainer stacks found.') . '</p>',
      ];
      return $build;
    }

    $rows = [];
    foreach ($stacks as $stack) {
      $rows[] = [
        $stack['name'],
        $stack['id'],
        $stack['status'],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Portainer ID'),
        $this->t('Status'),
      ],
      '#rows' => $rows,
    ];

    return $build;
  }

  /**
   * Builds a single-column orphan table inside a details element.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   Details title.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $columnLabel
   *   Table header.
   * @param list<string> $items
   *   Orphan names.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $emptyMessage
   *   Message when the list is empty.
   *
   * @return array
   *   Render array.
   */
  protected function buildSimpleTable($title, $columnLabel, array $items, $emptyMessage): array {
    $build = [
      '#type' => 'details',
      '#title' => $title,
      '#open' => $items !== [],
    ];

    if ($items === []) {
      $build['empty'] = [
        '#markup' => '<p>' . $emptyMessage . '</p>',
      ];
      return $build;
    }

    $rows = [];
    foreach ($items as $item) {
      $rows[] = [$item];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [$columnLabel],
      '#rows' => $rows,
    ];

    return $build;
  }

}
