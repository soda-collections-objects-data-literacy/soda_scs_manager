<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests WebProtégé username sanitization and project URL building.
 */
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'sanitizeUserName')]
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'buildProjectHashUrl')]
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'buildProjectLoginUrl')]
#[CoversMethod(SodaScsWebprotegeHelpers::class, 'extractProjectId')]
#[Group('soda_scs_manager')]
class SodaScsWebprotegeHelpersTest extends UnitTestCase {

  /**
   * Tests that sanitizeUserName() lowercases and replaces invalid characters.
   */
  public function testSanitizeUserNameLowercasesAndReplacesInvalidCharacters(): void {
    $this->assertSame('alice_example.com', SodaScsWebprotegeHelpers::sanitizeUserName('Alice@Example.com'));
    $this->assertSame('user-name_1', SodaScsWebprotegeHelpers::sanitizeUserName(' User-Name_1 '));
    $this->assertSame('', SodaScsWebprotegeHelpers::sanitizeUserName('   '));
  }

  /**
   * Tests that buildProjectHashUrl() uses classes perspective.
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
   * Tests that buildProjectHashUrl() falls back to project list.
   */
  public function testBuildProjectHashUrlFallsBackToProjectList(): void {
    $url = SodaScsWebprotegeHelpers::buildProjectHashUrl('webprotege.example.com', NULL);
    $this->assertSame(
      'https://webprotege.example.com/WebProtege.jsp#projects/list',
      $url,
    );
  }

  /**
   * Tests that buildProjectLoginUrl() passes project place as return to.
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
   * Tests that buildProjectLoginUrl() falls back to project list.
   */
  public function testBuildProjectLoginUrlFallsBackToProjectList(): void {
    $this->assertSame(
      'https://webprotege.example.com/webprotege/oidc/login?return_to=' . rawurlencode('projects/list'),
      SodaScsWebprotegeHelpers::buildProjectLoginUrl('https://webprotege.example.com', 'not-a-uuid'),
    );
  }

  /**
   * Tests that extractProjectId() from create payload and location.
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
