<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Progress;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service for tracking progress of long-running operations.
 *
 * This service stores progress information in the database and provides
 * methods to update and retrieve progress status for multi-step operations.
 */
class SodaScsProgressTracker {

  use StringTranslationTrait;

  /**
   * The database table name.
   */
  protected const TABLE_NAME = 'soda_scs_progress';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Constructs a new SodaScsProgressTracker.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    #[Autowire(service: 'database')]
    Connection $database,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    LoggerChannelInterface $logger,
    #[Autowire(service: 'string_translation')]
    TranslationInterface $stringTranslation,
    #[Autowire(service: 'datetime.time')]
    TimeInterface $time,
  ) {
    $this->database = $database;
    $this->logger = $logger;
    $this->stringTranslation = $stringTranslation;
    $this->time = $time;
  }

  /**
   * Generate a unique operation ID.
   *
   * @return string
   *   A unique operation ID.
   */
  public function generateOperationId(): string {
    return bin2hex(random_bytes(16));
  }

  /**
   * Initialize a pending operation (before it actually starts).
   *
   * This is useful for operations that need to exist before form submission
   * so that progress polling can begin immediately.
   *
   * @param string $operationId
   *   The unique operation ID.
   * @param string $operationType
   *   The type of operation (e.g., 'drupal_packages_update').
   * @param array $metadata
   *   Optional metadata to store with the operation.
   */
  public function initializePendingOperation(
    string $operationId,
    string $operationType,
    array $metadata = [],
  ): void {
    $currentTime = $this->time->getCurrentTime();
    $progressData = [
      'operationId'       => $operationId,
      'operationType'     => $operationType,
      'status'            => 'pending',
      'currentStep'       => NULL,
      'currentStepIndex'  => -1,
      'steps'             => [],
      'completedSteps'    => [],
      'logs'              => [],
      'metadata'          => $metadata,
      'startedAt'         => NULL,
      'updatedAt'         => $currentTime,
      'completedAt'       => NULL,
      'completionMessage' => NULL,
      'error'             => NULL,
    ];

    $this->insertProgressData($operationId, $operationType, 'pending', $progressData, $currentTime);
  }

  /**
   * Start tracking a new operation.
   *
   * @param string $operationId
   *   The unique operation ID.
   * @param string $operationType
   *   The type of operation (e.g., 'drupal_packages_update').
   * @param array $steps
   *   Array of step definitions with 'id', 'label', and optionally 'weight'.
   * @param array $metadata
   *   Optional metadata to store with the operation.
   */
  public function startOperation(
    string $operationId,
    string $operationType,
    array $steps,
    array $metadata = [],
  ): void {
    // Check if operation already exists (e.g., initialized as pending).
    $existingData = $this->getProgressData($operationId);
    $currentTime = $this->time->getCurrentTime();

    $normalizedSteps = $this->normalizeSteps($steps);
    $progressData = [
      'operationId'       => $operationId,
      'operationType'     => $operationType,
      'status'            => 'running',
      'currentStep'       => NULL,
      'currentStepIndex'  => -1,
      'steps'             => $normalizedSteps,
      'completedSteps'    => [],
      'logs'              => $existingData['logs'] ?? [],
      'metadata'          => array_merge($existingData['metadata'] ?? [], $metadata),
      'startedAt'         => $currentTime,
      'updatedAt'         => $currentTime,
      'completedAt'       => NULL,
      'completionMessage' => NULL,
      'error'             => NULL,
    ];

    if ($existingData !== NULL) {
      $this->updateProgressData($operationId, 'running', $progressData, $currentTime);
    }
    else {
      $this->insertProgressData($operationId, $operationType, 'running', $progressData, $currentTime);
    }
  }

  /**
   * Update the current step of an operation.
   *
   * @param string $operationId
   *   The operation ID.
   * @param string $stepId
   *   The ID of the step that is now starting.
   * @param string|null $message
   *   Optional message to display for this step.
   */
  public function setCurrentStep(
    string $operationId,
    string $stepId,
    ?string $message = NULL,
  ): void {
    $progressData = $this->getProgressData($operationId);
    if ($progressData === NULL) {
      return;
    }

    $stepIndex = $this->findStepIndex($progressData['steps'], $stepId);
    if ($stepIndex === -1) {
      return;
    }

    $currentTime = $this->time->getCurrentTime();
    $progressData['currentStep'] = $stepId;
    $progressData['currentStepIndex'] = $stepIndex;
    $progressData['steps'][$stepIndex]['status'] = 'running';
    $progressData['steps'][$stepIndex]['startedAt'] = $currentTime;
    if ($message !== NULL) {
      $progressData['steps'][$stepIndex]['message'] = $message;
    }
    $progressData['updatedAt'] = $currentTime;

    $this->updateProgressData($operationId, $progressData['status'], $progressData, $currentTime);
  }

  /**
   * Mark a step as completed.
   *
   * @param string $operationId
   *   The operation ID.
   * @param string $stepId
   *   The ID of the completed step.
   * @param string|null $message
   *   Optional completion message.
   */
  public function completeStep(
    string $operationId,
    string $stepId,
    ?string $message = NULL,
  ): void {
    $progressData = $this->getProgressData($operationId);
    if ($progressData === NULL) {
      return;
    }

    $stepIndex = $this->findStepIndex($progressData['steps'], $stepId);
    if ($stepIndex === -1) {
      return;
    }

    $currentTime = $this->time->getCurrentTime();
    $progressData['steps'][$stepIndex]['status'] = 'completed';
    $progressData['steps'][$stepIndex]['completedAt'] = $currentTime;
    if ($message !== NULL) {
      $progressData['steps'][$stepIndex]['message'] = $message;
    }
    $progressData['completedSteps'][] = $stepId;
    $progressData['updatedAt'] = $currentTime;

    $this->updateProgressData($operationId, $progressData['status'], $progressData, $currentTime);
  }

  /**
   * Mark a step as skipped.
   *
   * @param string $operationId
   *   The operation ID.
   * @param string $stepId
   *   The ID of the skipped step.
   * @param string|null $message
   *   Optional message explaining why the step was skipped.
   */
  public function skipStep(
    string $operationId,
    string $stepId,
    ?string $message = NULL,
  ): void {
    $progressData = $this->getProgressData($operationId);
    if ($progressData === NULL) {
      return;
    }

    $stepIndex = $this->findStepIndex($progressData['steps'], $stepId);
    if ($stepIndex === -1) {
      return;
    }

    $currentTime = $this->time->getCurrentTime();
    $progressData['steps'][$stepIndex]['status'] = 'skipped';
    $progressData['steps'][$stepIndex]['skippedAt'] = $currentTime;
    if ($message !== NULL) {
      $progressData['steps'][$stepIndex]['message'] = $message;
    }
    $progressData['updatedAt'] = $currentTime;

    $this->updateProgressData($operationId, $progressData['status'], $progressData, $currentTime);
  }

  /**
   * Add a log entry to the operation.
   *
   * @param string $operationId
   *   The operation ID.
   * @param string $message
   *   The log message.
   * @param string $level
   *   The log level (info, warning, error).
   */
  public function addLog(
    string $operationId,
    string $message,
    string $level = 'info',
  ): void {
    $progressData = $this->getProgressData($operationId);
    if ($progressData === NULL) {
      return;
    }

    $currentTime = $this->time->getCurrentTime();
    $progressData['logs'][] = [
      'level'     => $level,
      'message'   => $message,
      'timestamp' => $currentTime,
    ];
    $progressData['updatedAt'] = $currentTime;

    $this->updateProgressData($operationId, $progressData['status'], $progressData, $currentTime);
  }

  /**
   * Mark an operation as completed successfully.
   *
   * @param string $operationId
   *   The operation ID.
   * @param string|null $message
   *   Optional completion message.
   */
  public function completeOperation(
    string $operationId,
    ?string $message = NULL,
  ): void {
    $progressData = $this->getProgressData($operationId);
    if ($progressData === NULL) {
      return;
    }

    $currentTime = $this->time->getCurrentTime();
    $progressData['status'] = 'completed';
    $progressData['completedAt'] = $currentTime;
    $progressData['updatedAt'] = $currentTime;
    if ($message !== NULL) {
      $progressData['completionMessage'] = $message;
    }

    $this->updateProgressData($operationId, 'completed', $progressData, $currentTime);
  }

  /**
   * Mark an operation as failed.
   *
   * @param string $operationId
   *   The operation ID.
   * @param string $error
   *   The error message.
   * @param string|null $stepId
   *   Optional ID of the step that failed.
   */
  public function failOperation(
    string $operationId,
    string $error,
    ?string $stepId = NULL,
  ): void {
    $progressData = $this->getProgressData($operationId);
    if ($progressData === NULL) {
      return;
    }

    $currentTime = $this->time->getCurrentTime();
    $progressData['status'] = 'failed';
    $progressData['error'] = $error;
    $progressData['failedAt'] = $currentTime;
    $progressData['updatedAt'] = $currentTime;

    if ($stepId !== NULL) {
      $stepIndex = $this->findStepIndex($progressData['steps'], $stepId);
      if ($stepIndex !== -1) {
        $progressData['steps'][$stepIndex]['status'] = 'failed';
        $progressData['steps'][$stepIndex]['error'] = $error;
        $progressData['failedStep'] = $stepId;
      }
    }

    $this->updateProgressData($operationId, 'failed', $progressData, $currentTime);
  }

  /**
   * Get the current progress of an operation.
   *
   * @param string $operationId
   *   The operation ID.
   *
   * @return array|null
   *   The progress data or NULL if not found.
   */
  public function getProgress(string $operationId): ?array {
    $progressData = $this->getProgressData($operationId);
    if ($progressData === NULL) {
      return NULL;
    }

    // Calculate progress percentage.
    $totalSteps = count($progressData['steps']);
    $completedSteps = count(array_filter(
      $progressData['steps'],
      fn($step) => in_array($step['status'], ['completed', 'skipped'], TRUE)
    ));

    return [
      'operationId'       => $progressData['operationId'],
      'operationType'     => $progressData['operationType'],
      'status'            => $progressData['status'],
      'currentStep'       => $progressData['currentStep'],
      'currentStepLabel'  => $this->getCurrentStepLabel($progressData),
      'currentStepIndex'  => $progressData['currentStepIndex'],
      'totalSteps'        => $totalSteps,
      'completedSteps'    => $completedSteps,
      'percentage'        => $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0,
      'steps'             => $progressData['steps'],
      'logs'              => $progressData['logs'],
      'error'             => $progressData['error'],
      'startedAt'         => $progressData['startedAt'],
      'updatedAt'         => $progressData['updatedAt'],
      'completedAt'       => $progressData['completedAt'] ?? NULL,
      'completionMessage' => $progressData['completionMessage'] ?? NULL,
    ];
  }

  /**
   * Delete progress data for an operation.
   *
   * @param string $operationId
   *   The operation ID.
   */
  public function deleteProgress(string $operationId): void {
    $this->database->delete(self::TABLE_NAME)
      ->condition('operation_id', $operationId)
      ->execute();
  }

  /**
   * Define the standard steps for Drupal package update operation.
   *
   * @param string $targetVersion
   *   The target version (nightly or specific version).
   *
   * @return array
   *   Array of step definitions.
   */
  public function getDrupalPackageUpdateSteps(string $targetVersion): array {
    $isNightly = $targetVersion === 'nightly';

    $steps = [
      [
        'id'     => 'ensure_healthy',
        'label'  => (string) $this->t('Checking Drupal health'),
        'weight' => 0,
      ],
      [
        'id'     => 'secure_packages_database',
        'label'  => (string) $this->t('Backing up packages and database'),
        'weight' => 1,
      ],
    ];

    if ($isNightly) {
      $steps[] = [
        'id'     => 'simple_composer_update',
        'label'  => (string) $this->t('Running composer update'),
        'weight' => 2,
      ];
    }
    else {
      $steps[] = [
        'id'     => 'versioned_composer_update',
        'label'  => (string) $this->t('Installing versioned packages'),
        'weight' => 2,
      ];
    }

    $steps[] = [
      'id'     => 'update_database',
      'label'  => (string) $this->t('Updating database'),
      'weight' => 3,
    ];

    $steps[] = [
      'id'     => 'post_update_steps',
      'label'  => (string) $this->t('Finishing update'),
      'weight' => 4,
    ];

    return $steps;
  }

  /**
   * Get progress data from the database.
   *
   * @param string $operationId
   *   The operation ID.
   *
   * @return array|null
   *   The progress data or NULL if not found.
   */
  protected function getProgressData(string $operationId): ?array {
    try {
      $result = $this->database->select(self::TABLE_NAME, 'p')
        ->fields('p', ['progress_data'])
        ->condition('operation_id', $operationId)
        ->execute()
        ->fetchField();

      if ($result) {
        $data = json_decode($result, TRUE);
        if (is_array($data)) {
          return $data;
        }
      }
    }
    catch (\Exception $e) {
      // Log error but don't throw - return NULL instead.
      $this->logger->error('Failed to get progress data: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Insert new progress data into the database.
   *
   * @param string $operationId
   *   The operation ID.
   * @param string $operationType
   *   The operation type.
   * @param string $status
   *   The operation status.
   * @param array $progressData
   *   The progress data.
   * @param int $timestamp
   *   The current timestamp.
   */
  protected function insertProgressData(
    string $operationId,
    string $operationType,
    string $status,
    array $progressData,
    int $timestamp,
  ): void {
    try {
      $this->database->insert(self::TABLE_NAME)
        ->fields([
          'operation_id'   => $operationId,
          'operation_type' => $operationType,
          'status'         => $status,
          'progress_data'  => json_encode($progressData),
          'created'        => $timestamp,
          'updated'        => $timestamp,
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to insert progress data: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Update existing progress data in the database.
   *
   * @param string $operationId
   *   The operation ID.
   * @param string $status
   *   The operation status.
   * @param array $progressData
   *   The progress data.
   * @param int $timestamp
   *   The current timestamp.
   */
  protected function updateProgressData(
    string $operationId,
    string $status,
    array $progressData,
    int $timestamp,
  ): void {
    try {
      $this->database->update(self::TABLE_NAME)
        ->fields([
          'status'        => $status,
          'progress_data' => json_encode($progressData),
          'updated'       => $timestamp,
        ])
        ->condition('operation_id', $operationId)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update progress data: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Normalize step definitions.
   *
   * @param array $steps
   *   Raw step definitions.
   *
   * @return array
   *   Normalized step definitions.
   */
  protected function normalizeSteps(array $steps): array {
    $normalized = [];
    foreach ($steps as $index => $step) {
      $normalized[] = [
        'id'      => $step['id'],
        'label'   => $step['label'],
        'weight'  => $step['weight'] ?? $index,
        'status'  => 'pending',
        'message' => NULL,
        'error'   => NULL,
      ];
    }

    // Sort by weight.
    usort($normalized, fn($a, $b) => $a['weight'] <=> $b['weight']);

    return $normalized;
  }

  /**
   * Find the index of a step by its ID.
   *
   * @param array $steps
   *   The steps array.
   * @param string $stepId
   *   The step ID to find.
   *
   * @return int
   *   The index of the step, or -1 if not found.
   */
  protected function findStepIndex(array $steps, string $stepId): int {
    foreach ($steps as $index => $step) {
      if ($step['id'] === $stepId) {
        return $index;
      }
    }
    return -1;
  }

  /**
   * Get the label of the current step.
   *
   * @param array $progressData
   *   The progress data.
   *
   * @return string|null
   *   The current step label or NULL.
   */
  protected function getCurrentStepLabel(array $progressData): ?string {
    if ($progressData['currentStepIndex'] < 0) {
      return NULL;
    }

    return $progressData['steps'][$progressData['currentStepIndex']]['label'] ?? NULL;
  }

}
