<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Form;

use Drupal\soda_scs_manager\Form\SodaScsProjectDeleteForm;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The project delete form with the Batch API replaced by a synchronous run.
 *
 * Runs the batch operations the form hands over like the Batch API does: an
 * operation is called again while it sets 'finished' below 1, the results
 * persist across the operations, and the finish callback runs at the end. An
 * exception of an operation ends the batch and runs the finish callback with
 * a failed state, as the Batch API does after a request that ended in an
 * error. The project page redirect needs no URL generator here unless a test
 * asks for the form's own.
 */
final class RecordingProjectDeleteForm extends SodaScsProjectDeleteForm {

  /**
   * The batch definition the form handed over; NULL when none.
   *
   * @var array<string, mixed>|null
   */
  public ?array $batch = NULL;

  /**
   * What the finish callback returned.
   */
  public ?RedirectResponse $finishResult = NULL;

  /**
   * Whether the finish callback ran.
   */
  public bool $hasFinished = FALSE;

  /**
   * How often the redirect to the project page was built.
   */
  public int $projectPageRedirectCount = 0;

  /**
   * Whether projectPageRedirect() runs the form's own code.
   *
   * FALSE returns a fixed redirect without a URL generator.
   */
  public bool $usesRealProjectPageRedirect = FALSE;

  /**
   * Whether setBatch() runs the operations; FALSE only records the batch.
   */
  public bool $runsBatch = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function projectPageRedirect(): ?RedirectResponse {
    $this->projectPageRedirectCount++;
    if ($this->usesRealProjectPageRedirect) {
      return parent::projectPageRedirect();
    }
    return new RedirectResponse('/project-page');
  }

  /**
   * {@inheritdoc}
   */
  protected function setBatch(array $batch): void {
    $this->batch = $batch;
    if (!$this->runsBatch) {
      return;
    }
    $results = [];
    $isSuccess = TRUE;
    try {
      foreach ($batch['operations'] as [$callback, $arguments]) {
        do {
          $context = ['finished' => 1, 'results' => &$results, 'sandbox' => $context['sandbox'] ?? []];
          $callbackArguments = $arguments;
          $callbackArguments[] = &$context;
          call_user_func_array($callback, $callbackArguments);
        } while ($context['finished'] < 1);
        unset($context);
      }
    }
    catch (\Throwable) {
      $isSuccess = FALSE;
    }
    $this->hasFinished = TRUE;
    $this->finishResult = call_user_func($batch['finished'], $isSuccess, $results, []);
  }

}
