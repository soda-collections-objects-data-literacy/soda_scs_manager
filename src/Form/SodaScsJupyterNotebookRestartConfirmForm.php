<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm stopping the current user's Jupyter notebook so Hub can respawn.
 *
 * Docker bind mounts for project Team Folders are applied only when the
 * notebook container is spawned. Stopping here does not remount folders;
 * the user must Start again from JupyterHub.
 */
class SodaScsJupyterNotebookRestartConfirmForm extends ConfirmFormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected SodaScsJupyterHelpers $jupyterHelpers,
    protected SodaScsServiceHelpers $serviceHelpers,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.jupyter.helpers'),
      $container->get('soda_scs_manager.service.helpers'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_jupyter_notebook_restart_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Stop your Jupyter notebook server so project folders can remount?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('<p>JupyterLab only mounts the project Team Folders you belong to when your notebook server <strong>starts</strong>. After you create or join a project, you must stop the running server and start it again from JupyterHub.</p><p><strong>Warnings:</strong></p><ul><li>Any <strong>unsaved notebook work</strong>, open kernels, and terminal sessions will be lost.</li><li>This action <strong>stops</strong> your notebook server; it does not start it again.</li><li>Files already saved in your Jupyter home volume and in Nextcloud Team Folders are kept.</li><li>You must open JupyterHub afterwards and choose <strong>Start My Server</strong> so the new project folders appear under <code>/home/jovyan/nextcloud/</code>.</li></ul>');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Stop notebook server');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('soda_scs_manager.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\user\UserInterface|null $account */
    $account = $this->entityTypeManager
      ->getStorage('user')
      ->load($this->currentUser->id());
    if (!$account instanceof UserInterface) {
      $this->messenger()->addError($this->t('Unable to load your user account. Please try again.'));
      $form_state->setRedirect('soda_scs_manager.dashboard');
      return;
    }

    $result = $this->jupyterHelpers->stopNotebookContainerForUser($account);
    $hubHome = $this->jupyterHubHomeUrl();

    if (!$result['success']) {
      $this->messenger()->addError($this->t('Could not stop your Jupyter notebook server: @error', [
        '@error' => $result['message'],
      ]));
      $form_state->setRedirect('soda_scs_manager.dashboard');
      return;
    }

    if ($result['stopped']) {
      $this->messenger()->addWarning($this->t('Your Jupyter notebook server was stopped. Unsaved notebook work is lost.'));
      if ($hubHome !== '') {
        $this->messenger()->addStatus($this->t('Open <a href=":url">JupyterHub</a> and choose <strong>Start My Server</strong> so your project Team Folders are mounted again.', [
          ':url' => $hubHome,
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('Open JupyterHub and choose <strong>Start My Server</strong> so your project Team Folders are mounted again.'));
      }
    }
    else {
      if ($hubHome !== '') {
        $this->messenger()->addStatus($this->t('No running Jupyter notebook server was found. When you next <a href=":url">start JupyterHub</a>, your current project Team Folders will be mounted.', [
          ':url' => $hubHome,
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('No running Jupyter notebook server was found. When you next start JupyterHub, your current project Team Folders will be mounted.'));
      }
    }

    $form_state->setRedirect('soda_scs_manager.dashboard');
  }

  /**
   * Returns the JupyterHub home URL, or an empty string if not configured.
   */
  private function jupyterHubHomeUrl(): string {
    try {
      $settings = $this->serviceHelpers->initJupyterHubSettings();
    }
    catch (\Throwable) {
      return '';
    }
    $base = rtrim((string) ($settings['baseUrl'] ?? ''), '/');
    return $base !== '' ? $base . '/hub/home' : '';
  }

}
