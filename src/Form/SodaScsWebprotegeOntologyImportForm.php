<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsWebprotegeServiceActions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploads an ontology file into the WebProtégé project of a component.
 */
final class SodaScsWebprotegeOntologyImportForm extends FormBase {

  /**
   * Revision message used when the user enters none.
   */
  public const DEFAULT_COMMIT_MESSAGE = 'Ontology imported by the SCS Manager';

  /**
   * Largest file the form accepts, in bytes.
   *
   * The document is held in memory and checked for imports before it is
   * posted, and WebProtégé must parse it within the request timeout.
   */
  public const MAX_FILE_SIZE = 50 * 1024 * 1024;

  /**
   * Name of the file element.
   */
  private const FILE_ELEMENT = 'ontology_file';

  /**
   * Constructs the form.
   */
  public function __construct(
    protected SodaScsProjectApplicationHelpers $projectApplicationHelpers,
    protected SodaScsWebprotegeHelpers $webprotegeHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('soda_scs_manager.project_application.helpers'),
      $container->get('soda_scs_manager.webprotege.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsComponentInterface $soda_scs_component = NULL): array {
    if (!$soda_scs_component instanceof SodaScsComponentInterface) {
      throw new \InvalidArgumentException('WebProtégé component is required.');
    }
    $form_state->set('component', $soda_scs_component);
    $extensions = array_keys(SodaScsWebprotegeServiceActions::ONTOLOGY_DOCUMENT_MIME_TYPES);

    $form['#attributes']['class'][] = 'soda-scs-manager--webprotege-ontology-import';
    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('The classes, properties and other axioms of the file are added to the ontology of this WebProtégé project. Nothing in the project is removed or replaced.'),
    ];
    $form[self::FILE_ELEMENT] = [
      '#type' => 'file',
      '#title' => $this->t('Ontology file'),
      '#description' => $this->t('RDF/XML, Turtle or OWL functional syntax. Allowed extensions: @extensions. Maximum size: @size. An .owl file must be RDF/XML: save OWL/XML files from Protégé as RDF/XML first. Imports (owl:imports) are loaded too and must be public http or https addresses.', [
        '@extensions' => implode(', ', $extensions),
        '@size' => ByteSizeMarkup::create(self::maxFileSize()),
      ]),
      '#attributes' => ['accept' => '.' . implode(',.', $extensions)],
    ];
    $form['commit_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Revision message'),
      '#description' => $this->t('Shown in the history of the WebProtégé project.'),
      '#default_value' => self::DEFAULT_COMMIT_MESSAGE,
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import ontology'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $soda_scs_component->toUrl(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_webprotege_ontology_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    $component = $form_state->get('component');
    $form_state->setRedirectUrl($component->toUrl());

    $project = $this->projectApplicationHelpers->loadPartOfProject($component);
    /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
    $file = $form_state->getTemporaryValue('uploaded_file');
    if (!$project instanceof SodaScsProjectInterface) {
      $this->messenger()->addError($this->t('This WebProtégé application belongs to no project.'));
      return;
    }

    $document = file_get_contents($file->getRealPath());
    if ($document === FALSE) {
      $this->logger('soda_scs_manager')->error('Could not read the uploaded ontology file for component @id.', ['@id' => $component->id()]);
      $this->messenger()->addError($this->t('The uploaded file could not be read.'));
      return;
    }

    $commitMessage = trim((string) $form_state->getValue('commit_message')) ?: self::DEFAULT_COMMIT_MESSAGE;
    $result = $this->webprotegeHelpers->importOntologyDocument(
      $commitMessage,
      $document,
      strtolower($file->getClientOriginalExtension()),
      $project,
    );
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
      return;
    }
    $this->messenger()->addError($result->message);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $files = $this->getRequest()->files->all('files');
    $file = $files[self::FILE_ELEMENT] ?? NULL;
    if (!$file instanceof UploadedFile) {
      $form_state->setErrorByName(self::FILE_ELEMENT, $this->t('Choose an ontology file.'));
      return;
    }
    if (!$file->isValid()) {
      $form_state->setErrorByName(self::FILE_ELEMENT, $this->t('The file could not be uploaded. It may be larger than @size.', [
        '@size' => ByteSizeMarkup::create(self::maxFileSize()),
      ]));
      return;
    }
    if ($file->getSize() > self::maxFileSize()) {
      $form_state->setErrorByName(self::FILE_ELEMENT, $this->t('The file is larger than @size.', [
        '@size' => ByteSizeMarkup::create(self::maxFileSize()),
      ]));
      return;
    }
    $extension = strtolower($file->getClientOriginalExtension());
    if (!isset(SodaScsWebprotegeServiceActions::ONTOLOGY_DOCUMENT_MIME_TYPES[$extension])) {
      $form_state->setErrorByName(self::FILE_ELEMENT, $this->t('Only files with these extensions can be imported: @extensions.', [
        '@extensions' => implode(', ', array_keys(SodaScsWebprotegeServiceActions::ONTOLOGY_DOCUMENT_MIME_TYPES)),
      ]));
      return;
    }
    $form_state->setTemporaryValue('uploaded_file', $file);
  }

  /**
   * The size limit: MAX_FILE_SIZE, or PHP's upload limit when lower.
   */
  private static function maxFileSize(): int {
    return (int) min(self::MAX_FILE_SIZE, Environment::getUploadMaxSize());
  }

}
