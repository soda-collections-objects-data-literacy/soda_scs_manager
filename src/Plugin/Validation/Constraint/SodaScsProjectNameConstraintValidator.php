<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectNameHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SodaScsProjectName constraint.
 */
class SodaScsProjectNameConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    protected SodaScsProjectNameHelpers $projectNameHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.project_name.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof SodaScsProjectInterface) {
      return;
    }
    $label = trim((string) $value->label());
    // An empty label is reported by the required constraint of the field.
    if ($label === '') {
      return;
    }

    $check = $this->projectNameHelpers->checkLabel($label, $value->isNew() ? NULL : $value->id());
    if ($check['error'] === NULL) {
      return;
    }

    $message = $this->projectNameHelpers->errorMessage($check['error']);
    $this->context->buildViolation($message->getUntranslatedString(), $message->getArguments())
      ->atPath('label')
      ->addViolation();
  }

}
