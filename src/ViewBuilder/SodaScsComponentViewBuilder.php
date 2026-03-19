<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Theme\Registry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The View Builder for the SodaScsComponent entity.
 */
class SodaScsComponentViewBuilder extends EntityViewBuilder {

  /**
   * The config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The SodaScsManager settings.
   *
   * @var \Drupal\soda_scs_manager\SodaScsManagerSettings
   */
  protected $sodaScsManagerSettings;

  /**
   * Constructs a new EntityViewBuilder.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The config.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Theme\Registry $theme_registry
   *   The theme registry.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   */
  public function __construct(
    $config,
    EntityDisplayRepositoryInterface $entity_display_repository,
    EntityRepositoryInterface $entity_repository,
    LanguageManagerInterface $language_manager,
    Registry $theme_registry,
    EntityTypeInterface $entity_type,
  ) {
    $this->entityTypeId = $entity_type->id();
    $this->entityType = $entity_type;
    $this->entityRepository = $entity_repository;
    $this->languageManager = $language_manager;
    $this->themeRegistry = $theme_registry;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->sodaScsManagerSettings = $config->get('soda_scs_manager.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(
    ContainerInterface $container,
    EntityTypeInterface $entity_type,
  ) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_display.repository'),
      $container->get('entity.repository'),
      $container->get('language_manager'),
      $container->get('theme.registry'),
      $entity_type,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $build) {
    $build = parent::build($build);

    // Hide the flavours field if it exists in the build array.
    if (isset($build['flavours'])) {
      $build['flavours']['#access'] = FALSE;
    }

    $build['#attached']['library'][] = 'soda_scs_manager/security';
    $build['#attached']['library'][] = 'soda_scs_manager/entityHelpers';
    $build['#attached']['drupalSettings']['entityInfo']['healthUrl'] = '/soda-scs-manager/health/component/' . $build['#soda_scs_component']->id();

    // Triplestore: add dedicated credentials display (public, password, token).
    $entity = $build['#soda_scs_component'];
    if ($entity->bundle() === 'soda_scs_triplestore_component') {
      $build['triplestoreCredentials'] = $this->buildTriplestoreCredentials($entity);
      $build['triplestoreCredentials']['#weight'] = 55;
      // Hide default service key display to avoid duplication.
      if (isset($build['serviceKey'])) {
        $build['serviceKey']['#access'] = FALSE;
      }
    }

    // Disable caching.
    $build['#cache'] = [
      'max-age' => 0,
    ];

    return $build;
  }

  /**
   * Builds the triplestore credentials display (public read, password, token).
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The triplestore component entity.
   *
   * @return array
   *   Render array for the credentials section.
   */
  protected function buildTriplestoreCredentials(EntityInterface $entity): array {
    $publicRead = $entity->hasField('publicRead') && !$entity->get('publicRead')->isEmpty()
      ? (bool) $entity->get('publicRead')->value
      : FALSE;

    $machineName = $entity->get('machineName')->value ?? '';
    $host = $this->sodaScsManagerSettings->get('triplestore')['generalSettings']['host'] ?? '';
    $host = rtrim((string) $host, '/');
    $readUrl = $host ? $host . '/repositories/' . $machineName : '';
    $writeUrl = $host ? $host . '/repositories/' . $machineName . '/statements' : '';

    $password = '';
    $token = '';
    foreach ($entity->get('serviceKey') as $ref) {
      $serviceKey = $ref->entity;
      if (!$serviceKey) {
        continue;
      }
      $value = $serviceKey->get('servicePassword')->value ?? '';
      $value = is_string($value) ? $value : '';
      if ($serviceKey->get('type')->value === 'password') {
        $password = $value;
      }
      elseif ($serviceKey->get('type')->value === 'token') {
        $token = $value;
      }
    }

    $passwordDisplay = $password ?: (string) $this->t('(not set)');
    $tokenDisplay = $token ?: (string) $this->t('(not set)');

    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Endpoint access'),
      '#weight' => 55,
      'publicRead' => [
        '#type' => 'item',
        '#title' => $this->t('Public read'),
        '#markup' => $publicRead ? $this->t('Yes') : $this->t('No'),
        '#description' => $this->t('When enabled, the SPARQL endpoint is readable without authentication.'),
        '#description_display' => 'after',
      ],
      'readUrl' => [
        '#type' => 'item',
        '#title' => $this->t('Read URL'),
        '#markup' => $readUrl ? '<a href="' . htmlspecialchars($readUrl) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($readUrl) . '</a>' : (string) $this->t('(not configured)'),
        '#allowed_tags' => ['a'],
        '#description' => $this->t('SPARQL read endpoint for SELECT queries.'),
        '#description_display' => 'after',
      ],
      'writeUrl' => [
        '#type' => 'item',
        '#title' => $this->t('Write URL'),
        '#markup' => $writeUrl ? '<a href="' . htmlspecialchars($writeUrl) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($writeUrl) . '</a>' : (string) $this->t('(not configured)'),
        '#allowed_tags' => ['a'],
        '#description' => $this->t('SPARQL write endpoint for INSERT/UPDATE/DELETE.'),
        '#description_display' => 'after',
      ],
      'password' => [
        '#type' => 'item',
        '#title' => $this->t('Password'),
        '#markup' => '<span class="soda-scs-manager--service-password scs-manager--triplestore--password">' . htmlspecialchars($passwordDisplay) . '</span>',
        '#allowed_tags' => ['span'],
        '#description' => $this->t('Use with Basic Auth for authentication. Click to copy.'),
        '#description_display' => 'after',
      ],
      'token' => [
        '#type' => 'item',
        '#title' => $this->t('Token'),
        '#markup' => '<span class="soda-scs-manager--service-password scs-manager--triplestore--token">' . htmlspecialchars($tokenDisplay) . '</span>',
        '#allowed_tags' => ['span'],
        '#description' => $this->t('Use as Bearer token for API authentication. Click to copy.'),
        '#description_display' => 'after',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    $build = parent::getBuildDefaults($entity, $view_mode);

    // Add the bundle to the build array.
    // Add a field with the bundle name.
    $build['bundleName'] = [
      '#type' => 'markup',
      '#markup' => $entity->bundle(),
      '#access' => TRUE,
      '#weight' => 10,
      '#title' => 'Bundle',
    ];

    // Add custom theme suggestions.
    $build['#theme'] = 'soda_scs_entity';
    $build['#soda_scs_component'] = $entity;
    $build['#entity_type'] = 'component';
    $build['#view_mode'] = $view_mode;

    return $build;
  }

}
