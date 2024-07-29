<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\SodaScsApiActions;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides a form for deleting Soda SCS Component entities.
 */
class SodaScsComponentDeleteForm extends ContentEntityDeleteForm {

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsApiActions
   */
  protected SodaScsApiActions $sodaScsApiActions;

  /**
   * {@inheritdoc}
   */
  public function form_id() {
    return 'soda_scs_manager_component_delete_form';
  }


  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete component: @label?', ['@label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Add custom logic before deletion.
    \Drupal::logger('soda_scs_manager')->notice('Deleting component: @label', ['@label' => $this->entity->label()]);

    // Load Entity
    #$entity = $this->entityTypeManager->getStorage('soda_scs_component')->load($this->componentId);

    // Construct properties.
    #$bundle = $entity->bundle();
    #$options = [
    #  'user' => $entity->get('user')->value,
    #  'subdomain' => $entity->get('subdomain')->value,
    #];
    #$this->sodaScsApiActions->crudComponent($bundle,'delete', $options);
    // Call the parent submit handler to delete the entity.
    parent::submitForm($form, $form_state);

    // Add custom logic after deletion.
    \Drupal::messenger()->addMessage($this->t('Component @label has been deleted.', ['@label' => $this->entity->label()]));
  }

}
