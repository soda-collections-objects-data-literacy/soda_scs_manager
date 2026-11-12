<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\Form;

use Drupal\Core\Form\FormState;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;

/**
 * Fields the edit forms of stacks and components must not offer.
 *
 * @group soda_scs_manager
 */
class SodaScsEditFormsHideFieldsTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('current_user')->setAccount($this->testUser);
  }

  /**
   * The default data model switch can not be changed on a WissKI stack.
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsStackEditForm::buildForm
   */
  public function testStackEditFormHidesWithDefaultDataModel(): void {
    $stack = $this->createTestStack('soda_scs_wisski_stack');

    $form = $this->buildEditForm('soda_scs_stack', $stack);

    $this->assertArrayHasKey('widget', $form['withDefaultDataModel'], 'The field is on the form display.');
    $this->assertFalse($form['withDefaultDataModel']['#access']);
    $this->assertFalse($form['developmentInstance']['#access']);
  }

  /**
   * The default data model switch can not be changed on a WissKI component.
   *
   * @covers \Drupal\soda_scs_manager\Form\SodaScsComponentEditForm::buildForm
   */
  public function testComponentEditFormHidesWithDefaultDataModel(): void {
    $component = $this->createTestComponent('soda_scs_wisski_component');

    $form = $this->buildEditForm('soda_scs_component', $component);

    $this->assertArrayHasKey('widget', $form['withDefaultDataModel'], 'The field is on the form display.');
    $this->assertFalse($form['withDefaultDataModel']['#access']);
    $this->assertFalse($form['developmentInstance']['#access']);
  }

  /**
   * Builds the edit form of an entity.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array
   *   The form array.
   */
  protected function buildEditForm(string $entityTypeId, $entity): array {
    $formObject = $this->entityTypeManager->getFormObject($entityTypeId, 'edit');
    $formObject->setEntity($entity);
    $formObject->setModuleHandler($this->container->get('module_handler'));
    $formObject->setEntityTypeManager($this->entityTypeManager);
    return $formObject->buildForm([], new FormState());
  }

}
