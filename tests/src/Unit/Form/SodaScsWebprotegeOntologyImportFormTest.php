<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Form;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Form\SodaScsWebprotegeOntologyImportForm;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the upload validation and submit of the ontology import form.
 */
#[CoversMethod(SodaScsWebprotegeOntologyImportForm::class, 'validateForm')]
#[CoversMethod(SodaScsWebprotegeOntologyImportForm::class, 'submitForm')]
#[Group('soda_scs_manager')]
class SodaScsWebprotegeOntologyImportFormTest extends UnitTestCase {

  /**
   * Files created by the test.
   *
   * @var string[]
   */
  private array $files = [];

  /**
   * Project application helpers double.
   */
  private SodaScsProjectApplicationHelpers $applicationHelpers;

  /**
   * WebProtégé helpers double.
   */
  private SodaScsWebprotegeHelpers $webprotegeHelpers;

  /**
   * Messenger double.
   */
  private MessengerInterface $messenger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->applicationHelpers = $this->createMock(SodaScsProjectApplicationHelpers::class);
    $this->webprotegeHelpers = $this->createMock(SodaScsWebprotegeHelpers::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
  }

  /**
   * Builds the form with the given request holding the upload.
   *
   * @param array<string, mixed> $files
   *   Uploaded files by element name, as under the request's "files" key.
   */
  private function createForm(array $files): SodaScsWebprotegeOntologyImportForm {
    $request = new Request();
    $request->files->set('files', $files);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $form = new SodaScsWebprotegeOntologyImportForm($this->applicationHelpers, $this->webprotegeHelpers);
    $form->setRequestStack($requestStack);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messenger);
    return $form;
  }

  /**
   * Creates an uploaded file with the given client name and content.
   */
  private function createUpload(string $clientName, string $content = 'document body', int $error = UPLOAD_ERR_OK): UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'scs-ontology-');
    $this->assertIsString($path);
    $this->files[] = $path;
    file_put_contents($path, $content);
    return new UploadedFile($path, $clientName, NULL, $error, TRUE);
  }

  /**
   * Builds a component double that links to a canonical URL.
   */
  private function createComponent(): SodaScsComponentInterface {
    $url = $this->createMock(Url::class);
    $component = $this->createMock(SodaScsComponentInterface::class);
    $component->method('id')->willReturn(5);
    $component->method('toUrl')->willReturn($url);
    return $component;
  }

  /**
   * Validates the form and returns the form state.
   */
  private function validate(SodaScsWebprotegeOntologyImportForm $form): FormState {
    $formState = new FormState();
    $formArray = [];
    $form->validateForm($formArray, $formState);
    return $formState;
  }

  /**
   * Tests validate rejects missing file.
   */
  public function testValidateRejectsMissingFile(): void {
    $formState = $this->validate($this->createForm([]));

    $this->assertArrayHasKey('ontology_file', $formState->getErrors());
    $this->assertNull($formState->getTemporaryValue('uploaded_file'));
  }

  /**
   * Tests validate rejects failed upload.
   */
  public function testValidateRejectsFailedUpload(): void {
    $upload = $this->createUpload('model.ttl', 'x', UPLOAD_ERR_INI_SIZE);

    $formState = $this->validate($this->createForm(['ontology_file' => $upload]));

    $this->assertArrayHasKey('ontology_file', $formState->getErrors());
    $this->assertNull($formState->getTemporaryValue('uploaded_file'));
  }

  /**
   * Tests validate rejects file above the size limit.
   */
  public function testValidateRejectsFileAboveTheSizeLimit(): void {
    $upload = $this->createUploadOfSize('large.ttl', SodaScsWebprotegeOntologyImportForm::MAX_FILE_SIZE + 1);

    $formState = $this->validate($this->createForm(['ontology_file' => $upload]));

    $this->assertArrayHasKey('ontology_file', $formState->getErrors());
    $this->assertNull($formState->getTemporaryValue('uploaded_file'));
  }

  /**
   * Tests validate accepts file at the size limit.
   */
  public function testValidateAcceptsFileAtTheSizeLimit(): void {
    $upload = $this->createUploadOfSize('large.ttl', SodaScsWebprotegeOntologyImportForm::MAX_FILE_SIZE);

    $formState = $this->validate($this->createForm(['ontology_file' => $upload]));

    $this->assertSame([], $formState->getErrors());
    $this->assertSame($upload, $formState->getTemporaryValue('uploaded_file'));
  }

  /**
   * Creates a sparse uploaded file of the given size.
   */
  private function createUploadOfSize(string $clientName, int $size): UploadedFile {
    $upload = $this->createUpload($clientName, '');
    $handle = fopen($upload->getPathname(), 'r+');
    $this->assertIsResource($handle);
    ftruncate($handle, $size);
    fclose($handle);
    clearstatcache();
    return $upload;
  }

  /**
   * Tests validate rejects unsupported extension.
   */
  #[DataProvider('providerUnsupportedFileNames')]
  public function testValidateRejectsUnsupportedExtension(string $clientName): void {
    $upload = $this->createUpload($clientName);

    $formState = $this->validate($this->createForm(['ontology_file' => $upload]));

    $errors = $formState->getErrors();
    $this->assertArrayHasKey('ontology_file', $errors);
    $this->assertStringContainsString('ofn, owl, rdf, ttl', (string) $errors['ontology_file']);
    $this->assertNull($formState->getTemporaryValue('uploaded_file'));
  }

  /**
   * File names without an importable extension.
   */
  public static function providerUnsupportedFileNames(): array {
    return [
      'text file' => ['notes.txt'],
      'zip archive' => ['ontology.zip'],
      'no extension' => ['ontology'],
      'php file' => ['shell.php'],
      'supported extension only as part of the name' => ['model.ttl.exe'],
    ];
  }

  /**
   * Tests validate accepts supported extension in any case.
   */
  #[DataProvider('providerSupportedFileNames')]
  public function testValidateAcceptsSupportedExtensionInAnyCase(string $clientName): void {
    $upload = $this->createUpload($clientName);

    $formState = $this->validate($this->createForm(['ontology_file' => $upload]));

    $this->assertSame([], $formState->getErrors());
    $this->assertSame($upload, $formState->getTemporaryValue('uploaded_file'));
  }

  /**
   * File names the import accepts.
   */
  public static function providerSupportedFileNames(): array {
    return [
      'turtle' => ['model.ttl'],
      'upper case turtle' => ['MODEL.TTL'],
      'rdf/xml owl' => ['model.owl'],
      'rdf/xml rdf' => ['model.rdf'],
      'functional syntax' => ['model.ofn'],
      'dots in the name' => ['model.v1.2.ofn'],
    ];
  }

  /**
   * Tests submit passes document lowercase extension and message to helper.
   */
  public function testSubmitPassesDocumentLowercaseExtensionAndMessageToHelper(): void {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $this->applicationHelpers->method('loadPartOfProject')->willReturn($project);
    $this->webprotegeHelpers->expects($this->once())->method('importOntologyDocument')
      ->with('Add the people classes', '<a> <b> <c> .', 'ttl', $project)
      ->willReturn(SodaScsResult::success(['webprotegeProjectId' => 'x'], 'Imported the ontology into demo.'));
    $this->messenger->expects($this->once())->method('addStatus')->with('Imported the ontology into demo.');
    $this->messenger->expects($this->never())->method('addError');

    $form = $this->createForm([]);
    $formState = $this->prepareSubmit($this->createUpload('MODEL.TTL', '<a> <b> <c> .'), 'Add the people classes');
    $formArray = [];
    $form->submitForm($formArray, $formState);

    $this->assertNotNull($formState->getRedirect());
  }

  /**
   * Tests submit uses default message when message is blank.
   */
  public function testSubmitUsesDefaultMessageWhenMessageIsBlank(): void {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $this->applicationHelpers->method('loadPartOfProject')->willReturn($project);
    $this->webprotegeHelpers->expects($this->once())->method('importOntologyDocument')
      ->with('Ontology imported by the SCS Manager', 'body', 'owl', $project)
      ->willReturn(SodaScsResult::success([], 'Imported.'));

    $formState = $this->prepareSubmit($this->createUpload('model.owl', 'body'), "  \t ");
    $formArray = [];
    $this->createForm([])->submitForm($formArray, $formState);
  }

  /**
   * Tests submit shows error message when import fails.
   */
  public function testSubmitShowsErrorMessageWhenImportFails(): void {
    $project = $this->createMock(SodaScsProjectInterface::class);
    $this->applicationHelpers->method('loadPartOfProject')->willReturn($project);
    $this->webprotegeHelpers->method('importOntologyDocument')
      ->willReturn(SodaScsResult::failure('HTTP 400 from WebProtégé', 'WebProtégé could not read the ontology file.', FALSE));
    $this->messenger->expects($this->once())->method('addError')->with('WebProtégé could not read the ontology file.');
    $this->messenger->expects($this->never())->method('addStatus');

    $formState = $this->prepareSubmit($this->createUpload('model.ttl'), 'msg');
    $formArray = [];
    $this->createForm([])->submitForm($formArray, $formState);

    $this->assertNotNull($formState->getRedirect());
  }

  /**
   * Tests submit imports nothing when component belongs to no project.
   */
  public function testSubmitImportsNothingWhenComponentBelongsToNoProject(): void {
    $this->applicationHelpers->method('loadPartOfProject')->willReturn(NULL);
    $this->webprotegeHelpers->expects($this->never())->method('importOntologyDocument');
    $this->messenger->expects($this->once())->method('addError');
    $this->messenger->expects($this->never())->method('addStatus');

    $formState = $this->prepareSubmit($this->createUpload('model.ttl'), 'msg');
    $formArray = [];
    $this->createForm([])->submitForm($formArray, $formState);

    $this->assertNotNull($formState->getRedirect());
  }

  /**
   * Builds the form state validateForm() leaves for submitForm().
   */
  private function prepareSubmit(UploadedFile $upload, string $commitMessage): FormState {
    $formState = new FormState();
    $formState->set('component', $this->createComponent());
    $formState->setTemporaryValue('uploaded_file', $upload);
    $formState->setValue('commit_message', $commitMessage);
    return $formState;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->files as $file) {
      if (is_file($file)) {
        unlink($file);
      }
    }
    $this->files = [];
    parent::tearDown();
  }

}
