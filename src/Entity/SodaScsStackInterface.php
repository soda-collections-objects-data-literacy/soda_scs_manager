<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;

/**
 * Interface for the SodaScsStack entity.
 */
interface SodaScsStackInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Get the label.
   *
   * @return string
   *   The label.
   */
  public function getLabel();

  /**
   * Set the label.
   *
   * @param string $label
   *   The label.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface
   *   The called object.
   */
  public function setLabel($label);

  /**
   * Get the value of a field.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   Stack.
   * @param string $fieldName
   *   Field name.
   *
   * @return array
   *   The value of the field.
   */
  public function getValue(SodaScsStackInterface $stack, string $fieldName);

  /**
   * Set the value of a field.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   Stack.
   * @param string $fieldName
   *   Field name.
   * @param string $value
   *   Value to be put in $stack->field[$index]->value.
   * @param ?int $index
   *   The delta i.e. $stack->field[$index].
   * @param string $defaultValue
   *   The default values that will be written into the previous indexes.
   * @param bool $overwriteOldValues
   *   TRUE to ignore previous index values and
   *   overwrite them with $default_value.
   */
  public static function setValue(SodaScsStackInterface $stack, string $fieldName, string $value, ?int $index = NULL, string $defaultValue = "", bool $overwriteOldValues = FALSE);

}
