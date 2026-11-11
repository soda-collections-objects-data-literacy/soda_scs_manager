<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Form;

use Drupal\Core\Form\FormState;
use Drupal\soda_scs_manager\Form\SodaScsStackCreateForm;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm
 *
 * @group soda_scs_manager
 */
class SodaScsStackCreateFormMachineNameRequiredTest extends SodaScsKernelTestBase {

  /**
   * Form API #required must be off so ensureMachineNameFromLabel can run.
   *
   * FormValidator checks element #required before form #validate; with the
   * field still required the wizard POST fails under JS aggregation before
   * the server derive in validateForm().
   *
   * @covers ::buildForm
   */
  public function testMachineNameWidgetIsNotFormApiRequired(): void {
    $role = Role::load('authenticated');
    $role->grantPermission('soda scs create stack entities');
    $role->grantPermission('access content');
    $role->save();
    $this->container->get('current_user')->setAccount($this->testUser);

    $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->create([
      'bundle' => 'soda_scs_wisski_stack',
      'label' => 'Kernel required check',
    ]);
    /** @var \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm $form_object */
    $form_object = $this->entityTypeManager->getFormObject('soda_scs_stack', 'add');
    $this->assertInstanceOf(SodaScsStackCreateForm::class, $form_object);
    $form_object->setEntity($stack);
    $form_object->setModuleHandler($this->container->get('module_handler'));
    $form_object->setEntityTypeManager($this->entityTypeManager);

    $form_state = new FormState();
    $form = $form_object->buildForm([], $form_state, 'soda_scs_wisski_stack');

    $this->assertArrayHasKey('machineName', $form);
    $this->assertFalse($form['machineName']['#required'] ?? TRUE, 'field #required');
    $this->assertFalse($form['machineName']['widget']['#required'] ?? TRUE, 'widget #required');
    $this->assertFalse($form['machineName']['widget'][0]['#required'] ?? TRUE, 'delta #required');
    $this->assertFalse($form['machineName']['widget'][0]['value']['#required'] ?? TRUE, 'value #required');
  }

}
