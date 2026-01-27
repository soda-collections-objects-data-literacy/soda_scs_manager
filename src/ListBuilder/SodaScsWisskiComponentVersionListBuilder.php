<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ListBuilder;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of WissKI Component Version entities.
 */
class SodaScsWisskiComponentVersionListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['version'] = $this->t('Version');
    $header['wisskiStack'] = $this->t('WissKI Stack');
    $header['wisskiImage'] = $this->t('WissKI Image');
    $header['packageEnvironment'] = $this->t('Package Environment');
    $header['wisskiDefaultDataModelRecipe'] = $this->t('WissKI Default Data Model Recipe');
    $header['wisskiStarterRecipe'] = $this->t('WissKI Starter Recipe');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsWisskiComponentVersionInterface $entity */
    $row['version'] = $entity->getVersion();
    $row['wisskiStack'] = $entity->getWisskiStack();
    $row['wisskiImage'] = $entity->getWisskiImage();
    $row['packageEnvironment'] = $entity->getPackageEnvironment();
    $row['wisskiDefaultDataModelRecipe'] = $entity->getWisskiDefaultDataModelRecipe();
    $row['wisskiStarterRecipe'] = $entity->getWisskiStarterRecipe();
    return $row + parent::buildRow($entity);
  }

}
