<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Exception\SodaScsHelpersException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Helper class for CRUD operations on progress tracking tables.
 */
class SodaScsProgressHelper {

  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * SodaScsProgressHelper constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    #[Autowire(service: 'database')]
    Connection $database,
    #[Autowire(service: 'datetime.time')]
    TimeInterface $time,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    LoggerChannelInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->database = $database;
    $this->time = $time;
    $this->logger = $logger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create a new operation.
   *
   * @param string $operationType
   *   The operation type (e.g., 'drupal_packages_update').
   * @param string $status
   *   The initial status (default: 'started').
   *
   * @return string|null
   *   The operation UUID if created successfully, NULL otherwise.
   */
  public function createOperation(string $operationType, string $status = 'started'): ?string {
    try {
      $operationUuid = $this->generateUuid();
      $currentTime = $this->time->getCurrentTime();
      $this->database->insert('soda_scs_operations')
        ->fields([
          'uuid' => $operationUuid,
          'operation_type' => $operationType,
          'status' => $status,
          'created' => $currentTime,
          'updated' => $currentTime,
        ])
        ->execute();

      return $operationUuid;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create operation: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Read an operation by ID.
   *
   * @param string $operationId
   *   The operation ID.
   *
   * @return array|null
   *   The operation data as an associative array, or NULL if not found.
   */
  public function readOperation(string $operationId): ?array {
    try {
      $result = $this->database->select('soda_scs_operations', 'o')
        ->fields('o')
        ->condition('uuid', $operationId)
        ->execute()
        ->fetchAssoc();

      return $result ?: NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to read operation @id: @message', [
        '@id' => $operationId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Update an operation.
   *
   * @param string $operationId
   *   The operation ID.
   * @param array $fields
   *   An associative array of fields to update. Keys: 'operation_type',
   *   'status'. The 'updated' timestamp is automatically set.
   *
   * @return bool
   *   TRUE if the operation was updated successfully, FALSE otherwise.
   */
  public function updateOperation(string $operationId, array $fields): bool {
    try {
      $fields['updated'] = $this->time->getCurrentTime();

      $query = $this->database->update('soda_scs_operations')
        ->condition('uuid', $operationId);

      foreach ($fields as $field => $value) {
        $query->fields([$field => $value]);
      }

      $affected = $query->execute();

      return $affected > 0;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update operation @id: @message', [
        '@id' => $operationId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Delete an operation.
   *
   * @param string $operationId
   *   The operation ID.
   *
   * @return bool
   *   TRUE if the operation was deleted successfully, FALSE otherwise.
   */
  public function deleteOperation(string $operationId): bool {
    try {
      // Delete associated steps first.
      $this->database->delete('soda_scs_steps')
        ->condition('operation_uuid', $operationId)
        ->execute();

      // Delete the operation.
      $affected = $this->database->delete('soda_scs_operations')
        ->condition('uuid', $operationId)
        ->execute();

      return $affected > 0;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete operation @id: @message', [
        '@id' => $operationId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Find operations by criteria.
   *
   * @param array $conditions
   *   An associative array of conditions (field => value).
   * @param int|null $limit
   *   Optional limit on the number of results.
   * @param int|null $offset
   *   Optional offset for pagination.
   * @param string|null $orderBy
   *   Optional field to order by (default: 'created').
   * @param string $direction
   *   Order direction: 'ASC' or 'DESC' (default: 'DESC').
   *
   * @return array
   *   An array of operation arrays.
   */
  public function findOperations(
    array $conditions = [],
    ?int $limit = NULL,
    ?int $offset = NULL,
    ?string $orderBy = NULL,
    string $direction = 'DESC',
  ): array {
    try {
      $query = $this->database->select('soda_scs_operations', 'o')
        ->fields('o');

      foreach ($conditions as $field => $value) {
        $query->condition($field, $value);
      }

      if ($orderBy !== NULL) {
        $query->orderBy($orderBy, $direction);
      }
      else {
        $query->orderBy('created', $direction);
      }

      if ($limit !== NULL) {
        $query->range($offset ?? 0, $limit);
      }

      return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to find operations: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Create a new step.
   *
   * @param string $operationId
   *   The operation ID this step belongs to.
   * @param string|null $message
   *   Optional message for the step.
   *
   * @return string
   *   The step UUID.
   */
  public function createStep(
    string $operationId,
    ?string $message = NULL,
  ): string {
    try {
      $stepId = $this->generateUuid();
      $currentTime = $this->time->getCurrentTime();
      $fields = [
        'uuid' => $stepId,
        'operation_uuid' => $operationId,
        'created' => $currentTime,
      ];

      if ($message !== NULL) {
        $fields['message'] = $message;
      }

      $this->database->insert('soda_scs_steps')
        ->fields($fields)
        ->execute();

      return $stepId;
    }
    catch (\Exception $e) {
      throw SodaScsHelpersException::progressFailed(
        message: 'Failed to create step: ' . $e->getMessage(),
        operation: 'create_step',
        context: ['operation_id' => $operationId, 'message' => $message],
      );
    }
  }

  /**
   * Find steps by operation ID.
   *
   * @param string $operationId
   *   The operation ID.
   * @param array $additionalConditions
   *   Additional conditions (field => value).
   * @param string|null $orderBy
   *   Optional field to order by (default: 'created').
   * @param string $direction
   *   Order direction: 'ASC' or 'DESC' (default: 'ASC').
   *
   * @return array
   *   An array of step arrays.
   */
  public function findLatestStepsByOperation(
    string $operationId,
    array $additionalConditions = [],
    ?string $orderBy = 'created',
    string $direction = 'DESC',
  ): array {
    try {
      $query = $this->database->select('soda_scs_steps', 's')
        ->fields('s')
        ->condition('operation_uuid', $operationId);

      foreach ($additionalConditions as $field => $value) {
        $query->condition($field, $value);
      }

      $query->orderBy($orderBy, $direction);

      return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to find steps for operation @id: @message', [
        '@id' => $operationId,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Delete all steps for an operation.
   *
   * @param string $operationId
   *   The operation ID.
   *
   * @return bool
   *   TRUE if the steps were deleted successfully, FALSE otherwise.
   */
  public function deleteStepsByOperation(string $operationId): bool {
    try {
      $this->database->delete('soda_scs_steps')
        ->condition('operation_uuid', $operationId)
        ->execute();

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete steps for operation @id: @message', [
        '@id' => $operationId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Generate a new operation ID.
   *
   * @return string
   *   A 36-character UUID (UUID v4 with hyphens).
   */
  private function generateUuid(): string {
    return (new Php())->generate();
  }

}
