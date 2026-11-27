<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\ViewBuilder;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Service\SodaScsWisskiAdminTasks;
use Drupal\soda_scs_manager\ViewBuilder\SodaScsComponentViewBuilder;
use Drupal\soda_scs_manager\ViewBuilder\SodaScsStackViewBuilder;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the access data on the detail pages of SQL and triplestore components.
 *
 * The SQL page shows the database user and password, the triplestore page the
 * public read flag, the SPARQL URLs, user, password and token. The stack page
 * links to the detail page of exactly these two component kinds.
 */
#[CoversMethod(SodaScsComponentViewBuilder::class, 'buildDatabaseCredentials')]
#[CoversMethod(SodaScsComponentViewBuilder::class, 'getServiceKeyValues')]
#[CoversMethod(SodaScsComponentViewBuilder::class, 'buildUsernameItem')]
#[CoversMethod(SodaScsComponentViewBuilder::class, 'build')]
#[CoversMethod(SodaScsComponentViewBuilder::class, 'buildTriplestoreCredentials')]
#[CoversMethod(SodaScsStackViewBuilder::class, 'build')]
#[Group('soda_scs_manager')]
class SodaScsCredentialsViewTest extends SodaScsKernelTestBase {

  /**
   * Component bundle of SQL databases.
   */
  private const SQL_BUNDLE = 'soda_scs_sql_component';

  /**
   * Component bundle of triplestores.
   */
  private const TRIPLESTORE_BUNDLE = 'soda_scs_triplestore_component';

  /**
   * Placeholder shown for a missing value.
   */
  private const NOT_SET = '(not set)';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The admin tasks would ask the live container.
    $adminTasks = $this->createMock(SodaScsWisskiAdminTasks::class);
    $adminTasks->method('build')->willReturn([]);
    $this->container->set('soda_scs_manager.wisski_admin_tasks', $adminTasks);
    // The health badge link needs the service settings, which are not the
    // subject here.
    $helpers = $this->createMock(SodaScsServiceHelpers::class);
    $helpers->method('getComponentServiceAndLoginUrls')->willReturn(NULL);
    $helpers->method('getStackServiceAndLoginUrls')->willReturn(NULL);
    $this->container->set('soda_scs_manager.service.helpers', $helpers);

    $this->config('soda_scs_manager.settings')
      ->set('triplestore.generalSettings.host', 'https://triplestore.example.com/')
      ->save(TRUE);
  }

  /**
   * The SQL page shows the owner's display name as database user.
   */
  public function testSqlPageShowsOwnerNameAsUser(): void {
    $owner = $this->createTestUser(['name' => 'sql_owner']);
    $component = $this->createTestComponent(self::SQL_BUNDLE, ['owner' => $owner->id()]);

    $credentials = $this->buildComponent($component)['databaseCredentials'];

    $this->assertSame('Database access', (string) $credentials['#title']);
    $this->assertStringContainsString('>sql_owner</span>', (string) $credentials['username']['#markup']);
    $this->assertStringContainsString('scs-manager--database--username', (string) $credentials['username']['#markup']);
  }

  /**
   * The SQL password comes from the service key and sits in the hidden span.
   */
  public function testSqlPageShowsServicePasswordInClickToViewSpan(): void {
    $key = $this->createTestServiceKey('default', ['servicePassword' => 'sql-secret-1']);
    $component = $this->createTestComponent(self::SQL_BUNDLE, ['serviceKey' => [$key->id()]]);

    $password = $this->buildComponent($component)['databaseCredentials']['password']['#markup'];

    $this->assertStringContainsString('soda-scs-manager--service-password', (string) $password);
    $this->assertStringContainsString('>sql-secret-1</span>', (string) $password);
  }

  /**
   * Without a service key the SQL page shows the not-set placeholder.
   */
  public function testSqlPageShowsNotSetWithoutServiceKey(): void {
    $component = $this->createTestComponent(self::SQL_BUNDLE);

    $password = $this->buildComponent($component)['databaseCredentials']['password']['#markup'];

    $this->assertStringContainsString('>' . self::NOT_SET . '</span>', (string) $password);
  }

  /**
   * A service key with an empty password shows the placeholder, not a blank.
   */
  public function testSqlPageShowsNotSetForEmptyPassword(): void {
    $key = $this->createTestServiceKey('default', ['servicePassword' => '']);
    $component = $this->createTestComponent(self::SQL_BUNDLE, ['serviceKey' => [$key->id()]]);

    $password = $this->buildComponent($component)['databaseCredentials']['password']['#markup'];

    $this->assertStringContainsString('>' . self::NOT_SET . '</span>', (string) $password);
  }

  /**
   * HTML in the password and the user name is escaped.
   */
  public function testSqlPageEscapesPasswordAndUserName(): void {
    $owner = $this->createTestUser(['name' => 'o<b>x']);
    $key = $this->createTestServiceKey('default', ['servicePassword' => '<script>a&b</script>']);
    $component = $this->createTestComponent(self::SQL_BUNDLE, [
      'owner' => $owner->id(),
      'serviceKey' => [$key->id()],
    ]);

    $credentials = $this->buildComponent($component)['databaseCredentials'];

    $this->assertStringNotContainsString('<script>', (string) $credentials['password']['#markup']);
    $this->assertStringContainsString('&lt;script&gt;a&amp;b&lt;/script&gt;', (string) $credentials['password']['#markup']);
    $this->assertStringNotContainsString('<b>', (string) $credentials['username']['#markup']);
  }

  /**
   * The SQL password of a token-typed key is not taken as the password.
   */
  public function testSqlPageIgnoresServiceKeyOfOtherType(): void {
    $key = $this->createTestServiceKey('default', [
      'servicePassword' => 'a-token-value',
      'type' => 'token',
    ]);
    $component = $this->createTestComponent(self::SQL_BUNDLE, ['serviceKey' => [$key->id()]]);

    $password = $this->buildComponent($component)['databaseCredentials']['password']['#markup'];

    $this->assertStringNotContainsString('a-token-value', (string) $password);
    $this->assertStringContainsString(self::NOT_SET, (string) $password);
  }

  /**
   * The SQL page prints the database card with the stored values.
   */
  public function testSqlPageRendersDatabaseAccessCard(): void {
    $owner = $this->createTestUser(['name' => 'sql_render_owner']);
    $key = $this->createTestServiceKey('default', ['servicePassword' => 'sql-render-pw']);
    $component = $this->createTestComponent(self::SQL_BUNDLE, [
      'owner' => $owner->id(),
      'serviceKey' => [$key->id()],
    ]);

    $html = $this->renderComponent($component);

    $this->assertStringContainsString('application__details-card--database-access', $html);
    $this->assertStringContainsString('Database access', $html);
    $this->assertStringContainsString('sql_render_owner', $html);
    $this->assertStringContainsString('sql-render-pw', $html);
    $this->assertStringNotContainsString('application__details-card--endpoint-access', $html);
  }

  /**
   * The triplestore page prints the endpoint card with every row.
   */
  public function testTriplestorePageRendersEndpointAccessCard(): void {
    $owner = $this->createTestUser(['name' => 'ts_render_owner']);
    $password = $this->createTestServiceKey('default', [
      'servicePassword' => 'ts-render-pw',
      'scsComponentBundle' => self::TRIPLESTORE_BUNDLE,
    ]);
    $token = $this->createTestServiceKey('default', [
      'servicePassword' => 'ts-render-token',
      'scsComponentBundle' => self::TRIPLESTORE_BUNDLE,
      'type' => 'token',
    ]);
    $component = $this->createTestComponent(self::TRIPLESTORE_BUNDLE, [
      'owner' => $owner->id(),
      'machineName' => 'ts-repo',
      'publicRead' => TRUE,
      'serviceKey' => [$password->id(), $token->id()],
    ]);

    $html = $this->renderComponent($component);

    $this->assertStringContainsString('application__details-card--endpoint-access', $html);
    $this->assertStringContainsString('Endpoint access', $html);
    $this->assertStringContainsString('https://triplestore.example.com/repositories/ts-repo', $html);
    $this->assertStringContainsString('https://triplestore.example.com/repositories/ts-repo/statements', $html);
    $this->assertStringContainsString('ts_render_owner', $html);
    $this->assertStringContainsString('ts-render-pw', $html);
    $this->assertStringContainsString('ts-render-token', $html);
    $this->assertStringNotContainsString('application__details-card--database-access', $html);
  }

  /**
   * The triplestore block has the owner's display name as user row.
   */
  public function testTriplestoreBlockHasOwnerNameAsUser(): void {
    $owner = $this->createTestUser(['name' => 'ts_owner']);
    $component = $this->createTestComponent(self::TRIPLESTORE_BUNDLE, ['owner' => $owner->id()]);

    $username = $this->buildComponent($component)['triplestoreCredentials']['username'];

    $this->assertSame('User', (string) $username['#title']);
    $this->assertStringContainsString('>ts_owner</span>', (string) $username['#markup']);
    $this->assertStringContainsString('scs-manager--triplestore--username', (string) $username['#markup']);
  }

  /**
   * Public read is a disabled, ticked checkbox when the store is public.
   */
  public function testTriplestorePublicReadCheckboxIsCheckedWhenPublic(): void {
    $component = $this->createTestComponent(self::TRIPLESTORE_BUNDLE, ['publicRead' => TRUE]);

    $attributes = $this->buildComponent($component)['triplestoreCredentials']['publicRead']['checkbox']['#attributes'];

    $this->assertTrue($attributes['checked']);
    $this->assertSame('disabled', $attributes['disabled']);
    $this->assertSame('checkbox', $attributes['type']);
  }

  /**
   * Public read is an unticked checkbox when the store is not public.
   */
  public function testTriplestorePublicReadCheckboxIsUncheckedWhenNotPublic(): void {
    $component = $this->createTestComponent(self::TRIPLESTORE_BUNDLE, ['publicRead' => FALSE]);

    $attributes = $this->buildComponent($component)['triplestoreCredentials']['publicRead']['checkbox']['#attributes'];

    $this->assertFalse($attributes['checked']);
    $this->assertSame('disabled', $attributes['disabled']);
  }

  /**
   * Public read is unticked when the flag was never set.
   */
  public function testTriplestorePublicReadCheckboxIsUncheckedWhenUnset(): void {
    $component = $this->createTestComponent(self::TRIPLESTORE_BUNDLE);
    $component->set('publicRead', NULL);

    $attributes = $this->buildComponent($component)['triplestoreCredentials']['publicRead']['checkbox']['#attributes'];

    $this->assertFalse($attributes['checked']);
  }

  /**
   * The rendered checkbox carries the checked attribute only when public.
   */
  public function testTriplestoreRenderedCheckboxCheckedOnlyWhenPublic(): void {
    $public = $this->renderComponent($this->createTestComponent(self::TRIPLESTORE_BUNDLE, ['publicRead' => TRUE]));
    $private = $this->renderComponent($this->createTestComponent(self::TRIPLESTORE_BUNDLE, ['publicRead' => FALSE]));

    $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*checked/', $public);
    $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="checkbox"[^>]*checked/', $private);
    $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*disabled/', $private);
  }

  /**
   * Password and token are read by the type of the service key.
   */
  public function testTriplestoreReadsPasswordAndTokenByKeyType(): void {
    $password = $this->createTestServiceKey('default', ['servicePassword' => 'pw-value']);
    $token = $this->createTestServiceKey('default', [
      'servicePassword' => 'token-value',
      'type' => 'token',
    ]);
    $component = $this->createTestComponent(self::TRIPLESTORE_BUNDLE, [
      'serviceKey' => [$token->id(), $password->id()],
    ]);

    $credentials = $this->buildComponent($component)['triplestoreCredentials'];

    $this->assertStringContainsString('>pw-value</span>', (string) $credentials['password']['#markup']);
    $this->assertStringContainsString('>token-value</span>', (string) $credentials['token']['#markup']);
  }

  /**
   * Without service keys the triplestore shows the placeholder twice.
   */
  public function testTriplestoreShowsNotSetWithoutServiceKeys(): void {
    $component = $this->createTestComponent(self::TRIPLESTORE_BUNDLE);

    $credentials = $this->buildComponent($component)['triplestoreCredentials'];

    $this->assertStringContainsString(self::NOT_SET, (string) $credentials['password']['#markup']);
    $this->assertStringContainsString(self::NOT_SET, (string) $credentials['token']['#markup']);
  }

  /**
   * Without a configured host the SPARQL URLs stay empty.
   */
  public function testTriplestoreUrlsAreEmptyWithoutHost(): void {
    $this->config('soda_scs_manager.settings')
      ->set('triplestore.generalSettings.host', '')
      ->save(TRUE);
    $component = $this->createTestComponent(self::TRIPLESTORE_BUNDLE, ['machineName' => 'ts-nohost']);

    $html = $this->renderComponent($component);

    $this->assertStringNotContainsString('/repositories/ts-nohost', $html);
  }

  /**
   * Other component kinds get neither credentials block.
   */
  public function testOtherBundlesGetNoCredentialsBlock(): void {
    $component = $this->createTestComponent('soda_scs_wisski_component');

    $html = $this->renderComponent($component);
    $build = $this->buildComponent($component);

    $this->assertArrayNotHasKey('databaseCredentials', $build);
    $this->assertArrayNotHasKey('triplestoreCredentials', $build);
    $this->assertStringNotContainsString('application__details-card--database-access', $html);
    $this->assertStringNotContainsString('application__details-card--endpoint-access', $html);
  }

  /**
   * The stack page links the SQL and triplestore cards to their detail page.
   */
  public function testStackPageShowsDetailsLinkForSqlAndTriplestoreCards(): void {
    $sql = $this->createTestComponent(self::SQL_BUNDLE);
    $triplestore = $this->createTestComponent(self::TRIPLESTORE_BUNDLE);

    $flags = $this->detailsLinkFlagsOfStackPage([$sql, $triplestore]);

    $this->assertSame([
      (string) $sql->id() => TRUE,
      (string) $triplestore->id() => TRUE,
    ], $flags);
  }

  /**
   * The stack page keeps the Details button hidden on the other cards.
   */
  public function testStackPageHidesDetailsLinkForOtherCards(): void {
    $wisski = $this->createTestComponent('soda_scs_wisski_component');
    $webprotege = $this->createTestComponent('soda_scs_webprotege_component');

    $flags = $this->detailsLinkFlagsOfStackPage([$wisski, $webprotege]);

    $this->assertSame([
      (string) $wisski->id() => FALSE,
      (string) $webprotege->id() => FALSE,
    ], $flags);
  }

  /**
   * A viewer who is not the owner gets no credentials in the render array.
   */
  #[DataProvider('credentialBundleProvider')]
  public function testNonOwnerGetsNoCredentials(string $bundle, string $key): void {
    $owner = $this->createTestUser(['name' => 'the_owner']);
    $secret = $this->createTestServiceKey('default', ['servicePassword' => 'owner-only-secret']);
    $component = $this->createTestComponent($bundle, [
      'owner' => $owner->id(),
      'serviceKey' => [$secret->id()],
    ]);
    $this->container->get('current_user')->setAccount($this->createTestUser(['name' => 'someone_else']));

    $viewBuilder = $this->entityTypeManager->getViewBuilder('soda_scs_component');
    $build = $viewBuilder->build($viewBuilder->view($component));
    $page = $viewBuilder->view($component);
    $html = (string) $this->container->get('renderer')->renderInIsolation($page);

    $this->assertArrayNotHasKey($key, $build);
    $this->assertStringNotContainsString('owner-only-secret', $html);
    $this->assertStringNotContainsString('the_owner', $html);
  }

  /**
   * Bundles with a credentials block and the render key of the block.
   *
   * @return array<string, array{string, string}>
   *   Bundle and key.
   */
  public static function credentialBundleProvider(): array {
    return [
      'sql' => [self::SQL_BUNDLE, 'databaseCredentials'],
      'triplestore' => [self::TRIPLESTORE_BUNDLE, 'triplestoreCredentials'],
    ];
  }

  /**
   * Builds the component's render array as the view builder produces it.
   *
   * @return array<string, mixed>
   *   The built render array.
   */
  private function buildComponent(SodaScsComponentInterface $component): array {
    $this->container->get('current_user')->setAccount($component->getOwner());
    $viewBuilder = $this->entityTypeManager->getViewBuilder('soda_scs_component');
    return $viewBuilder->build($viewBuilder->view($component));
  }

  /**
   * Renders the component page.
   */
  private function renderComponent(SodaScsComponentInterface $component): string {
    $this->container->get('current_user')->setAccount($component->getOwner());
    $viewBuilder = $this->entityTypeManager->getViewBuilder('soda_scs_component');
    $build = $viewBuilder->view($component);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Builds a stack page and tells per component whether its card has Details.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[] $components
   *   The components included in the stack.
   *
   * @return array<string, bool>
   *   Whether the card has a details link, keyed by component ID.
   */
  private function detailsLinkFlagsOfStackPage(array $components): array {
    $stack = $this->createTestStack('soda_scs_wisski_stack', [
      'includedComponents' => array_map(static fn($component) => $component->id(), $components),
    ]);

    $viewBuilder = $this->entityTypeManager->getViewBuilder('soda_scs_stack');
    $build = $viewBuilder->build($viewBuilder->view($stack));

    $flags = [];
    foreach ($components as $index => $component) {
      $flags[(string) $component->id()] = !empty($build['includedComponentCards'][$index]['#details_link']);
    }
    return $flags;
  }

}
