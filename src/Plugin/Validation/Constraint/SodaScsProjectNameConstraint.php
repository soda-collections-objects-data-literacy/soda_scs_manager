<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Keeps SCS project labels globally unique (compared by machine name).
 */
#[Constraint(
  id: 'SodaScsProjectName',
  label: new TranslatableMarkup('Unique SCS project name', [], ['context' => 'Validation']),
  type: ['entity:soda_scs_project'],
)]
class SodaScsProjectNameConstraint extends SymfonyConstraint {}
