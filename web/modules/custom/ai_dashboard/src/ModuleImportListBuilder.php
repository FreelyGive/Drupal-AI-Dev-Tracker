<?php

namespace Drupal\ai_dashboard;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a listing of Module import entities.
 */
class ModuleImportListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['select'] = [
      'data' => $this->t('Select'),
      'class' => ['select-all'],
    ];
    $header['label'] = $this->t('Name');
    $header['source_type'] = $this->t('Source Type');
    $header['machine_name'] = $this->t('Module Machine Name');
    $header['filter_tags'] = $this->t('Label Filter');
    $header['filter_component'] = $this->t('Component Filter');
    $header['last_run'] = $this->t('Last Run');
    $header['status'] = $this->t('Status');
    $header['operations'] = $this->t('Operations');
    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ai_dashboard\Entity\ModuleImport $entity */
    
    // Add checkbox for bulk operations.
    $row['select'] = [
      'data' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Select @title', ['@title' => $entity->label()]),
        '#title_display' => 'invisible',
        '#return_value' => $entity->id(),
        '#attributes' => ['class' => ['bulk-select']],
      ],
    ];
    
    $row['label'] = $entity->label();

    // Get source type label.
    $source_types = [
      'drupal_org' => $this->t('Drupal.org'),
      'gitlab' => $this->t('GitLab'),
      'github' => $this->t('GitHub'),
    ];
    $source_type = $entity->getSourceType();
    $row['source_type'] = $source_types[$source_type] ?? $source_type;

    // Show machine name as link to drupal.org project page.
    $machine_name = $entity->getProjectMachineName();
    if ($machine_name && $source_type === 'drupal_org') {
      $row['machine_name'] = [
        'data' => [
          '#type' => 'link',
          '#title' => $machine_name,
          '#url' => Url::fromUri("https://www.drupal.org/project/{$machine_name}"),
          '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ],
      ];
    } else {
      $row['machine_name'] = $machine_name ?: $this->t('N/A');
    }

    // Show filter tags (label filter).
    $filter_tags = $entity->getFilterTags();
    if (!empty($filter_tags)) {
      $row['filter_tags'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => '<span class="filter-tags-value">' . implode(', ', $filter_tags) . '</span>',
        ],
      ];
    } else {
      $row['filter_tags'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => '<span class="filter-tags-none">' . $this->t('None') . '</span>',
        ],
      ];
    }

    // Show component filter with styling.
    $component_filter = $entity->getFilterComponent();
    if ($component_filter) {
      $row['filter_component'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => '<span class="component-filter-value">' . $this->t('@component', ['@component' => $component_filter]) . '</span>',
        ],
      ];
    } else {
      $row['filter_component'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => '<span class="component-filter-none">' . $this->t('None') . '</span>',
        ],
      ];
    }

    // Show last run timestamp from State API (where drush command stores it).
    $last_run = \Drupal::state()->get('ai_dashboard:last_import:' . $entity->id());
    if ($last_run) {
      $last_run_date = \Drupal::service('date.formatter')->format($last_run, 'medium');
      $row['last_run'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => '<span class="last-run-date" title="' . \Drupal::service('date.formatter')->format($last_run, 'long') . '">' . $last_run_date . '</span>',
        ],
      ];
    } else {
      $row['last_run'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => '<span class="last-run-never">' . $this->t('Never') . '</span>',
        ],
      ];
    }

    // Show status with styling.
    if ($entity->isActive()) {
      $row['status'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => '<span class="status-active">' . $this->t('Active') . '</span>',
        ],
      ];
    } else {
      $row['status'] = [
        'data' => [
          '#type' => 'markup',
          '#markup' => '<span class="status-inactive">' . $this->t('Inactive') . '</span>',
        ],
      ];
    }

    // Add direct operations buttons (Edit and Run).
    $operations = [];
    $operations['edit'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit'),
      '#url' => $entity->toUrl('edit-form'),
      '#attributes' => [
        'class' => ['btn', 'btn-sm', 'btn-outline-primary'],
        'style' => 'margin-right: 0.5rem;',
      ],
    ];
    
    $operations['run'] = [
      '#type' => 'link', 
      '#title' => $this->t('Run'),
      '#url' => \Drupal\Core\Url::fromRoute('ai_dashboard.module_import.run', ['module_import' => $entity->id()]),
      '#attributes' => [
        'class' => ['btn', 'btn-sm', 'btn-success'],
        'onclick' => 'return confirm("' . $this->t('Are you sure you want to run this import?') . '")',
      ],
    ];

    $row['operations'] = [
      'data' => $operations,
    ];

    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);

    // Add a run import operation.
    $operations['run'] = [
      'title' => $this->t('Run import'),
      'weight' => 10,
      'url' => Url::fromRoute('ai_dashboard.module_import.run', ['module_import' => $entity->id()]),
    ];

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    
    // Get statistics for display.
    $storage = $this->getStorage();
    $total_configs = $storage->getQuery()->count()->execute();
    $active_configs = $storage->getQuery()->condition('active', TRUE)->count()->execute();
    $inactive_configs = $total_configs - $active_configs;
    
    // Add statistics summary.
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['import-configs-summary']],
      '#weight' => -10,
    ];
    
    $build['summary']['stats'] = [
      '#type' => 'markup',
      '#markup' => '<div class="config-stats">' .
        '<span class="stat-item"><strong>' . $total_configs . '</strong> Total Configurations</span>' .
        '<span class="stat-item"><strong>' . $active_configs . '</strong> Active</span>' .
        '<span class="stat-item"><strong>' . $inactive_configs . '</strong> Inactive</span>' .
        '</div>',
    ];
    
    // Wrap the entire content in a form to handle bulk operations.
    $form_build = \Drupal::formBuilder()->getForm('Drupal\ai_dashboard\Form\ModuleImportBulkForm');
    
    // Add the summary and table to the form.
    $form_build['summary'] = $build['summary'];
    $form_build['table'] = $build['table'];
    $form_build['pager'] = $build['pager'] ?? [];
    
    // Set proper order: bulk operations (10), import controls (20), status (30)
    $form_build['bulk_operations']['#weight'] = 10;
    
    // Add CSS class to table for styling.
    $form_build['table']['#attributes']['class'][] = 'module-import-table';
    
    // Add JavaScript for select all functionality.
    $form_build['#attached']['library'][] = 'ai_dashboard/bulk_operations';
    
    // Add import controls block.
    $form_build['import_controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['import-controls-block']],
      '#weight' => 20,
    ];
    
    $form_build['import_controls']['controls'] = [
      '#type' => 'markup',
      '#markup' => '<div class="import-controls">
        <h3>Import Controls</h3>
        <div class="controls-actions">
          <a href="' . \Drupal\Core\Url::fromRoute('ai_dashboard.module_import.add')->toString() . '" class="btn btn-primary">
            <span class="btn-icon">+</span> Add Configuration
          </a>
          <a href="/ai-dashboard/admin/import/run-all" class="btn btn-success" onclick="return confirm(\'Run all active import configurations?\')">
            <span class="btn-icon">▶</span> Run All Active Imports
          </a>
          <a href="/ai-dashboard/admin/import/delete-all" class="btn btn-danger" onclick="return confirm(\'This will delete ALL issues! Are you sure?\')">
            <span class="btn-icon">🗑</span> Delete All Issues
          </a>
        </div>
      </div>',
    ];
    
    // Add current status block.
    $issue_count = $this->getIssueCount();
    $form_build['status_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['import-status-block']],
      '#weight' => 30,
    ];
    
    $form_build['status_info']['status'] = [
      '#type' => 'markup',
      '#markup' => '<div class="import-status">
        <h4><span class="status-icon">📊</span> Current Status</h4>
        <div class="status-grid">
          <div class="status-item">
            <span class="status-number">' . $issue_count . '</span>
            <span class="status-label">Total Issues</span>
          </div>
          <div class="status-item">
            <span class="status-number">' . $total_configs . '</span>
            <span class="status-label">Import Configurations</span>
          </div>
          <div class="status-item">
            <span class="status-number">' . $active_configs . '</span>
            <span class="status-label">Active Configurations</span>
          </div>
        </div>
      </div>',
    ];
    
    return $form_build;
  }
  
  /**
   * Get the total count of issues in the system.
   *
   * @return int
   *   The number of AI issues.
   */
  protected function getIssueCount() {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    return $query->count()->execute();
  }

}
