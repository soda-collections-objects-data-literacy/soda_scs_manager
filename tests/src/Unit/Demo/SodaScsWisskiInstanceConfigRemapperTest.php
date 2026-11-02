<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\soda_scs_manager\Demo\SodaScsWisskiInstanceConfigRemapper;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\soda_scs_manager\Demo\SodaScsWisskiInstanceConfigRemapper
 * @group soda_scs_manager
 */
class SodaScsWisskiInstanceConfigRemapperTest extends UnitTestCase {

  /**
   * The remapper under test.
   */
  protected SodaScsWisskiInstanceConfigRemapper $remapper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->remapper = new SodaScsWisskiInstanceConfigRemapper($this->createMock(SodaScsContainerHelpers::class));
  }

  /**
   * @covers ::parsePayload
   */
  public function testParsePayloadIgnoresSurroundingDrushOutput(): void {
    $configs = [
      'wisski_salz.wisski_salz_adapter.default' => ['settings' => ['read_url' => 'https://ts.example/repositories/ts-anna-demo']],
      'system.site' => ['name' => 'Säurebad / "quoted"'],
    ];
    $output = "[warning] Something noisy\nSCS_INSTANCE_CONFIG:" . base64_encode(json_encode($configs)) . "\n[notice] done";

    $this->assertSame($configs, $this->remapper->parsePayload($output));
  }

  /**
   * @covers ::parsePayload
   * @dataProvider invalidPayloadProvider
   */
  public function testParsePayloadRejectsInvalidOutput(string $output): void {
    $this->assertNull($this->remapper->parsePayload($output));
  }

  /**
   * @covers ::omitLoginRedirects
   */
  public function testOmitLoginRedirectsKeepsTheSsoClient(): void {
    $configs = [
      'openid_connect.settings' => [
        'redirect_login' => 'home',
        'redirect_logout' => 'home',
        'user_login_display' => 'hidden',
      ],
      'openid_connect.client.scs_sso' => [
        'settings' => ['client_id' => 'https://robert-demo.example'],
      ],
    ];

    $result = $this->remapper->omitLoginRedirects($configs);

    $this->assertArrayNotHasKey('redirect_login', $result['openid_connect.settings']);
    $this->assertArrayNotHasKey('redirect_logout', $result['openid_connect.settings']);
    $this->assertSame('hidden', $result['openid_connect.settings']['user_login_display']);
    $this->assertSame($configs['openid_connect.client.scs_sso'], $result['openid_connect.client.scs_sso']);
  }

  /**
   * Drush outputs that carry no usable payload.
   */
  public static function invalidPayloadProvider(): array {
    return [
      'no marker' => ['PHP Fatal error: something failed'],
      'empty payload' => ['SCS_INSTANCE_CONFIG:'],
      'not json' => ['SCS_INSTANCE_CONFIG:' . base64_encode('not json')],
      'scalar json' => ['SCS_INSTANCE_CONFIG:' . base64_encode('42')],
    ];
  }

}
