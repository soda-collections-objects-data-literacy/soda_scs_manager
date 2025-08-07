<?php

namespace Drupal\soda_scs_manager\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'soda_scs_component_nested_fieldset' widget.
 */
#[FieldWidget(
  id: 'soda_scs_component_nested_fieldset',
  label: new TranslatableMarkup('Nested fieldset (Owner > Component Type > Options)'),
  field_types: [
    'entity_reference',
  ],
  multiple_values: TRUE,
)]
class SodaScsComponentNestedFieldsetWidget extends OptionsWidgetBase implements ContainerFactoryPluginInterface {



  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Get all available options from the base widget.
    $options = $this->getOptions($items->getEntity());
    $selected = $this->getSelectedOptions($items);

    // Load all component entities to group them.
    $component_ids = array_keys($options);
    if (empty($component_ids)) {
      return $element + [
        '#markup' => $this->t('No components available.'),
      ];
    }

    $components = $this->entityTypeManager->getStorage('soda_scs_component')->loadMultiple($component_ids);

    // Group components by owner and then by bundle (component type).
    $grouped_components = [];
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('soda_scs_component');

    foreach ($components as $component_id => $component) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
      $owner = $component->getOwner();
      $owner_name = $owner ? $owner->getDisplayName() : $this->t('Unknown');
      $owner_id = $owner ? $owner->id() : 0;

      $bundle = $component->bundle();
      $bundle_label = $bundle_info[$bundle]['label'] ?? $bundle;

      $grouped_components[$owner_id][$bundle][] = [
        'id' => $component_id,
        'label' => $options[$component_id],
        'owner_name' => $owner_name,
        'bundle_label' => $bundle_label,
      ];
    }

    // Sort by owner name.
    uksort($grouped_components, function($a, $b) use ($grouped_components) {
      if (isset($grouped_components[$a]) && isset($grouped_components[$b])) {
        $owner_a = reset(reset($grouped_components[$a]))['owner_name'];
        $owner_b = reset(reset($grouped_components[$b]))['owner_name'];
        return strcasecmp($owner_a, $owner_b);
      }
      return 0;
    });

    // Build the nested fieldset structure with main fieldset wrapper.
    $element['#type'] = 'fieldset';
    $element['#title'] = $element['#title'] ?? $this->fieldDefinition->getLabel();
    $element['#tree'] = TRUE;
    $element['#attributes']['class'][] = 'soda-scs-nested-main-fieldset';

    // Create a container for the nested content.
    $element['nested_content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['soda-scs-nested-content']],
    ];

        foreach ($grouped_components as $owner_id => $owner_components) {
      $owner_name = reset(reset($owner_components))['owner_name'];

      $element['nested_content']['owner_' . $owner_id] = [
        '#type' => 'details',
        '#title' => $this->t('Owner: @name', ['@name' => $owner_name]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['soda-scs-nested-owner-fieldset']],
      ];

      // Sort bundles by label.
      uksort($owner_components, function($a, $b) use ($owner_components) {
        $bundle_a = reset($owner_components[$a])['bundle_label'];
        $bundle_b = reset($owner_components[$b])['bundle_label'];
        return strcasecmp($bundle_a, $bundle_b);
      });

            foreach ($owner_components as $bundle => $bundle_components) {
        $bundle_label = $bundle_components[0]['bundle_label'];

        $element['nested_content']['owner_' . $owner_id]['bundle_' . $bundle] = [
          '#type' => 'fieldset',
          '#title' => $bundle_label,
          '#attributes' => ['class' => ['soda-scs-nested-bundle-fieldset']],
        ];

        // Create options for this bundle.
        $bundle_options = [];
        foreach ($bundle_components as $component) {
          $bundle_options[$component['id']] = $component['label'];
        }

        if ($this->multiple) {
          $element['nested_content']['owner_' . $owner_id]['bundle_' . $bundle]['options'] = [
            '#type' => 'checkboxes',
            '#options' => $bundle_options,
            '#default_value' => array_intersect($selected, array_keys($bundle_options)),
            '#attributes' => ['class' => ['soda-scs-nested-options']],
          ];
        }
        else {
          // For single selection, use radios but we need to handle the grouping differently.
          $element['nested_content']['owner_' . $owner_id]['bundle_' . $bundle]['options'] = [
            '#type' => 'radios',
            '#options' => $bundle_options,
            '#default_value' => $selected ? reset($selected) : NULL,
            '#attributes' => ['class' => ['soda-scs-nested-options']],
          ];
        }
      }
    }

    // Add a custom value callback to handle the nested structure.
    $element['#value_callback'] = [$this, 'valueCallback'];

    return $element;
  }

  /**
   * Custom value callback to extract values from nested structure.
   */
  public function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if ($input === FALSE) {
      return [];
    }

    $values = [];
    if (is_array($input) && isset($input['nested_content'])) {
      foreach ($input['nested_content'] as $owner_key => $owner_data) {
        if (is_array($owner_data) && strpos($owner_key, 'owner_') === 0) {
          foreach ($owner_data as $bundle_key => $bundle_data) {
            if (is_array($bundle_data) && strpos($bundle_key, 'bundle_') === 0 && isset($bundle_data['options'])) {
              if ($this->multiple) {
                // For checkboxes, filter out FALSE values.
                $selected = array_filter($bundle_data['options']);
                $values = array_merge($values, array_keys($selected));
              }
              else {
                // For radios, add the selected value if any.
                if (!empty($bundle_data['options'])) {
                  $values[] = $bundle_data['options'];
                }
              }
            }
          }
        }
      }
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEmptyLabel() {
    if (!$this->required && !$this->multiple) {
      return $this->t('N/A');
    }
  }

}
