<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager;

use Drupal\views\EntityViewsData;

/**
 * Provides views data for Soda SCS Stack entities.
 */
class SodaScsStackViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    // Base table information
    $data['soda_scs_stack']['table']['group'] = $this->t('Soda SCS Stack');
    $data['soda_scs_stack_field_data']['table']['wizard_id'] = 'soda_scs_stack';

    // Add a custom filter for stacks by machine name
    $data['soda_scs_stack_field_data']['machineName']['filter']['id'] = 'string';
    $data['soda_scs_stack_field_data']['machineName']['filter']['title'] = $this->t('Machine name');
    $data['soda_scs_stack_field_data']['machineName']['filter']['help'] = $this->t('Filter by the stack machine name.');

    // Improve label field display
    $data['soda_scs_stack_field_data']['label']['field']['default_formatter_settings'] = ['link_to_entity' => TRUE];

    // Add a custom area handler for empty stacks
    $data['soda_scs_stack']['soda_scs_stack_empty'] = [
      'title' => $this->t('Empty Stacks behavior'),
      'help' => $this->t('Provides a link to create a new stack.'),
      'area' => [
        'id' => 'soda_scs_stack_empty',
      ],
    ];

    // Add a custom bulk form
    $data['soda_scs_stack']['soda_scs_stack_bulk_form'] = [
      'title' => $this->t('Stack operations bulk form'),
      'help' => $this->t('Add a form element that lets you run operations on multiple stacks.'),
      'field' => [
        'id' => 'bulk_form',
        'entity_type' => 'soda_scs_stack',
      ],
    ];

    // Add date-based arguments for created/updated dates
    $this->addDateBasedArguments($data, 'soda_scs_stack_field_data', 'created');
    $this->addDateBasedArguments($data, 'soda_scs_stack_field_data', 'updated');

    return $data;
  }

  /**
   * Adds date-based arguments for a specific field.
   *
   * @param array &$data
   *   The views data to modify.
   * @param string $table
   *   The table name.
   * @param string $field
   *   The field name.
   */
  protected function addDateBasedArguments(array &$data, $table, $field) {
    $title = $field === 'created' ? $this->t('Created') : $this->t('Updated');

    $data[$table][$field . '_fulldate'] = [
      'title' => $title . ' ' . $this->t('date'),
      'help' => $this->t('Date in the form of CCYYMMDD.'),
      'argument' => [
        'field' => $field,
        'id' => 'date_fulldate',
      ],
    ];

    $data[$table][$field . '_year_month'] = [
      'title' => $title . ' ' . $this->t('year + month'),
      'help' => $this->t('Date in the form of YYYYMM.'),
      'argument' => [
        'field' => $field,
        'id' => 'date_year_month',
      ],
    ];

    $data[$table][$field . '_year'] = [
      'title' => $title . ' ' . $this->t('year'),
      'help' => $this->t('Date in the form of YYYY.'),
      'argument' => [
        'field' => $field,
        'id' => 'date_year',
      ],
    ];

    $data[$table][$field . '_month'] = [
      'title' => $title . ' ' . $this->t('month'),
      'help' => $this->t('Date in the form of MM (01 - 12).'),
      'argument' => [
        'field' => $field,
        'id' => 'date_month',
      ],
    ];
  }

}
