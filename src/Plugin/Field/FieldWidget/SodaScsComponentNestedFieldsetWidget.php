<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Utility\NestedArray;
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
    $componentIds = array_keys($options);
    if (empty($componentIds)) {
      return $element + [
        '#markup' => $this->t('No components available.'),
      ];
    }

    $components = $this->entityTypeManager->getStorage('soda_scs_component')->loadMultiple($componentIds);

    // Group components by owner and then by bundle (component type).
    $groupedComponents = [];
    $bundleInfo = \Drupal::service('entity_type.bundle.info')->getBundleInfo('soda_scs_component');

    foreach ($components as $componentId => $component) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
      $owner = $component->getOwner();
      $ownerName = $owner ? $owner->getDisplayName() : $this->t('Unknown');
      $ownerId = $owner ? $owner->id() : 0;

      $bundle = $component->bundle();
      $bundleLabel = $bundleInfo[$bundle]['label'] ?? $bundle;

      $groupedComponents[$ownerId][$bundle][] = [
        'id' => $componentId,
        'label' => $options[$componentId],
        'owner_name' => $ownerName,
        'bundle_label' => $bundleLabel,
      ];
    }

    // Sort by owner name.
    uksort($groupedComponents, function($a, $b) use ($groupedComponents) {
      if (isset($groupedComponents[$a]) && isset($groupedComponents[$b])) {
        $firstOwnerComponentA = reset($groupedComponents[$a]);
        $firstOwnerComponentB = reset($groupedComponents[$b]);
        $ownerA = reset($firstOwnerComponentA)['owner_name'];
        $ownerB = reset($firstOwnerComponentB)['owner_name'];
        return strcasecmp($ownerA, $ownerB);
      }
      return 0;
    });

    // Build the nested fieldset structure with main fieldset wrapper.
    $element['#type'] = 'fieldset';
    $element['#title'] = $element['#title'] ?? $this->fieldDefinition->getLabel();
    $element['#tree'] = TRUE;
    $element['#attributes']['class'][] = 'soda-scs-nested-main-fieldset';

    // Add own validation handler.
    $element['#element_validate'][] = [static::class, 'validateNestedElement'];
    $element['#soda_scs_multiple'] = $this->multiple;

    // Create a container for the nested content.
    $element['nested_content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['soda-scs-nested-content']],
    ];

    foreach ($groupedComponents as $ownerId => $ownerComponents) {
      $firstOwnerComponent = reset($ownerComponents);
      $ownerName = reset($firstOwnerComponent)['owner_name'];

      $element['nested_content']['owner_' . $ownerId] = [
        '#type' => 'details',
        '#title' => $this->t('Owner: @name', ['@name' => $ownerName]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['soda-scs-nested-owner-fieldset']],
      ];

      // Sort bundles by label.
      uksort($ownerComponents, function($a, $b) use ($ownerComponents) {
        $bundleA = reset($ownerComponents[$a])['bundle_label'];
        $bundleB = reset($ownerComponents[$b])['bundle_label'];
        return strcasecmp($bundleA, $bundleB);
      });

      foreach ($ownerComponents as $bundle => $bundleComponents) {
        $bundleLabel = $bundleComponents[0]['bundle_label'];

        $element['nested_content']['owner_' . $ownerId]['bundle_' . $bundle] = [
          '#type' => 'fieldset',
          '#title' => $bundleLabel,
          '#attributes' => ['class' => ['soda-scs-nested-bundle-fieldset']],
        ];

        // Create options for this bundle.
        $bundleOptions = [];
        foreach ($bundleComponents as $component) {
          $bundleOptions[$component['id']] = $component['label'];
        }

        if ($this->multiple) {
          $element['nested_content']['owner_' . $ownerId]['bundle_' . $bundle]['options'] = [
            '#type' => 'checkboxes',
            '#options' => $bundleOptions,
            '#default_value' => array_intersect($selected, array_keys($bundleOptions)),
            '#attributes' => ['class' => ['soda-scs-nested-options']],
          ];
        }
        else {
          // For single selection, use radios but we need to handle the grouping differently.
          $element['nested_content']['owner_' . $ownerId]['bundle_' . $bundle]['options'] = [
            '#type' => 'radios',
            '#options' => $bundleOptions,
            '#default_value' => $selected ? reset($selected) : NULL,
            '#attributes' => ['class' => ['soda-scs-nested-options']],
          ];
        }
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // Values are already flattened in validateNestedElement().
    return $values;
  }

   /**
   * Custom element validation handler for nested widgets.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateNestedElement(array $element, FormStateInterface $form_state) {
    $selectedIds = [];

    if (!empty($element['nested_content']) && is_array($element['nested_content'])) {
      // Filter $element['nested_content'] for items beginning with 'owner_'.
      $ownerArray = array_filter(
        $element['nested_content'],
        function ($key) {
          return str_starts_with($key, 'owner_');
        },
        ARRAY_FILTER_USE_KEY
      );
      foreach ($ownerArray as $owner) {
        $bundleArray = array_filter(
          $owner,
          function ($key) {
            return str_starts_with($key, 'bundle_soda_scs_');
          },
          ARRAY_FILTER_USE_KEY
        );
        foreach ($bundleArray as $bundle) {
          if (!array_key_exists('options', $bundle)) {
            continue;
          }
          $opts = $bundle['options'];

          // New shape: a single checkbox element where '#value' is either
          // [id => id] when checked, or [] when unchecked. Also supports
          // element arrays with '#value' for radios.
          if (is_array($opts) && array_key_exists('#value', $opts)) {
            $value = $opts['#value'];
            if (is_array($value) && !empty($value)) {
              foreach (array_keys($value) as $id) {
                $selectedIds[] = (string) $id;
              }
            }
            elseif (!is_array($value) && !empty($value) && $value !== '_none') {
              $selectedIds[] = (string) $value;
            }
          }
          // Legacy checkboxes: id => 0|id (truthy when selected).
          elseif (is_array($opts)) {
            foreach ($opts as $id => $checked) {
              if (!empty($checked)) {
                $selectedIds[] = (string) $id;
              }
            }
          }
          // Legacy radios: scalar id or empty.
          elseif (!empty($opts) && $opts !== '_none') {
            $selectedIds[] = (string) $opts;
          }
        }
      }
    }

    // If single selection, keep the first one only.
    $isMultiple = !empty($element['#soda_scs_multiple']);
    if (!$isMultiple && $selectedIds) {
      $selectedIds = [reset($selectedIds)];
    }

    // Build items in the structure expected by WidgetBase::submit().
    $items = [];
    foreach ($selectedIds as $id) {
      $items[] = [$element['#key_column'] => $id];
    }

    // If required, enforce at least one selected.
    if (!empty($element['#required']) && !$items) {
      $form_state->setError($element, t('@name field is required.', ['@name' => $element['#title']]));
    }

    // Set the flattened items as the value of this element.
    $form_state->setValueForElement($element, $items);
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
