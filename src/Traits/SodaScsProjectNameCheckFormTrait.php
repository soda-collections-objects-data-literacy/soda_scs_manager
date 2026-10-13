<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Traits;

use Drupal\Core\Url;

/**
 * Adds the live unique-name check to the label of project forms.
 *
 * The server-side rule is the SodaScsProjectName entity constraint; this only
 * warns while typing.
 */
trait SodaScsProjectNameCheckFormTrait {

  /**
   * Attaches the name check to the project label widget.
   *
   * @param array $form
   *   The project form.
   * @param int|string|null $projectId
   *   The project being edited, so its own name counts as available.
   */
  protected function attachProjectNameCheck(array &$form, int|string|null $projectId = NULL): void {
    if (!isset($form['label']['widget'][0]['value'])) {
      return;
    }
    $element = &$form['label']['widget'][0]['value'];
    $element['#description'] = $this->t('Project names are unique across the platform. The name is also used for the project folder in Nextcloud.');
    $element['#attributes']['data-scs-project-name-check-url'] = Url::fromRoute('soda_scs_manager.project_check_name')->toString();
    if ($projectId !== NULL && $projectId !== '') {
      $element['#attributes']['data-scs-project-id'] = (string) $projectId;
    }
    $form['#attached']['library'][] = 'soda_scs_manager/projectNameCheck';
  }

}
