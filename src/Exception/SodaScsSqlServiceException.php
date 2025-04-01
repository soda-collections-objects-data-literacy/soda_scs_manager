<?php

namespace Drupal\soda_scs_manager\Exception;

/**
 * Custom exception for database-related errors in the Soda SCS Manager module.
 */
class SodaScsSqlServiceException extends \Exception {
  /**
   * The command that was executed.
   *
   * @var string
   */
  protected $command;

  /**
   * The action being performed.
   *
   * @var string
   */
  protected $action;

  /**
   * The name of the entity involved in the action.
   *
   * @var string
   */
  protected $entityName;

  /**
   * The output from the command execution.
   *
   * @var string|array
   */
  protected $output;

  public function __construct($message, $command, $action, $entityName, $output, $code = 0, \Exception|null $previous = NULL) {
    $this->command = $command;
    $this->action = $action;
    $this->entityName = $entityName;
    $this->output = $output;

    // Build a more detailed message that includes all relevant information.
    $detailedMessage = $message . "\n";
    $detailedMessage .= "Action: " . $action . "\n";
    $detailedMessage .= "Entity Name: " . $entityName . "\n";
    $detailedMessage .= "Output: " . (is_array($output) ? json_encode($output) : $output);

    parent::__construct($detailedMessage, $code, $previous);
  }

  /**
   * Get the command that was executed.
   *
   * @return string
   *   The command that was executed.
   */
  public function getCommand(): string {
    return $this->command;
  }

  /**
   * Get the action being performed.
   *
   * @return string
   *   The action being performed.
   */
  public function getAction(): string {
    return $this->action;
  }

  /**
   * Get the name of the entity involved in the action.
   *
   * @return string
   *   The name of the entity involved in the action.
   */
  public function getEntityName(): string {
    return $this->entityName;
  }

  /**
   * Get the output from the command execution.
   *
   * @return string|array
   *   The output from the command execution.
   */
  public function getOutput(): string|array {
    return $this->output;
  }

}
