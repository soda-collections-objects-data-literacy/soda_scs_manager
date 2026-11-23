<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoQueueRunner;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Form\SodaScsDemoProjectAdminForm;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the version labels of the demo project admin form.
 *
 * Issue 3640915: imported versions are marked in the table.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Form\SodaScsDemoProjectAdminForm
 * @group soda_scs_manager
 */
class SodaScsDemoProjectAdminFormVersionsTest extends UnitTestCase {

  /**
   * The demo manager double.
   */
  private SodaScsDemoManager&MockObject $demoManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturn('/status');
    $container = new ContainerBuilder();
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);
    $this->demoManager = $this->createMock(SodaScsDemoManager::class);
  }

  /**
   * The version column marks imported and active versions.
   *
   * @covers ::buildVersionsTable
   */
  #[DataProvider('labelCases')]
  public function testVersionLabel(bool $imported, bool $active, string $expected): void {
    $template = $this->createMock(SodaScsDemoTemplateInterface::class);
    $template->method('id')->willReturn(3);
    $template->method('getVersion')->willReturn('1.4.0');
    $template->method('isImported')->willReturn($imported);
    $template->method('getStatus')->willReturn('ready');
    $template->method('getChangelog')->willReturn('');
    $created = $this->createMock(FieldItemList::class);
    $created->method('__get')->with('value')->willReturn('1700000000');
    $template->method('get')->with('created')->willReturn($created);
    $this->demoManager->method('templatesByVersion')->willReturn([$template]);
    $this->demoManager->method('isActiveTemplate')->willReturn($active);
    $this->demoManager->method('templateUsageCount')->willReturn(0);
    $this->demoManager->method('templateStatusText')->willReturn('Ready');
    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('format')->willReturn('today');
    $form = new SodaScsDemoProjectAdminForm(
      $dateFormatter,
      $this->demoManager,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(SodaScsDemoQueueRunner::class),
    );
    $form->setStringTranslation($this->getStringTranslationStub());

    $table = (new \ReflectionMethod($form, 'buildVersionsTable'))->invoke($form);

    $this->assertSame($expected, (string) $table['template_3']['version']['#markup']);
  }

  /**
   * Cases of the version label.
   *
   * @return array<string, array{bool, bool, string}>
   *   Imported flag, active flag and the label.
   */
  public static function labelCases(): array {
    return [
      'built' => [FALSE, FALSE, 'v1.4.0'],
      'built and active' => [FALSE, TRUE, 'v1.4.0 (active)'],
      'imported' => [TRUE, FALSE, 'v1.4.0 (imported)'],
      'imported and active' => [TRUE, TRUE, 'v1.4.0 (active, imported)'],
    ];
  }

}
