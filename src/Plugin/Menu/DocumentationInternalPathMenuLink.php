<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\Menu;

use Drupal\Core\Menu\MenuLinkDefault;
use Drupal\Core\Url;

/**
 * Menu link href for the documentation book root.
 *
 * Always uses the path /soda-scs-manager/documentation (language prefix added
 * for the current interface language). Does not use entity.node.canonical URL
 * generation, so a stale path alias such as /docs for German cannot produce
 * /de/docs in the menu.
 *
 * Route name/parameters still come from discovery (entity.node.canonical when
 * the book exists) so the active trail matches on the node page.
 */
final class DocumentationInternalPathMenuLink extends MenuLinkDefault {

  /**
   * {@inheritdoc}
   */
  public function getUrlObject($title_attribute = TRUE) {
    $language = \Drupal::languageManager()->getCurrentLanguage();
    $prefixes = \Drupal::config('language.negotiation')->get('url.prefixes') ?: [];
    $prefix_segment = $prefixes[$language->getId()] ?? '';
    $path = '/soda-scs-manager/documentation';
    if ($prefix_segment !== '' && $prefix_segment !== NULL) {
      $path = '/' . $prefix_segment . $path;
    }
    $options = [
      'language' => $language,
      'path_processing' => FALSE,
    ];
    if ($title_attribute) {
      $description = $this->getDescription();
      if ((string) $description !== '') {
        $options['attributes']['title'] = $description;
      }
    }
    return Url::fromUserInput($path, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return array_merge(parent::getCacheContexts(), ['languages:language_interface']);
  }

}
