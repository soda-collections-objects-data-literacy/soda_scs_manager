<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Widget for connected components (WissKI + SQL) with add more/remove.
 *
 * Filled rows show component name as text; empty slots show a dropdown to select.
 * Each filled row has a minus button to remove. Options filtered by
 * soda_scs_wisski_component and soda_scs_sql_component.
 */
#[FieldWidget(
  id: 'soda_scs_connected_components_select',
  label: new TranslatableMarkup('Dropdown (add more, remove)'),
  field_types: [
    'entity_reference',
  ],
  multiple_values: FALSE,
)]
class SodaScsConnectedComponentsWidget extends OptionsWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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

    $this->required = $element['#required'];
    $this->multiple = $this->fieldDefinition->getFieldStorageDefinition()->isMultiple();
    $this->has_value = isset($items[$delta]->target_id);

    $value = $items[$delta]->target_id ?? NULL;

    // Already has a value: show component name as text + hidden input for form submission.
    if ($value) {
      $entity = $this->entityTypeManager->getStorage('soda_scs_component')->load($value);
      $is_orphan = !$entity;
      $label = $entity ? (string) $entity->label() : (string) $this->t('Deleted component (#@id)', ['@id' => $value]);

      $element['#type'] = 'container';
      $element['#attributes']['class'][] = 'scs-manager--connected-component-display';
      if ($is_orphan) {
        $element['#attributes']['class'][] = 'scs-manager--connected-component-orphan';
      }
      // Do NOT set #options - the row merges _weight (0) into #value, causing
      // "value 0 is not allowed" when options validation runs. Without #options,
      // performRequiredValidation skips the options check for this element.
      $element['label'] = [
        '#type' => 'markup',
        '#markup' => Html::escape($label),
        '#weight' => 0,
      ];
      $element['target_id'] = [
        '#type' => 'hidden',
        '#value' => $value,
        '#weight' => 1,
      ];
      $element['#element_validate'][] = [static::class, 'validateFilledElement'];
      return $element;
    }

    // Empty slot: show dropdown to select.
    $options = $this->getOptions($items->getEntity());
    $element += [
      '#type' => 'select',
      '#options' => $options,
      '#default_value' => '_none',
      '#key_column' => 'target_id',
      '#empty_value' => '_none',
    ];
    $element['#element_validate'][] = [static::class, 'validateElement'];

    return $element;
  }

  /**
   * Form validation for filled rows (container with hidden target_id).
   */
  public static function validateFilledElement(array $element, FormStateInterface $form_state) {
    $value = $element['target_id']['#value'] ?? NULL;
    $items = $value ? [['target_id' => $value]] : [];
    $form_state->setValueForElement($element, $items);
  }

  /**
   * Form validation handler for widget elements (select/dropdown).
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateElement(array $element, FormStateInterface $form_state) {
    if (!empty($element['#required']) && ($element['#value'] ?? '') === '_none') {
      $form_state->setError($element, new TranslatableMarkup('@name field is required.', ['@name' => $element['#title']]));
    }

    $value = $element['#value'] ?? '_none';
    if ($value === '_none') {
      $value = NULL;
    }

    $items = $value !== NULL ? [['target_id' => $value]] : [];
    $form_state->setValueForElement($element, $items);
  }

  /**
   * {@inheritdoc}
   *
   * Includes existing/orphan target_ids so FormValidator accepts them.
   */
  protected function getOptions(FieldableEntityInterface $entity) {
    $options = parent::getOptions($entity);
    $flat = OptGroup::flattenOptions($options);
    $field_name = $this->fieldDefinition->getName();
    $items = $entity->get($field_name);
    foreach ($items as $item) {
      $tid = $item->target_id ?? NULL;
      if ($tid && !isset($flat[$tid])) {
        $comp = $this->entityTypeManager->getStorage('soda_scs_component')->load($tid);
        $label = $comp ? (string) $comp->label() : (string) $this->t('Deleted component (#@id)', ['@id' => $tid]);
        $options[$tid] = $label;
        $flat[$tid] = $label;
      }
    }
    $this->options = $options;
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEmptyLabel() {
    return $this->t('- Select -');
  }

  /**
   * {@inheritdoc}
   */
  protected function sanitizeLabel(&$label) {
    $label = Html::decodeEntities(strip_tags((string) $label));
  }

  /**
   * {@inheritdoc}
   *
   * Override to fix user_input handling: parent corrupts values when select
   * submits scalars. We remove only the deleted delta and renumber.
   *
   * Removal flow:
   * 1. Here: set field_state['deleted_item'] and update user_input.
   * 2. WidgetBase::form() (on rebuild): calls $items->removeItem($delta) to
   *    remove the entity from the field array.
   */
  public static function deleteSubmit(&$form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    $delta = (int) $button['#delta'];
    $array_parents = array_slice($button['#array_parents'], 0, -4);
    $parent_element = NestedArray::getValue($form, array_merge($array_parents, ['widget']));
    $field_name = $parent_element['#field_name'];
    $parents = $parent_element['#field_parents'];
    $field_state = static::getWidgetState($parents, $field_name, $form_state);

    $user_input = $form_state->getUserInput();
    $field_parents = $parent_element['#parents'];
    $field_input = NestedArray::getValue($user_input, $field_parents, $exists);
    $set_parents = $field_parents;
    // For translatable entities, values may be under langcode: connectedComponents['x-default'][0].
    if ($exists && is_array($field_input) && !isset($field_input[0]) && !array_key_exists(0, $field_input)) {
      $langcode = $form_state->get('langcode') ?? \Drupal::languageManager()->getCurrentLanguage()->getId();
      $field_input = $field_input[$langcode] ?? $field_input['x-default'] ?? [];
      $set_parents = array_merge($field_parents, [$langcode]);
    }
    if ($exists && is_array($field_input)) {
      // Remove only the deleted delta, renumber, preserve other values.
      $field_values = [];
      foreach ($field_input as $key => $input) {
        if (!is_numeric($key)) {
          continue;
        }
        if ((int) $key === $delta) {
          continue;
        }
        $item = is_array($input) ? $input : ['target_id' => $input];
        if (!isset($item['target_id']) && isset($item[0])) {
          $item['target_id'] = $item[0];
        }
        $field_values[] = $item;
      }
      $weight = -1 * max(0, count($field_values) - 1);
      foreach ($field_values as $i => $item) {
        $field_values[$i]['_weight'] = $weight++;
      }
      NestedArray::setValue($user_input, $set_parents, $field_values);
      $form_state->setUserInput($user_input);
    }

    $field_state['deleted_item'] = $delta;
    if ($field_state['items_count'] > 0) {
      $field_state['items_count']--;
    }

    unset($parent_element[$delta]);
    NestedArray::setValue($form, $array_parents, $parent_element);

    static::setWidgetState($parents, $field_name, $form_state, $field_state);
    $form_state->setRebuild();
  }

}
