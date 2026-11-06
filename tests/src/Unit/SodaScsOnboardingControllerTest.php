<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\soda_scs_manager\Controller\SodaScsOnboardingController;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests automatic guided onboarding startup.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\Controller\SodaScsOnboardingController
 */
class SodaScsOnboardingControllerTest extends UnitTestCase {

  private ?ContainerInterface $previousContainer = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->previousContainer = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('42');

    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->previousContainer) {
      \Drupal::setContainer($this->previousContainer);
    }
    else {
      \Drupal::unsetContainer();
    }
    parent::tearDown();
  }

  /**
   * @covers ::state
   */
  public function testStateStartsTourWithoutLegacyIntroCompletion(): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->expects($this->once())
      ->method('get')
      ->with('soda_scs_manager', 42, 'onboarding_tour_completed')
      ->willReturn(NULL);

    $response = (new SodaScsOnboardingController($userData))->state();

    $this->assertSame(['autoStart' => TRUE], json_decode($response->getContent(), TRUE));
    $this->assertSame(0, $response->getMaxAge());
  }

  /**
   * @covers ::state
   */
  public function testStateDoesNotRestartCompletedTour(): void {
    $userData = $this->createMock(UserDataInterface::class);
    $userData->expects($this->once())
      ->method('get')
      ->with('soda_scs_manager', 42, 'onboarding_tour_completed')
      ->willReturn(1);

    $response = (new SodaScsOnboardingController($userData))->state();

    $this->assertSame(['autoStart' => FALSE], json_decode($response->getContent(), TRUE));
  }

}
