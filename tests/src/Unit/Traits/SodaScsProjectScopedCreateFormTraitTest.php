<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Traits;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\Traits\SodaScsProjectScopedCreateFormTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests SodaScsProjectScopedCreateFormTrait.
 */
#[CoversMethod(SodaScsProjectScopedCreateFormTrait::class, 'queryDefersProjectSelection')]
#[CoversMethod(SodaScsProjectScopedCreateFormTrait::class, 'isValidateOnlyRequest')]
#[CoversMethod(SodaScsProjectScopedCreateFormTrait::class, 'submitValidateOnly')]
#[CoversMethod(SodaScsProjectScopedCreateFormTrait::class, 'getEditedFieldNames')]
#[CoversMethod(SodaScsProjectScopedCreateFormTrait::class, 'applySelectedProject')]
#[Group('soda_scs_manager')]
class SodaScsProjectScopedCreateFormTraitTest extends UnitTestCase {

  /**
   * Only the query string "1" skips the project picker.
   */
  public function testQueryDefersProjectSelectionOnlyForTheStringOne(): void {
    $this->assertTrue(DeferProjectSelectionProbe::queryDefersProjectSelection('1'));
    $this->assertFalse(DeferProjectSelectionProbe::queryDefersProjectSelection('0'));
    $this->assertFalse(DeferProjectSelectionProbe::queryDefersProjectSelection('true'));
    $this->assertFalse(DeferProjectSelectionProbe::queryDefersProjectSelection(1));
    $this->assertFalse(DeferProjectSelectionProbe::queryDefersProjectSelection(NULL));
  }

  /**
   * Validate-only mode needs both query values to be the string "1".
   *
   * @param array<string, mixed> $query
   *   The query parameters of the request.
   * @param bool $expected
   *   Whether the request is a validate-only request.
   */
  #[DataProvider('providerValidateOnlyQuery')]
  public function testIsValidateOnlyRequestNeedsBothQueryValuesAsStringOne(array $query, bool $expected): void {
    $probe = new ValidateOnlyProbe(new Request($query));

    $this->assertSame($expected, $probe->isValidateOnly());
  }

  /**
   * Query parameters and whether they mean validate-only.
   *
   * @return array<string, array{0: array<string, mixed>, 1: bool}>
   *   The parameters and the expectation.
   */
  public static function providerValidateOnlyQuery(): array {
    return [
      'both are 1' => [['deferProject' => '1', 'validateOnly' => '1'], TRUE],
      'validateOnly alone' => [['validateOnly' => '1'], FALSE],
      'deferProject alone' => [['deferProject' => '1'], FALSE],
      'no parameters' => [[], FALSE],
      'validateOnly is 0' => [['deferProject' => '1', 'validateOnly' => '0'], FALSE],
      'validateOnly is true' => [['deferProject' => '1', 'validateOnly' => 'true'], FALSE],
      'validateOnly is 01' => [['deferProject' => '1', 'validateOnly' => '01'], FALSE],
      'validateOnly is 1.0' => [['deferProject' => '1', 'validateOnly' => '1.0'], FALSE],
      'validateOnly is empty' => [['deferProject' => '1', 'validateOnly' => ''], FALSE],
      'deferProject is 0' => [['deferProject' => '0', 'validateOnly' => '1'], FALSE],
      'deferProject is true' => [['deferProject' => 'true', 'validateOnly' => '1'], FALSE],
    ];
  }

  /**
   * Without a request nothing is validate-only.
   */
  public function testIsValidateOnlyRequestIsFalseWithoutRequest(): void {
    $this->assertFalse((new ValidateOnlyProbe(NULL))->isValidateOnly());
  }

  /**
   * The validate-only submit handler asks for a rebuild and nothing else.
   */
  public function testSubmitValidateOnlyRebuildsTheForm(): void {
    $form = [];
    $form_state = new FormState();

    (new ValidateOnlyProbe(NULL))->submitValidateOnly($form, $form_state);

    $this->assertTrue($form_state->isRebuilding());
    $this->assertFalse($form_state->isExecuted());
    $this->assertSame([], $form);
  }

  /**
   * A validate-only request leaves partOfProjects out of the edited fields.
   */
  public function testGetEditedFieldNamesDropsPartOfProjectsWhenValidateOnly(): void {
    $probe = new ValidateOnlyProbe(new Request(['deferProject' => '1', 'validateOnly' => '1']));

    $this->assertSame(['label', 'machineName'], $probe->editedFieldNames(new FormState()));
  }

  /**
   * Other requests keep partOfProjects in the edited fields.
   */
  public function testGetEditedFieldNamesKeepsPartOfProjectsOtherwise(): void {
    $probe = new ValidateOnlyProbe(new Request(['deferProject' => '1']));

    $this->assertSame(['label', 'machineName', 'partOfProjects'], $probe->editedFieldNames(new FormState()));
  }

  /**
   * A validate-only request selects no project and sets no error.
   */
  public function testApplySelectedProjectDoesNothingWhenValidateOnly(): void {
    $probe = new ValidateOnlyProbe(new Request(['deferProject' => '1', 'validateOnly' => '1']));
    $form_state = new FormState();

    $this->assertNull($probe->selectProject($form_state));
    $this->assertSame([], $form_state->getErrors());
    $this->assertNull($form_state->getValue('partOfProjects'));
  }

}

/**
 * Exposes the trait method under test.
 */
class DeferProjectSelectionProbe {

  use SodaScsProjectScopedCreateFormTrait;

}

/**
 * The form base class the trait overrides.
 */
class EditedFieldNamesBase {

  /**
   * Field names as the entity form reports them.
   *
   * @return string[]
   *   The edited field names.
   */
  protected function getEditedFieldNames(FormStateInterface $form_state) {
    return ['label', 'machineName', 'partOfProjects'];
  }

}

/**
 * A create form reduced to what the trait uses, with a fixed request.
 */
class ValidateOnlyProbe extends EditedFieldNamesBase {

  use SodaScsProjectScopedCreateFormTrait {
    getEditedFieldNames as traitGetEditedFieldNames;
  }

  public function __construct(private readonly ?Request $request) {}

  /**
   * The request the trait reads.
   */
  protected function getRequest(): ?Request {
    return $this->request;
  }

  /**
   * Message for the project error when the trait sets one.
   */
  protected function t(string $string): string {
    return $string;
  }

  /**
   * Edited field names as the trait reports them.
   *
   * @return string[]
   *   The names.
   */
  public function editedFieldNames(FormStateInterface $form_state): array {
    return $this->traitGetEditedFieldNames($form_state);
  }

  /**
   * Exposes isValidateOnlyRequest().
   */
  public function isValidateOnly(): bool {
    return $this->isValidateOnlyRequest();
  }

  /**
   * Exposes applySelectedProject().
   */
  public function selectProject(FormStateInterface $form_state): int|string|null {
    return $this->applySelectedProject($form_state);
  }

}
