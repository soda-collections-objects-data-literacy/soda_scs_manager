<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Form;

use Drupal\Core\Form\FormState;
use Drupal\soda_scs_manager\Form\SodaScsStackCreateForm;
use Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm
 *
 * @group soda_scs_manager
 */
class SodaScsStackCreateFormMachineNameRequiredTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $role = Role::load('authenticated');
    $role->grantPermission('soda scs create stack entities');
    $role->grantPermission('access content');
    $role->save();
    $this->container->get('current_user')->setAccount($this->testUser);
    $this->container->get('request_stack')->getCurrentRequest()->query->set('deferProject', '1');
  }

  /**
   * The machineName field stays required; the value callback fills it earlier.
   *
   * @covers ::buildForm
   */
  public function testMachineNameStaysRequiredAndUsesValueCallback(): void {
    $form = $this->buildStackCreateForm();
    $value = $form['machineName']['widget'][0]['value'];

    $this->assertTrue($value['#required']);
    $this->assertSame([
      SodaScsMachineNameHelper::class,
      'valueCallback',
    ], $value['#value_callback']);
  }

  /**
   * An empty posted machineName is derived before the required check.
   *
   * No project is selected, so validation fails closed and nothing is saved.
   *
   * @covers \Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper::valueCallback
   */
  public function testEmptyPostedMachineNameIsDerivedBeforeRequiredCheck(): void {
    $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->create([
      'bundle' => 'soda_scs_jupyter_stack',
    ]);
    /** @var \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm $form_object */
    $form_object = $this->entityTypeManager->getFormObject('soda_scs_stack', 'add');
    $this->assertInstanceOf(SodaScsStackCreateForm::class, $form_object);
    $form_object->setEntity($stack);

    $form_state = new FormState();
    $form_state->setProgrammedBypassAccessCheck(TRUE);
    $form_state->setRebuild(TRUE);
    $form_state->setValue(['label', 0, 'value'], 'aaa12344');
    $form_state->setValue(['machineName', 0, 'value'], '');

    $this->container->get('form_builder')->submitForm($form_object, $form_state, 'soda_scs_jupyter_stack');

    $this->assertSame('aaa12344', $form_state->getValue(['machineName', 0, 'value']));
    $messages = implode(' ', array_map('strval', $form_state->getErrors()));
    $this->assertStringNotContainsString('Machine Name field is required', $messages);
    $this->assertStringNotContainsString('could not be derived', $messages);
    $this->assertFalse($form_state->isExecuted(), 'A form with no project must not be saved.');
  }

  /**
   * A label with no letters still fails the required check.
   *
   * @covers \Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper::valueCallback
   */
  public function testUndersivableMachineNameStaysRequired(): void {
    $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->create([
      'bundle' => 'soda_scs_jupyter_stack',
    ]);
    $form_object = $this->entityTypeManager->getFormObject('soda_scs_stack', 'add');
    $form_object->setEntity($stack);

    $form_state = new FormState();
    $form_state->setProgrammedBypassAccessCheck(TRUE);
    $form_state->setRebuild(TRUE);
    $form_state->setValue(['label', 0, 'value'], '123');
    $form_state->setValue(['machineName', 0, 'value'], '');

    $this->container->get('form_builder')->submitForm($form_object, $form_state, 'soda_scs_jupyter_stack');

    $messages = implode(' ', array_map('strval', $form_state->getErrors()));
    $this->assertStringContainsString('could not be derived', $messages);
    $this->assertFalse($form_state->isExecuted());
  }

  /**
   * Builds the WissKI stack create form for the current user.
   */
  protected function buildStackCreateForm(): array {
    $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->create([
      'bundle' => 'soda_scs_jupyter_stack',
      'label' => 'Kernel required check',
    ]);
    /** @var \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm $form_object */
    $form_object = $this->entityTypeManager->getFormObject('soda_scs_stack', 'add');
    $form_object->setEntity($stack);
    $form_object->setModuleHandler($this->container->get('module_handler'));
    $form_object->setEntityTypeManager($this->entityTypeManager);

    return $form_object->buildForm([], new FormState(), 'soda_scs_jupyter_stack');
  }

}
