<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\SodaScsApiActions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides a confirmation form for some action.
 */
class SodaScsManagerServiceActionConfirmForm extends ConfirmFormBase
{

  /**
   * The API actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsApiActions
   */
  protected SodaScsApiActions $apiActions;

  /**
   * The action to perform.
   *
   * @var string
   */
  protected string $action;

  /**
   * The component ID.
   *
   * @var int
   */
  protected int $sodaScsComponentId;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;
  /**
   * Constructs a new form.
   *
   * @param \Drupal\soda_scs_manager\SodaScsApiActions $api_actions
   *   The API actions service.
   */
  public function __construct(string $action, SodaScsApiActions $api_actions, int $sodaScsComponentId, EntityTypeManagerInterface $entityTypeManager)
  {
    $this->action = $action;
    $this->apiActions = $api_actions;
    $this->sodaScsComponentId = $sodaScsComponentId;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    $routeMatch = $container->get('current_route_match');
    return new static(
      $routeMatch->getParameter('action'),
      $container->get('soda_scs_manager.api.actions'),
      $routeMatch->getParameter('soda_scs_component_id'),
      $container->get('entity_type.manager'),

    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'soda_scs_manager_service_action_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion()
  {
    return $this->t('Are you sure you want to perform this action?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl()
  {
    return new Url('soda_scs_manager.desk');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription()
  {
    return $this->t('This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Load Entity
    $entity = $this->entityTypeManager->getStorage('soda_scs_component')->load($this->sodaScsComponentId);

    // Construct properties.
    $bundle = $entity->bundle();
    $action = $this->action;
    $options = [
      'user' => $entity->get('user')->value,
      'subdomain' => $entity->get('subdomain')->value,
    ];

    // Perform the action.
    $this->apiActions->crudComponent($bundle, $action, $options);

    // Redirect to the component list.
    $form_state->setRedirectUrl($this->getCancelUrl());
  }
}
