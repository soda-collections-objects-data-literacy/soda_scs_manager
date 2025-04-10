<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for renewing a service key.
 */
class SodaScsServiceKeyRenewConfirmForm extends ConfirmFormBase {

  /**
   * The service key entity.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface
   */
  protected $serviceKey;

  /**
   * The service key storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $serviceKeyStorage;

  /**
   * The service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected $sqlServiceActions;

  /**
   * Constructs a new SodaScsServiceKeyRenewConfirmForm.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $service_key_storage
   *   The service key storage.
   */
  public function __construct(EntityStorageInterface $service_key_storage, SodaScsServiceActionsInterface $sqlServiceActions) {
    $this->serviceKeyStorage = $service_key_storage;
    $this->sqlServiceActions = $sqlServiceActions;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('soda_scs_service_key'),
      $container->get('soda_scs_manager.sql_service.actions'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_service_key_renew_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to renew the service key %name?', ['%name' => $this->serviceKey->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.soda_scs_service_key.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action will generate a new password for this service key. The old password will no longer work. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Renew');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, SodaScsServiceKeyInterface|null $soda_scs_service_key = NULL) {
    $this->serviceKey = $soda_scs_service_key;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $renewUserPasswordResult = $this->sqlServiceActions->renewUserPassword($this->serviceKey);
    if ($renewUserPasswordResult['execStatus'] !== 0) {
      $this->messenger()->addError($this->t('Could not renew the service key %name.', [
        '%name' => $this->serviceKey->label(),
      ]));
      return;
    }

    $this->messenger()->addStatus($this->t('The service key %name has been renewed.', [
      '%name' => $this->serviceKey->label(),
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
