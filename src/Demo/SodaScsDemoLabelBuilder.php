<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\soda_scs_manager\Helpers\SodaScsMachineNameHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Names of a user's demo project and its applications.
 *
 * Labels follow "<username> Demo" and "<username> Demo-<App>". Machine names
 * are bases that the stack and component actions prefix (stack-, sql-, ts-,
 * wisski-), so they stay within the 30 characters of the stack form.
 * A demo has WissKI, JupyterHub and WebProtégé. The WissKI stack brings its
 * own database and triplestore; those are not separate demo applications.
 */
class SodaScsDemoLabelBuilder {

  /**
   * Application keys of a demo project.
   */
  public const APP_JUPYTER = 'jupyter';
  public const APP_WEBPROTEGE = 'webprotege';
  public const APP_WISSKI = 'wisski';

  /**
   * Label suffix per application, after "<username> ".
   */
  protected const APP_LABEL_SUFFIXES = [
    self::APP_JUPYTER => 'Demo-JupyterHub',
    self::APP_WEBPROTEGE => 'Demo-WebProtégé',
    self::APP_WISSKI => 'Demo-WissKI',
  ];

  /**
   * Machine name suffix per application, after "<username>-".
   */
  protected const APP_MACHINE_NAME_SUFFIXES = [
    self::APP_JUPYTER => 'demo-jupyter',
    self::APP_WEBPROTEGE => 'demo-ontology',
    self::APP_WISSKI => 'demo',
  ];

  /**
   * Maximum length of a stack or component machine name base.
   */
  public const MACHINE_NAME_MAX_LENGTH = 30;

  /**
   * Constructs the builder.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.machine_name.helpers')]
    protected SodaScsMachineNameHelper $machineNameHelper,
  ) {}

  /**
   * Label of a demo application, e.g. "anna Demo-WissKI".
   *
   * @param string $appKey
   *   One of the APP_* constants.
   * @param string $userName
   *   The account name of the owner.
   * @param int $index
   *   1-based position among applications of the same kind.
   */
  public function appLabel(string $appKey, string $userName, int $index = 1): string {
    $label = $userName . ' ' . self::APP_LABEL_SUFFIXES[$appKey];
    return $index > 1 ? $label . ' ' . $index : $label;
  }

  /**
   * Machine name base of a demo application, e.g. "anna-demo-db".
   *
   * @param string $appKey
   *   One of the APP_* constants.
   * @param string $userName
   *   The account name of the owner.
   * @param int $index
   *   1-based position among applications of the same kind.
   * @param int $attempt
   *   1-based attempt; later attempts add a counter to avoid collisions.
   */
  public function appMachineName(string $appKey, string $userName, int $index = 1, int $attempt = 1): string {
    $suffix = self::APP_MACHINE_NAME_SUFFIXES[$appKey];
    if ($index > 1) {
      $suffix .= '-' . $index;
    }
    if ($attempt > 1) {
      $suffix .= '-' . $attempt;
    }
    $userPart = $this->machineNameHelper->fromLabel($userName, self::MACHINE_NAME_MAX_LENGTH - strlen($suffix) - 1);
    if ($userPart === '') {
      $userPart = 'user';
    }
    return $userPart . '-' . $suffix;
  }

  /**
   * Label of the demo project, e.g. "anna Demo".
   */
  public function projectLabel(string $userName): string {
    return $userName . ' Demo';
  }

}
