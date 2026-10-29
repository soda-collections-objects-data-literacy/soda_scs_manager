<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms deleting a demo template version and its files.
 */
final class SodaScsDemoTemplateDeleteForm extends ConfirmFormBase {

  /**
   * The template to delete.
   */
  protected ?SodaScsDemoTemplateInterface $template = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    protected SodaScsDemoManager $demoManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('soda_scs_manager.demo.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsDemoTemplateInterface $soda_scs_demo_template = NULL): array {
    $this->template = $soda_scs_demo_template;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('soda_scs_manager.demo_project_admin');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The files of this version are deleted from the server. This cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_demo_template_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete demo version v@version?', ['@version' => $this->template?->getVersion()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->demoManager->deleteTemplate($this->template);
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
