<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\Menu;

use Drupal\Core\Menu\MenuLinkDefault;
use Drupal\Core\Url;

/**
 * Menu link href for the documentation book root.
 *
 * Uses the internal path soda-scs-manager/documentation with the current
 * interface language so outbound path processors add the correct URL prefix
 * (e.g. /de/...). Avoids baking the prefix into internal: URIs: PathValidator
 * expects the unprefixed system path, and path_processing must stay enabled
 * so LanguageUrlOutboundPathProcessor runs (otherwise links fall back to the
 * default language and show node/N in English).
 *
 * Does not use entity.node.canonical URL generation alone, so a stale per-lang
 * alias cannot replace this fixed path in the menu.
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
    $options = $this->getOptions();
    $options['language'] = $language;
    // Ensure outbound path processors run (language prefix + alias per lang).
    $options['path_processing'] = TRUE;
    if ($title_attribute) {
      $description = $this->getDescription();
      if ((string) $description !== '') {
        $options['attributes']['title'] = $description;
      }
    }
    return Url::fromUri('internal:/soda-scs-manager/documentation', $options);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return array_merge(parent::getCacheContexts(), ['languages:language_interface']);
  }

}
