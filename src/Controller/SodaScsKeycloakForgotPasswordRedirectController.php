<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Password reset guidance for Keycloak when OpenID Connect SSO is enabled.
 *
 * When SSO is off, route user.pass stays Drupal's native UserPasswordForm.
 */
final class SodaScsKeycloakForgotPasswordRedirectController extends ControllerBase {

  public function __construct(
    protected SodaScsServiceHelpers $serviceHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.service.helpers'),
    );
  }

  /**
   * Page explaining that password reset is done via Keycloak, not Drupal.
   *
   * @return array
   *   A render array.
   */
  public function instructions(): array {

    $resetUrl = $this->buildKeycloakResetCredentialsUrl();
    if ($resetUrl !== '') {
      $build['direct'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mt-6']],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t(
          'This site uses SSO for sign-in. Go to Keycloak and request a new password using the <strong>Forgot password?</strong> option on the Keycloak login page. Keycloak will email you instructions.'
          ),
        ],
        'reset_link' => Link::fromTextAndUrl(
          $this->t('Open Keycloak password reset'),
          Url::fromUri($resetUrl, ['external' => TRUE]),
        )->toRenderable(),
      ];
      $build['direct']['reset_link']['#attributes']['class'][] = 'button';
    }

    $settings = $this->config('soda_scs_manager.settings');
    $tags = $settings->getCacheTags();
    $machineNamePath = 'keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientMachineName';
    $clientMachineName = $settings->get($machineNamePath);
    if (is_string($clientMachineName) && $clientMachineName !== '') {
      $oidc = $this->config('openid_connect.client.' . $clientMachineName);
      if (!$oidc->isNew()) {
        $tags = Cache::mergeTags($tags, $oidc->getCacheTags());
      }
    }

    $build['#cache'] = [
      'contexts' => ['languages:language_interface'],
      'tags' => $tags,
    ];

    return $build;
  }

  /**
   * Builds the Keycloak reset-credentials URL used for an optional direct link.
   */
  private function buildKeycloakResetCredentialsUrl(): string {
    $general = $this->serviceHelpers->initKeycloakGeneralSettings();
    $base = rtrim($general['url'] ?? '', '/');
    $realm = $general['realm'] ?? '';
    if ($base === '' || $realm === '') {
      return '';
    }
    $path = '/realms/' . rawurlencode($realm) . '/login-actions/reset-credentials';
    $query = [];
    $settings = $this->config('soda_scs_manager.settings');
    $machineNamePath = 'keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientMachineName';
    $clientMachineName = $settings->get($machineNamePath);
    if (is_string($clientMachineName) && $clientMachineName !== '') {
      $oidc = $this->config('openid_connect.client.' . $clientMachineName);
      if (!$oidc->isNew()) {
        $pluginSettings = $oidc->get('settings');
        $clientId = is_array($pluginSettings) ? ($pluginSettings['client_id'] ?? '') : '';
        $clientId = is_string($clientId) ? trim($clientId) : '';
        if ($clientId !== '' && !preg_match('#\Ahttps?://#i', $clientId)) {
          $query['client_id'] = $clientId;
        }
      }
    }
    return $base . $path . ($query !== [] ? '?' . http_build_query($query) : '');
  }

}
