<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Traits;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;

/**
 * Makes a project mandatory on application create forms.
 *
 * Every application belongs to exactly one project of the current user. With
 * ?project= the project is fixed; otherwise the user picks one of their
 * projects. Without any project the form is replaced by a hint to create one.
 *
 * Expects the using form to provide $currentUser, $entityTypeManager,
 * getRequest() and t().
 */
trait SodaScsProjectScopedCreateFormTrait {

  /**
   * Form element that lets the user pick one of their projects.
   */
  private const PROJECT_SELECT_ELEMENT = 'scsProject';

  /**
   * Copies the chosen project into the partOfProjects form value.
   *
   * Call before parent::validateForm() so the entity is built with it.
   *
   * @return int|string|null
   *   The project ID, or NULL when none was chosen (an error is set).
   */
  protected function applySelectedProject(FormStateInterface $form_state): int|string|null {
    $projectId = $this->resolveSelectedProjectId($form_state);
    if ($projectId === NULL) {
      $form_state->setErrorByName(self::PROJECT_SELECT_ELEMENT, $this->t('Choose the project this application belongs to.'));
      return NULL;
    }
    $form_state->setValue('partOfProjects', [['target_id' => $projectId]]);
    return $projectId;
  }

  /**
   * Adds the project choice, or replaces the form when there is no project.
   */
  protected function buildProjectScope(array &$form): void {
    if (!isset($form['partOfProjects'])) {
      return;
    }
    $form['partOfProjects']['#access'] = FALSE;

    $requestedProjectId = $this->resolveRequestedProjectId();
    if ($requestedProjectId !== NULL) {
      $form['partOfProjects']['widget']['#default_value'] = [$requestedProjectId];
      return;
    }

    $options = $this->ownedProjectOptions();
    if ($options === []) {
      foreach (Element::children($form) as $key) {
        $form[$key]['#access'] = FALSE;
      }
      $form['projectRequired'] = [
        '#type' => 'container',
        '#weight' => -100,
        '#attributes' => ['class' => ['soda-scs-manager--project-required']],
        'message' => [
          '#markup' => '<p>' . $this->t('Applications always belong to a project. Create a project first, then add the application from the project page.') . '</p>',
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Create a project'),
          '#url' => Url::fromRoute('entity.soda_scs_project.add_form', ['bundle' => 'soda_scs_project']),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ];
      return;
    }

    $form[self::PROJECT_SELECT_ELEMENT] = [
      '#type' => 'select',
      '#title' => $this->t('Project'),
      '#description' => $this->t('The application belongs to this project. Its members get access.'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select a project -'),
      '#default_value' => count($options) === 1 ? array_key_first($options) : NULL,
      '#required' => TRUE,
      '#weight' => -50,
    ];
  }

  /**
   * Projects owned by the current user, keyed by ID and sorted by label.
   *
   * @return array<int|string, string>
   *   Project labels keyed by project ID.
   */
  protected function ownedProjectOptions(): array {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner', $this->currentUser->id())
      ->sort('label')
      ->execute();
    $options = [];
    foreach ($storage->loadMultiple($ids) as $project) {
      $options[$project->id()] = (string) $project->label();
    }
    return $options;
  }

  /**
   * The ?project= value, if the current user owns that project.
   */
  protected function resolveRequestedProjectId(): int|string|null {
    $requestedProjectId = $this->getRequest()->query->get('project');
    if ($requestedProjectId === NULL || $requestedProjectId === '') {
      return NULL;
    }
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface|null $project */
    $project = $this->entityTypeManager
      ->getStorage('soda_scs_project')
      ->load($requestedProjectId);
    if ($project && (int) $project->getOwnerId() === (int) $this->currentUser->id()) {
      return $project->id();
    }
    return NULL;
  }

  /**
   * The project for the new application: ?project= or the user's choice.
   */
  protected function resolveSelectedProjectId(FormStateInterface $form_state): int|string|null {
    $requestedProjectId = $this->resolveRequestedProjectId();
    if ($requestedProjectId !== NULL) {
      return $requestedProjectId;
    }
    $selected = $form_state->getValue(self::PROJECT_SELECT_ELEMENT);
    if ($selected === NULL || $selected === '') {
      return NULL;
    }
    return array_key_exists($selected, $this->ownedProjectOptions()) ? $selected : NULL;
  }

}
