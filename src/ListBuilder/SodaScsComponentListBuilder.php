<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ListBuilder;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines a class to build a listing of SODa SCS Component entities.
 *
 * @ingroup soda_scs_manager
 */
class SodaScsComponentListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The pager manager service.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected PagerManagerInterface $pagerManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->currentUser = $container->get('current_user');
    $instance->formBuilder = $container->get('form_builder');
    $instance->requestStack = $container->get('request_stack');
    $instance->pagerManager = $container->get('pager.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    $entityQuery = $this->storage->getQuery()
      ->accessCheck(TRUE);

    // Apply filters from request.
    $request = $this->requestStack->getCurrentRequest();
    $filters = $request->query->all();

    // NAME filter (contains).
    if (!empty($filters['name'])) {
      $entityQuery->condition('label', '%' . $filters['name'] . '%', 'LIKE');
    }

    // ID filter (exact match).
    if (!empty($filters['id'])) {
      $entityQuery->condition('id', $filters['id']);
    }

    // Type filter (bundle dropdown).
    if (!empty($filters['type'])) {
      $entityQuery->condition('bundle', $filters['type']);
    }

    // Machine Name filter (contains).
    if (!empty($filters['machine_name'])) {
      $entityQuery->condition('machineName', '%' . $filters['machine_name'] . '%', 'LIKE');
    }

    // Owner filter (contains - matches username).
    if (!empty($filters['owner'])) {
      // Load users whose name contains the search string.
      $userStorage = \Drupal::entityTypeManager()->getStorage('user');
      $userQuery = $userStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('name', '%' . $filters['owner'] . '%', 'LIKE');
      $userIds = $userQuery->execute();

      if (!empty($userIds)) {
        $entityQuery->condition('owner', $userIds, 'IN');
      }
      else {
        // If no users found, ensure no results.
        $entityQuery->condition('owner', 0);
      }
    }

    // Health Status filter (dropdown).
    // @todo Implement health status field and filter logic.
    if (!empty($filters['health_status'])) {
      // Placeholder for future implementation.
      // $entityQuery->condition('health_status', $filters['health_status']);.
    }

    // Access control - non-admins see only their own components.
    if (!$this->currentUser->hasPermission('soda scs manager admin')) {
      $entityQuery->condition('owner', $this->currentUser->id());
    }

    // Apply table sorting from URL parameters.
    $sortField = $request->query->get('sort');
    $sortOrder = $request->query->get('order', 'asc');

    // Validate sort field to prevent injection.
    $allowedSortFields = ['label', 'id', 'bundle', 'machineName', 'owner', 'created'];

    // For owner sorting, we need special handling to sort by display name.
    $sortByOwnerName = FALSE;
    $isDefaultSort = empty($sortField);

    if (!empty($sortField) && $sortField === 'owner') {
      $sortByOwnerName = TRUE;
      // Don't add any other sorts; we'll handle everything after loading.
    }
    elseif (!empty($sortField) && in_array($sortField, $allowedSortFields)) {
      // User-specified sort (not owner).
      $entityQuery->sort($sortField, strtoupper($sortOrder));

      // Add secondary sorts for consistency.
      if ($sortField !== 'label') {
        $entityQuery->sort('label', 'ASC');
      }
      if ($sortField !== 'id') {
        $entityQuery->sort('id', 'ASC');
      }
    }
    elseif ($isDefaultSort) {
      // Default sort by owner name, then type, then label.
      $sortByOwnerName = TRUE;
    }

    // If not sorting by owner name, apply pagination normally.
    if (!$sortByOwnerName) {
      $entityQuery->pager(10);
      $entityIds = $entityQuery->execute();
      $entities = $this->storage->loadMultiple($entityIds);
    }
    else {
      // For owner name sorting, we need to load ALL entities, sort them,
      // then manually apply pagination.
      $entityIds = $entityQuery->execute();
      $totalEntities = $this->storage->loadMultiple($entityIds);

      // Sort by owner display name.
      $sortedEntities = $this->sortByOwnerDisplayName($totalEntities, $sortOrder);

      // Get current page and items per page.
      $itemsPerPage = 10;
      $currentPage = $this->pagerManager->findPage();
      $offset = $currentPage * $itemsPerPage;
      $total = count($sortedEntities);

      // Create pager.
      $this->pagerManager->createPager($total, $itemsPerPage);

      // Apply manual pagination.
      $entities = array_slice($sortedEntities, $offset, $itemsPerPage, TRUE);
    }

    return $entities;
  }

  /**
   * Sorts entities by owner display name.
   *
   * @param array $entities
   *   Array of entities to sort.
   * @param string $order
   *   Sort order: 'asc' or 'desc'.
   *
   * @return array
   *   Sorted entities.
   */
  protected function sortByOwnerDisplayName(array $entities, string $order = 'asc'): array {
    // Create an array with owner display names for sorting.
    $sortable = [];
    foreach ($entities as $id => $entity) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity */
      $owner = $entity->getOwner();
      $ownerName = $owner ? $owner->getDisplayName() : '';
      $bundle = $entity->bundle();
      $label = $entity->label();

      $sortable[$id] = [
        'owner_name' => mb_strtolower($ownerName),
        'bundle' => $bundle,
        'label' => mb_strtolower($label),
        'entity' => $entity,
      ];
    }

    // Sort the array.
    usort($sortable, function ($a, $b) use ($order) {
      // First by owner name.
      $comparison = strcmp($a['owner_name'], $b['owner_name']);
      if ($comparison !== 0) {
        return $order === 'desc' ? -$comparison : $comparison;
      }

      // Then by bundle.
      $comparison = strcmp($a['bundle'], $b['bundle']);
      if ($comparison !== 0) {
        return $comparison;
      }

      // Finally by label.
      return strcmp($a['label'], $b['label']);
    });

    // Extract entities in sorted order.
    $sorted = [];
    foreach ($sortable as $item) {
      $sorted[$item['entity']->id()] = $item['entity'];
    }

    return $sorted;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $request = $this->requestStack->getCurrentRequest();
    $currentSort = $request->query->get('sort');
    $currentOrder = $request->query->get('order', 'asc');

    // Define sortable headers.
    $header['label'] = $this->buildSortableHeader('label', $this->t('Name'), $currentSort, $currentOrder);
    $header['id'] = $this->buildSortableHeader('id', $this->t('ID'), $currentSort, $currentOrder);
    $header['type'] = $this->buildSortableHeader('bundle', $this->t('Type'), $currentSort, $currentOrder);
    $header['machineName'] = $this->buildSortableHeader('machineName', $this->t('Machine Name'), $currentSort, $currentOrder);
    $header['owner'] = $this->buildSortableHeader('owner', $this->t('Owner'), $currentSort, $currentOrder);
    $header['health'] = $this->t('Health Status');
    $header['created'] = $this->buildSortableHeader('created', $this->t('Created'), $currentSort, $currentOrder);

    return $header + parent::buildHeader();
  }

  /**
   * Builds a sortable header cell.
   *
   * @param string $field
   *   The field name to sort by.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The header label.
   * @param string|null $currentSort
   *   The currently sorted field.
   * @param string $currentOrder
   *   The current sort order (asc or desc).
   *
   * @return array
   *   The header cell render array.
   */
  protected function buildSortableHeader(string $field, TranslatableMarkup $label, ?string $currentSort, string $currentOrder): array {
    $request = $this->requestStack->getCurrentRequest();
    $queryParams = $request->query->all();

    // Determine the new sort order: asc -> desc -> reset.
    $isActive = $currentSort === $field;
    $shouldReset = FALSE;

    if ($isActive) {
      if ($currentOrder === 'asc') {
        // First click was asc, now go to desc.
        $newOrder = 'desc';
      }
      else {
        // Second click was desc, now reset to default.
        $shouldReset = TRUE;
      }
    }
    else {
      // Not currently sorted by this field, start with asc.
      $newOrder = 'asc';
    }

    // Reset to first page when sorting changes.
    unset($queryParams['page']);

    // Build the URL with updated sort parameters.
    if ($shouldReset) {
      // Remove sort parameters to return to default sorting.
      unset($queryParams['sort']);
      unset($queryParams['order']);
    }
    else {
      $queryParams['sort'] = $field;
      $queryParams['order'] = $newOrder;
    }

    $url = Url::fromRoute('entity.soda_scs_component.collection', [], [
      'query' => $queryParams,
    ]);

    // Build CSS classes for sort state.
    $linkClasses = ['sortable-header'];
    $thClasses = [];

    if ($isActive) {
      $thClasses[] = 'is-active';
      $linkClasses[] = 'is-active';
      $linkClasses[] = 'sort-' . $currentOrder;
    }

    return [
      'data' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $url,
        '#attributes' => [
          'class' => $linkClasses,
          'title' => $this->t('Sort by @field', ['@field' => $label]),
        ],
      ],
      'class' => $thClasses,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity */
    $bundle = $entity->bundle();

    // Create a link to the entity.
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      Url::fromRoute('entity.soda_scs_component.canonical', [
        'soda_scs_component' => $entity->id(),
      ])
    )->toString();

    // ID.
    $row['id'] = $entity->id();

    // Format the bundle type to be more readable.
    $row['type'] = $this->formatBundleType($bundle);

    // Machine name.
    $row['machineName'] = [
      'data' => [
        '#markup' => '<code>' . $entity->get('machineName')->value . '</code>',
      ],
    ];

    // Owner information.
    $owner = $entity->getOwner();
    if ($owner && $owner->id()) {
      $row['owner'] = Link::fromTextAndUrl(
        $owner->getDisplayName(),
        Url::fromRoute('entity.user.canonical', ['user' => $owner->id()])
      )->toString();
    }
    else {
      $row['owner'] = $this->t('Unknown');
    }

    // Health status - will be populated by JavaScript.
    // @todo Implement health status.
    $row['health'] = [
      'data' => [
        '#markup' => '<span class="component-health-status health-pending">(not yet implemented)</span>',
      ],
    ];

    // Created date.
    $created = $entity->get('created')->value;
    $row['created'] = $this->dateFormatter->format($created, 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * Builds the filter form.
   *
   * @return array
   *   The filter form render array.
   */
  protected function buildFilterForm(): array {
    $request = $this->requestStack->getCurrentRequest();
    $filters = $request->query->all();

    $form = [
      '#type' => 'container',
      '#attributes' => ['class' => ['soda-scs-component-filters']],
    ];

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
      '#open' => !empty(array_filter($filters)),
    ];

    $form['filters']['filter_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    // NAME filter (contains).
    $form['filters']['filter_wrapper']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#size' => 20,
      '#default_value' => $filters['name'] ?? '',
      '#placeholder' => $this->t('Contains...'),
    ];

    // ID filter (exact).
    $form['filters']['filter_wrapper']['id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ID'),
      '#size' => 10,
      '#default_value' => $filters['id'] ?? '',
      '#placeholder' => $this->t('Exact match'),
    ];

    // Type filter (dropdown).
    $typeOptions = [
      '' => $this->t('- Any -'),
      'soda_scs_filesystem_component' => $this->t('Inter-App folder'),
      'soda_scs_sql_component' => $this->t('SQL Database'),
      'soda_scs_triplestore_component' => $this->t('Triplestore'),
      'soda_scs_webprotege_component' => $this->t('WebProtégé'),
      'soda_scs_wisski_component' => $this->t('WissKI'),
    ];

    $form['filters']['filter_wrapper']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => $typeOptions,
      '#default_value' => $filters['type'] ?? '',
    ];

    // Machine Name filter (contains).
    $form['filters']['filter_wrapper']['machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Machine Name'),
      '#size' => 20,
      '#default_value' => $filters['machine_name'] ?? '',
      '#placeholder' => $this->t('Contains...'),
    ];

    // Owner filter (contains).
    $form['filters']['filter_wrapper']['owner'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Owner'),
      '#size' => 20,
      '#default_value' => $filters['owner'] ?? '',
      '#placeholder' => $this->t('Username contains...'),
    ];

    // Health Status filter (dropdown).
    $healthOptions = [
      '' => $this->t('- Any -'),
      'healthy' => $this->t('Healthy'),
      'warning' => $this->t('Warning'),
      'error' => $this->t('Error'),
      'unknown' => $this->t('Unknown'),
    ];

    $form['filters']['filter_wrapper']['health_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Health Status'),
      '#options' => $healthOptions,
      '#default_value' => $filters['health_status'] ?? '',
      '#disabled' => TRUE,
      '#description' => $this->t('Not yet implemented'),
    ];

    // Filter actions.
    $form['filters']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#button_type' => 'primary',
      '#submit' => ['::submitFilterForm'],
    ];

    $form['filters']['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute('entity.soda_scs_component.collection'),
      '#attributes' => ['class' => ['button']],
    ];

    // Convert to markup since we're not using FormBuilder for this simple case.
    return $this->convertFormToMarkup($form, $filters);
  }

  /**
   * Converts the filter form to markup for rendering.
   *
   * @param array $form
   *   The form array.
   * @param array $filters
   *   Current filter values.
   *
   * @return array
   *   The render array.
   */
  protected function convertFormToMarkup(array $form, array $filters): array {
    $hasFilters = !empty(array_filter($filters, function ($value, $key) {
      return !in_array($key, ['sort', 'order', 'page']) && !empty($value);
    }, ARRAY_FILTER_USE_BOTH));

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['soda-scs-component-filters']],
    ];

    $build['form'] = [
      '#type' => 'html_tag',
      '#tag' => 'form',
      '#attributes' => [
        'method' => 'get',
        'class' => ['soda-scs-filters-form'],
      ],
    ];

    // Build details element manually for better control.
    $build['form']['details'] = [
      '#type' => 'html_tag',
      '#tag' => 'details',
      '#attributes' => [
        'class' => ['soda-scs-filter-details'],
      ],
    ];

    if ($hasFilters) {
      $build['form']['details']['#attributes']['open'] = 'open';
    }

    // Custom summary with proper structure.
    $build['form']['details']['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'summary',
      '#attributes' => ['class' => ['filter-summary']],
    ];

    $build['form']['details']['summary']['icon'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['class' => ['filter-caret']],
      '#value' => '▶',
    ];

    $build['form']['details']['summary']['text'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['class' => ['filter-title']],
      '#value' => $this->t('Filters'),
    ];

    $build['form']['details']['fields'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-inline', 'filter-fields']],
    ];

    // Add filter fields.
    $fields = [
      'name' => ['label' => $this->t('Name'), 'size' => 15, 'placeholder' => $this->t('Contains...')],
      'id' => ['label' => $this->t('ID'), 'size' => 8, 'placeholder' => $this->t('Exact')],
      'machine_name' => ['label' => $this->t('Machine Name'), 'size' => 15, 'placeholder' => $this->t('Contains...')],
      'owner' => ['label' => $this->t('Owner'), 'size' => 15, 'placeholder' => $this->t('Username...')],
    ];

    foreach ($fields as $fieldName => $fieldInfo) {
      $build['form']['details']['fields'][$fieldName] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $fieldInfo['label'],
        ],
        'input' => [
          '#type' => 'html_tag',
          '#tag' => 'input',
          '#attributes' => [
            'type' => 'text',
            'name' => $fieldName,
            'value' => $filters[$fieldName] ?? '',
            'placeholder' => $fieldInfo['placeholder'],
            'size' => $fieldInfo['size'],
            'class' => ['form-text'],
          ],
        ],
      ];
    }

    // Type dropdown with proper option elements.
    $typeOptions = [
      '' => $this->t('- Any Type -'),
      'soda_scs_filesystem_component' => $this->t('Inter-App folder'),
      'soda_scs_sql_component' => $this->t('SQL Database'),
      'soda_scs_triplestore_component' => $this->t('Triplestore'),
      'soda_scs_webprotege_component' => $this->t('WebProtégé'),
      'soda_scs_wisski_component' => $this->t('WissKI'),
    ];

    $selectedType = $filters['type'] ?? '';

    $build['form']['details']['fields']['type'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['form-item']],
    ];

    $build['form']['details']['fields']['type']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'label',
      '#value' => $this->t('Type'),
    ];

    $build['form']['details']['fields']['type']['select'] = [
      '#type' => 'html_tag',
      '#tag' => 'select',
      '#attributes' => [
        'name' => 'type',
        'class' => ['form-select'],
      ],
    ];

    // Add option elements.
    $optionIndex = 0;
    foreach ($typeOptions as $value => $label) {
      $optionAttributes = ['value' => $value];
      if ($selectedType === $value) {
        $optionAttributes['selected'] = 'selected';
      }

      $build['form']['details']['fields']['type']['select']['option_' . $optionIndex] = [
        '#type' => 'html_tag',
        '#tag' => 'option',
        '#attributes' => $optionAttributes,
        '#value' => (string) $label,
      ];
      $optionIndex++;
    }

    // Preserve sort and order parameters.
    if (!empty($filters['sort'])) {
      $build['form']['sort'] = [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'type' => 'hidden',
          'name' => 'sort',
          'value' => $filters['sort'],
        ],
      ];
    }

    if (!empty($filters['order'])) {
      $build['form']['order'] = [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'type' => 'hidden',
          'name' => 'order',
          'value' => $filters['order'],
        ],
      ];
    }

    // Actions.
    $build['form']['details']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions']],
    ];

    $build['form']['details']['actions']['submit'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#attributes' => [
        'type' => 'submit',
        'class' => ['button', 'button--primary', 'form-submit'],
      ],
      '#value' => $this->t('Apply Filters'),
    ];

    $resetUrl = Url::fromRoute('entity.soda_scs_component.collection')->toString();
    $build['form']['details']['actions']['reset'] = [
      '#type' => 'markup',
      '#markup' => '<a href="' . $resetUrl . '" class="button">' . $this->t('Reset') . '</a>',
    ];

    return $build;
  }

  /**
   * Formats the bundle type to be more human-readable.
   *
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The formatted bundle type.
   */
  protected function formatBundleType(string $bundle): TranslatableMarkup|string {
    $typeMap = [
      'soda_scs_filesystem_component'  => $this->t('Inter-App folder'),
      'soda_scs_sql_component' => $this->t('SQL Database'),
      'soda_scs_triplestore_component' => $this->t('Triplestore'),
      'soda_scs_webprotege_component'  => $this->t('WebProtégé'),
      'soda_scs_wisski_component'  => $this->t('WissKI'),
    ];

    return $typeMap[$bundle] ?? $bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = [];

    // Add filter form.
    $build['filters'] = $this->buildFilterForm();

    // Build the table manually with proper sorting support.
    $header = $this->buildHeader();
    $rows = [];

    foreach ($this->load() as $entity) {
      $row = $this->buildRow($entity);
      $rows[$entity->id()] = [
        'data' => $row,
        '#attributes' => [
          'data-component-id' => $entity->id(),
          'data-component-bundle' => $entity->bundle(),
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('There are no SODa SCS Components available.'),
      '#attributes' => [
        'class' => ['soda-scs-table-list'],
      ],
      '#attached' => [
        'library' => [
          'soda_scs_manager/componentListHealthStatus',
          'soda_scs_manager/table-list',
        ],
      ],
    ];

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    // Disable caching.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

}
