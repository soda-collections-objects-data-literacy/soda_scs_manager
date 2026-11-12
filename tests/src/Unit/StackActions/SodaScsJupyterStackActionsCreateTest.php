<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\StackActions;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\StackActions\SodaScsJupyterStackActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Tests\soda_scs_manager\Unit\SodaScsCreatePathTestTrait;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Failure results of SodaScsJupyterStackActions::createStack().
 *
 * Issue 3637658: the user message is translated and never holds the text of
 * an exception; the error carries the details for the log.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\StackActions\SodaScsJupyterStackActions
 * @group soda_scs_manager
 */
class SodaScsJupyterStackActionsCreateTest extends UnitTestCase {

  use ProphecyTrait;
  use SodaScsCreatePathTestTrait;

  /**
   * The mocked project application helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $applicationHelpers;

  /**
   * The mocked project helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $projectHelpers;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $messenger;

  /**
   * The mocked stack.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $stack;

  /**
   * The mocked project.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $project;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsJupyterStackActions
   */
  protected SodaScsJupyterStackActions $actions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('soda_scs_manager')->willReturn($this->prophesize(LoggerChannelInterface::class)->reveal());
    $this->messenger = $this->prophesize(MessengerInterface::class);

    $this->project = $this->prophesize(SodaScsProjectInterface::class);
    $this->project->id()->willReturn('3');
    $this->project->getOwnerId()->willReturn(7);

    $this->stack = $this->prophesize(SodaScsStackInterface::class);
    $this->stack->id()->willReturn(NULL);
    $this->stack->label()->willReturn('Jupyter');
    $this->stack->set(Argument::cetera())->willReturn($this->stack->reveal());

    $this->applicationHelpers = $this->prophesize(SodaScsProjectApplicationHelpers::class);
    $this->applicationHelpers->loadPartOfProject(Argument::any())->willReturn($this->project->reveal());
    $this->applicationHelpers->loadProjectApplication(Argument::cetera())->willReturn(NULL);

    $this->projectHelpers = $this->prophesize(SodaScsProjectHelpers::class);

    $this->actions = new SodaScsJupyterStackActions(
      $loggerFactory->reveal(),
      $this->messenger->reveal(),
      $this->prophesize(SodaScsStackHelpers::class)->reveal(),
      $this->getStringTranslationStub(),
      $this->applicationHelpers->reveal(),
      $this->prophesize(SodaScsJupyterHelpers::class)->reveal(),
      $this->prophesize(NextcloudProjectMountManager::class)->reveal(),
      $this->projectHelpers->reveal(),
      $this->prophesize(SodaScsServiceRequestInterface::class)->reveal(),
    );
  }

  /**
   * A project that already has JupyterHub is a complete failure result.
   *
   * @covers ::createStack
   */
  public function testCreateStackFailsWhenProjectAlreadyHasJupyterHub(): void {
    $this->applicationHelpers->loadProjectApplication(Argument::cetera())->willReturn($this->stack->reveal());
    $this->stack->save()->shouldNotBeCalled();

    $result = $this->actions->createStack($this->stack->reveal());

    $this->assertCompleteFailure($result);
    $this->assertSame('This project already has JupyterHub.', $result['message']->getUntranslatedString());
  }

  /**
   * A project folder that is not ready is a failure; the stack is not saved.
   *
   * @covers ::createStack
   */
  public function testCreateStackFailsWhenProjectFolderGroupsCannotBeSynced(): void {
    $this->projectHelpers->syncProjectTeamFolderGroups($this->project->reveal(), TRUE)
      ->willReturn(SodaScsResult::failure('Group sync failed.', 'The Team Folder groups are missing.', FALSE));
    $this->stack->save()->shouldNotBeCalled();
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createStack($this->stack->reveal());

    $this->assertCompleteFailure($result);
    $this->assertSame('Group sync failed.', $result['error']);
    $this->assertSame(
      'The project folder is not ready for JupyterHub: The Team Folder groups are missing.',
      (string) $result['message'],
    );
  }

  /**
   * The text of an exception is returned as the error, not shown.
   *
   * @covers ::createStack
   */
  public function testCreateStackKeepsExceptionTextOutOfTheUserMessage(): void {
    $failure = new \RuntimeException('connect to jupyterhub.internal:8081 refused');
    $this->projectHelpers->syncProjectTeamFolderGroups($this->project->reveal(), TRUE)->willThrow($failure);
    $this->messenger->addError(Argument::cetera())->shouldNotBeCalled();

    $result = $this->actions->createStack($this->stack->reveal());

    $this->assertCompleteFailure($result);
    $this->assertSame($failure, $result['error']);
    $this->assertSame('JupyterHub could not be set up for this project.', (string) $result['message']);
    $this->assertStringNotContainsString('jupyterhub.internal', (string) $result['message']);
  }

}
