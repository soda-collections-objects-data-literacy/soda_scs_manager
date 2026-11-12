<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Form\SodaScsStackCreateForm;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * What SodaScsStackCreateForm::save() logs and shows when creation fails.
 *
 * Issue 3637658: one message with a translated reason; the original exception
 * is logged; raw strings and exception texts never reach the user.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm
 * @group soda_scs_manager
 */
class SodaScsStackCreateFormSaveTest extends UnitTestCase {

  use ProphecyTrait;
  use SodaScsCreatePathTestTrait;

  /**
   * The mocked stack actions.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $stackActions;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $messenger;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;

  /**
   * The form under test.
   *
   * @var \Drupal\soda_scs_manager\Form\SodaScsStackCreateForm
   */
  protected SodaScsStackCreateForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->stackActions = $this->prophesize(SodaScsStackActionsInterface::class);
    $this->messenger = $this->prophesize(MessengerInterface::class);
    $this->logger = $this->prophesize(LoggerChannelInterface::class);
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->logger->reveal());

    $account = $this->prophesize(AccountProxyInterface::class);
    $account->id()->willReturn(7);
    $account->getAccount()->willReturn($account->reveal());
    $time = $this->prophesize(TimeInterface::class);
    $time->getRequestTime()->willReturn(1700000000);

    $stack = $this->prophesize(SodaScsStackInterface::class);
    $stack->hasField(Argument::any())->willReturn(FALSE);
    $stack->set(Argument::cetera())->willReturn($stack->reveal());
    $stack->setLabel(Argument::any())->willReturn($stack->reveal());
    $stack->get('machineName')->willReturn($this->fieldList('foo'));
    $stack->label()->willReturn('Foo');

    $this->form = $this->getMockBuilder(SodaScsStackCreateForm::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();
    $this->form->setEntity($stack->reveal());
    $this->form->setMessenger($this->messenger->reveal());
    $this->form->setStringTranslation($this->getStringTranslationStub());
    foreach ([
      'bundle' => 'soda_scs_wisski_stack',
      'currentUser' => $account->reveal(),
      'loggerFactory' => $loggerFactory->reveal(),
      'sodaScsStackActions' => $this->stackActions->reveal(),
      'time' => $time->reveal(),
    ] as $name => $value) {
      $this->setProtectedProperty($name, $value);
    }
  }

  /**
   * A translated reason is shown once, with the label.
   *
   * @covers ::save
   */
  public function testSaveShowsTranslatedReasonOnce(): void {
    $reason = new TranslatableMarkup('Database: A database with this application name already exists. Choose another name.', [], [], $this->getStringTranslationStub());
    $this->stackActions->createStack(Argument::any())->willReturn([
      'message' => $reason,
      'success' => FALSE,
      'error' => new \RuntimeException('Database sql-foo already exists.'),
    ]);
    $this->messenger->addError(Argument::that(
      fn($message) => str_contains((string) $message, 'Could not create')
        && str_contains((string) $message, 'Foo')
        && str_contains((string) $message, 'Database: A database with this application name already exists.')
    ))->shouldBeCalledOnce();
    $this->messenger->addMessage(Argument::cetera())->shouldNotBeCalled();

    $formState = new FormState();
    $this->save($formState);

    $this->assertTrue($formState->isRebuilding());
  }

  /**
   * The original exception is logged with machine name, bundle and user.
   *
   * @covers ::save
   */
  public function testSaveLogsOriginalExceptionWithContext(): void {
    $this->stackActions->createStack(Argument::any())->willReturn([
      'message' => new TranslatableMarkup('Database failed.', [], [], $this->getStringTranslationStub()),
      'success' => FALSE,
      'error' => new \RuntimeException('Database sql-foo already exists.'),
    ]);
    $this->logger->log(
      'error',
      'Cannot create stack @machineName (@bundle) for user @uid: @message',
      Argument::that(fn(array $context) => $context['@machineName'] === 'foo'
        && $context['@bundle'] === 'soda_scs_wisski_stack'
        && $context['@uid'] === 7
        && $context['@message'] === 'Database sql-foo already exists.'
        && $context['%type'] === \RuntimeException::class)
    )->shouldBeCalledOnce();

    $this->save(new FormState());
  }

  /**
   * A plain string message is never shown, only the generic reason.
   *
   * @covers ::save
   */
  public function testSaveDoesNotShowRawStringMessage(): void {
    $this->stackActions->createStack(Argument::any())->willReturn([
      'message' => 'mysql: access denied for user root',
      'success' => FALSE,
      'error' => 'access denied',
    ]);
    $this->messenger->addError(Argument::that(
      fn($message) => str_contains((string) $message, 'Try again later or contact support.')
        && !str_contains((string) $message, 'mysql')
        && !str_contains((string) $message, 'access denied')
    ))->shouldBeCalledOnce();
    $this->logger->log('error', Argument::type('string'), Argument::that(
      fn(array $context) => $context['@message'] === 'access denied'
    ))->shouldBeCalledOnce();

    $this->save(new FormState());
  }

  /**
   * A failure without error details is logged with a descriptive message.
   *
   * @covers ::save
   *
   * @dataProvider missingErrorProvider
   */
  public function testSaveNeverLogsUnknownError(array $createResult): void {
    $this->stackActions->createStack(Argument::any())->willReturn($createResult);
    $this->logger->log('error', Argument::type('string'), Argument::that(
      fn(array $context) => $context['@message'] === 'Stack creation returned no error details.'
    ))->shouldBeCalledOnce();
    $this->messenger->addError(Argument::that(
      fn($message) => !str_contains((string) $message, 'Unknown error')
    ))->shouldBeCalledOnce();

    $this->save(new FormState());
  }

  /**
   * Results that carry no usable error.
   *
   * @return array<string, array>
   *   The results.
   */
  public static function missingErrorProvider(): array {
    return [
      'no error key' => [['success' => FALSE]],
      'NULL error' => [['success' => FALSE, 'error' => NULL]],
      'FALSE error' => [['success' => FALSE, 'error' => FALSE]],
      'empty string error' => [['success' => FALSE, 'error' => '']],
    ];
  }

  /**
   * An exception thrown by createStack() is caught, logged and not shown.
   *
   * @covers ::save
   */
  public function testSaveCatchesExceptionOfCreateStack(): void {
    $failure = new \RuntimeException('Bundle info missing at db.internal.');
    $this->stackActions->createStack(Argument::any())->willThrow($failure);
    $this->logger->log('error', Argument::type('string'), Argument::that(
      fn(array $context) => $context['@message'] === 'Bundle info missing at db.internal.'
    ))->shouldBeCalledOnce();
    $this->messenger->addError(Argument::that(
      fn($message) => str_contains((string) $message, 'Try again later or contact support.')
        && !str_contains((string) $message, 'db.internal')
    ))->shouldBeCalledOnce();

    $formState = new FormState();
    $this->save($formState);

    $this->assertTrue($formState->isRebuilding());
  }

  /**
   * A successful creation redirects and shows no error.
   *
   * @covers ::save
   */
  public function testSaveRedirectsToDashboardOnSuccess(): void {
    $this->stackActions->createStack(Argument::any())->willReturn([
      'success' => TRUE,
      'error' => NULL,
      'message' => 'ok',
    ]);
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $formState = new FormState();
    $this->save($formState);

    $this->assertFalse($formState->isRebuilding());
    $this->assertSame('soda_scs_manager.dashboard', $formState->getRedirect()->getRouteName());
  }

  /**
   * Runs save() with the field values the form posts.
   */
  protected function save(FormState $formState): void {
    $formState->setValue('machineName', [['value' => 'foo']]);
    $formState->setValue('label', [['value' => 'Foo']]);
    $formState->setValue('partOfProjects', []);
    $form = [];
    $this->form->save($form, $formState);
  }

  /**
   * Sets a protected property of the form, wherever it is declared.
   */
  protected function setProtectedProperty(string $name, mixed $value): void {
    $class = new \ReflectionObject($this->form);
    while (!$class->hasProperty($name)) {
      $class = $class->getParentClass();
    }
    $class->getProperty($name)->setValue($this->form, $value);
  }

}
