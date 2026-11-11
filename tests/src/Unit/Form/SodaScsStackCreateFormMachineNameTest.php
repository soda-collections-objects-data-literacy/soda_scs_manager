<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Form;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\Core\Form\FormState;
use Drupal\soda_scs_manager\Form\SodaScsStackCreateForm;
use Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm
 *
 * @group soda_scs_manager
 */
class SodaScsStackCreateFormMachineNameTest extends UnitTestCase {

  /**
   * Empty machineName from a wizard/fetch submit is derived from the label.
   *
   * @covers ::ensureMachineNameFromLabel
   */
  public function testEnsureMachineNameFromLabelDerivesWhenEmpty(): void {
    $this->assertTrue(
      method_exists(SodaScsStackCreateForm::class, 'ensureMachineNameFromLabel'),
      'ensureMachineNameFromLabel must exist so empty wizard posts get a machine name',
    );

    $form = $this->formWithMachineNameHelper();
    $form_state = new FormState();
    $form_state->setValue(['label', 0, 'value'], 'aaa12344');
    $form_state->setValue(['machineName', 0, 'value'], '');

    $this->invokeEnsureMachineNameFromLabel($form, $form_state);

    $this->assertSame('aaa12344', $form_state->getValue(['machineName', 0, 'value']));
  }

  /**
   * A client-supplied machineName is left unchanged.
   *
   * @covers ::ensureMachineNameFromLabel
   */
  public function testEnsureMachineNameFromLabelKeepsExistingValue(): void {
    $this->assertTrue(
      method_exists(SodaScsStackCreateForm::class, 'ensureMachineNameFromLabel'),
      'ensureMachineNameFromLabel must exist so empty wizard posts get a machine name',
    );

    $form = $this->formWithMachineNameHelper();
    $form_state = new FormState();
    $form_state->setValue(['label', 0, 'value'], 'other-label');
    $form_state->setValue(['machineName', 0, 'value'], 'already-set');

    $this->invokeEnsureMachineNameFromLabel($form, $form_state);

    $this->assertSame('already-set', $form_state->getValue(['machineName', 0, 'value']));
  }

  /**
   * Stack create form with a real machine-name helper injected.
   */
  protected function formWithMachineNameHelper(): SodaScsStackCreateForm {
    $form = $this->getMockBuilder(SodaScsStackCreateForm::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $property = (new \ReflectionClass(SodaScsStackCreateForm::class))
      ->getProperty('machineNameHelper');
    $property->setValue($form, new SodaScsMachineNameHelper(new PhpTransliteration()));

    return $form;
  }

  /**
   * Calls the protected ensureMachineNameFromLabel().
   */
  protected function invokeEnsureMachineNameFromLabel(SodaScsStackCreateForm $form, FormState $form_state): void {
    $method = (new \ReflectionClass(SodaScsStackCreateForm::class))
      ->getMethod('ensureMachineNameFromLabel');
    $method->invoke($form, $form_state);
  }

}
