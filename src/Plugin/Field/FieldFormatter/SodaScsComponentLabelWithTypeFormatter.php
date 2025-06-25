<?php

namespace Drupal\soda_scs_manager\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'soda_scs_component_label_with_type' formatter.
 */
#[FieldFormatter(
  id: 'soda_scs_component_label_with_type',
  label: new TranslatableMarkup('Label with type'),
  description: new TranslatableMarkup('Display the label and type of the referenced component.'),
  field_types: [
    'entity_reference',
  ],
)]
class SodaScsComponentLabelWithTypeFormatter extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    $output_as_link = $this->getSetting('link');

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      if (!isset($elements[$delta])) {
        continue;
      }

      // Only process SodaScsComponent entities
      if ($entity->getEntityTypeId() == 'soda_scs_component') {
        // Get the bundle/type of the component
        $bundle = $entity->bundle();
        $bundle_label = \Drupal::service('entity_type.bundle.info')
          ->getBundleInfo('soda_scs_component')[$bundle]['label'] ?? $bundle;

        $label = $entity->label();
        $display_text = $label . ' (' . $bundle_label . ')';

        if ($output_as_link && isset($elements[$delta]['#type']) && $elements[$delta]['#type'] === 'link') {
          $elements[$delta]['#title'] = $display_text;
        }
        else {
          $elements[$delta]['#plain_text'] = $display_text;
        }
      }
    }

    return $elements;
  }

}
