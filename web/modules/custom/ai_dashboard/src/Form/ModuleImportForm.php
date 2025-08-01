<?php

namespace Drupal\ai_dashboard\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Form handler for the Module import add and edit forms.
 */
class ModuleImportForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $module_import = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#maxlength' => 255,
      '#default_value' => $module_import->label(),
      '#description' => $this->t('Name of the module import configuration.'),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $module_import->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_dashboard\Entity\ModuleImport::load',
      ],
      '#disabled' => !$module_import->isNew(),
    ];

    $form['source_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Source Type'),
      '#description' => $this->t('The API source to import from'),
      '#options' => [
        'drupal_org' => $this->t('Drupal.org'),
        'gitlab' => $this->t('GitLab'),
        'github' => $this->t('GitHub'),
      ],
      '#default_value' => $module_import->getSourceType() ?: 'drupal_org',
      '#required' => TRUE,
    ];

    $form['project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project ID'),
      '#description' => $this->t('The project ID to import from (e.g., 3294612 for AI module on drupal.org)'),
      '#default_value' => $module_import->getProjectId(),
      '#required' => TRUE,
    ];

    $form['project_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project Machine Name'),
      '#description' => $this->t('The project ID to import from (e.g., ai for AI module on drupal.org)'),
      '#default_value' => $module_import->getProjectMachineName(),
      '#required' => TRUE,
    ];

    $form['filter_tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter Tags'),
      '#description' => $this->t('Tags to filter by, comma-separated (e.g., "AIInitiative, AI Core, Provider Integration")'),
      '#default_value' => implode(',', $module_import->getFilterTags()),
      '#required' => FALSE,
    ];

    $form['status_filter'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Status Filter'),
      '#description' => $this->t('Select which issue statuses to import.'),
      '#options' => [
        'all_open' => $this->t('All Open Issues'),
        '1' => $this->t('Active'),
        '8' => $this->t('Needs review'),
        '13' => $this->t('Needs work'),
        '14' => $this->t('Reviewed & tested by the community'),
        '15' => $this->t('Patch (to be ported)'),
        '2' => $this->t('Fixed'),
        '4' => $this->t('Postponed'),
        '16' => $this->t('Postponed (maintainer needs more info)'),
        '3' => $this->t('Closed (fixed)'),
      ],
      '#default_value' => $module_import->getStatusFilter() ?: ['1', '13', '8', '14', '15', '2'],
    ];

    $form['max_issues'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Issues'),
      '#description' => $this->t('Maximum number of issues to import (leave blank for 1000)'),
      '#min' => 1,
      '#max' => 5000,
      '#default_value' => $module_import->getMaxIssues(),
      '#required' => FALSE,
    ];

    // Date filter field
    $date_default = NULL;
    if ($module_import->getDateFilter()) {
      try {
        $date_default = new DrupalDateTime($module_import->getDateFilter());
      }
      catch (\Exception $e) {
        // Invalid date, leave as NULL
      }
    }

    $form['date_filter'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Import From Date'),
      '#description' => $this->t('Only import issues created after this date'),
      '#default_value' => $date_default,
      '#date_time_element' => 'date',
      '#required' => FALSE,
    ];

    $form['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active'),
      '#description' => $this->t('Whether this import configuration is active'),
      '#default_value' => $module_import->isNew() ? TRUE : $module_import->isActive(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $module_import = $this->entity;

    // Process the date filter
    if (!empty($form_state->getValue('date_filter'))) {
      /** @var \Drupal\Core\Datetime\DrupalDateTime $date */
      $date = $form_state->getValue('date_filter');
      if ($date instanceof DrupalDateTime) {
        $module_import->setDateFilter($date->format('Y-m-d'));
      }
    }
    else {
      $module_import->setDateFilter(NULL);
    }

    // Process the status filter to remove unchecked values
    $status_filter = array_filter($form_state->getValue('status_filter', []));
    $module_import->setStatusFilter(array_keys($status_filter))
      ->setProjectMachineName($form_state->getValue('project_name'));
    $module_import->setFilterTags($form_state->getValue('filter_tags'));

    $status = $module_import->save();

    if ($status) {
      $this->messenger()->addMessage($this->t('Saved the %label Module import.', [
        '%label' => $module_import->label(),
      ]));
    }
    else {
      $this->messenger()->addMessage($this->t('The %label Module import was not saved.', [
        '%label' => $module_import->label(),
      ]), 'error');
    }

    $form_state->setRedirectUrl($module_import->toUrl('collection'));
  }

}
