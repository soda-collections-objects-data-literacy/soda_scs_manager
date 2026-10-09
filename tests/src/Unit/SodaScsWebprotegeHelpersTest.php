<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\Tests\UnitTestCase;

/**
 * Tests WebProtégé username sanitization and project URL building.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers
 */
class SodaScsWebprotegeHelpersTest extends UnitTestCase {

  /**
   * @covers ::sanitizeUserName
   */
  public function testSanitizeUserNameLowercasesAndReplacesInvalidCharacters(): void {
    $this->assertSame('alice_example.com', SodaScsWebprotegeHelpers::sanitizeUserName('Alice@Example.com'));
    $this->assertSame('user-name_1', SodaScsWebprotegeHelpers::sanitizeUserName(' User-Name_1 '));
    $this->assertSame('', SodaScsWebprotegeHelpers::sanitizeUserName('   '));
  }

  /**
   * @covers ::buildProjectHashUrl
   */
  public function testBuildProjectHashUrlUsesClassesPerspective(): void {
    $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $url = SodaScsWebprotegeHelpers::buildProjectHashUrl(
      'https://webprotege.example.com',
      $uuid,
    );
    $this->assertSame(
      'https://webprotege.example.com/WebProtege.jsp#projects/' . $uuid . '/perspectives/' . SodaScsWebprotegeHelpers::CLASSES_PERSPECTIVE_ID,
      $url,
    );
  }

  /**
   * @covers ::buildProjectHashUrl
   */
  public function testBuildProjectHashUrlFallsBackToProjectList(): void {
    $url = SodaScsWebprotegeHelpers::buildProjectHashUrl('webprotege.example.com', NULL);
    $this->assertSame(
      'https://webprotege.example.com/WebProtege.jsp#projects/list',
      $url,
    );
  }

  /**
   * @covers ::buildProjectLoginUrl
   */
  public function testBuildProjectLoginUrlPassesProjectPlaceAsReturnTo(): void {
    $uuid = 'AAAAAAAA-bbbb-cccc-dddd-eeeeeeeeeeee';
    $url = SodaScsWebprotegeHelpers::buildProjectLoginUrl('webprotege.example.com/', $uuid);
    $this->assertSame(
      'https://webprotege.example.com/webprotege/oidc/login?return_to=' . rawurlencode('projects/' . strtolower($uuid) . '/perspectives/' . SodaScsWebprotegeHelpers::CLASSES_PERSPECTIVE_ID),
      $url,
    );
  }

  /**
   * @covers ::buildProjectLoginUrl
   */
  public function testBuildProjectLoginUrlFallsBackToProjectList(): void {
    $this->assertSame(
      'https://webprotege.example.com/webprotege/oidc/login?return_to=' . rawurlencode('projects/list'),
      SodaScsWebprotegeHelpers::buildProjectLoginUrl('https://webprotege.example.com', 'not-a-uuid'),
    );
  }

  /**
   * @covers ::extractProjectId
   */
  public function testExtractProjectIdFromCreatePayloadAndLocation(): void {
    $uuid = '11111111-2222-3333-4444-555555555555';
    $this->assertSame($uuid, SodaScsWebprotegeHelpers::extractProjectId(['_id' => $uuid]));
    $this->assertSame(
      $uuid,
      SodaScsWebprotegeHelpers::extractProjectId(
        [],
        'https://webprotege.example.com/data/projects/' . $uuid,
      ),
    );
    $this->assertNull(SodaScsWebprotegeHelpers::extractProjectId(['displayName' => 'Nope']));
  }

}
