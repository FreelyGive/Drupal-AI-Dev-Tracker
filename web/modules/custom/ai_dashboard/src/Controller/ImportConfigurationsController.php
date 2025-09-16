<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Controller for read-only import configurations page.
 */
class ImportConfigurationsController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an ImportConfigurationsController.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Displays the import configurations page.
   */
  public function view() {
    $configurations = [];
    $state = \Drupal::state();
    
    // Try to load from module_import entities first
    if ($this->entityTypeManager->hasDefinition('module_import')) {
      try {
        $storage = $this->entityTypeManager->getStorage('module_import');
        // Load all module imports without access checks for anonymous viewing
        $query = $storage->getQuery();
        $query->accessCheck(FALSE);
        $ids = $query->execute();
        $module_imports = $ids ? $storage->loadMultiple($ids) : [];
        
        foreach ($module_imports as $import) {
          // Map status filter IDs to labels
          $status_filters = $import->get('status_filter') ?? [];
          $status_labels = [];
          foreach ($status_filters as $status) {
            $status_labels[] = $this->getStatusLabel($status);
          }
          
          // Get tag and component filters
          $tag_filters_string = $import->get('filter_tags') ?? '';
          $tag_filters = !empty($tag_filters_string) ? array_map('trim', explode(',', $tag_filters_string)) : [];
          $component_filter = $import->get('filter_component') ?? '';
          
          // Get last run timestamp
          $last_run = $state->get('ai_dashboard:last_import:' . $import->id());
          $last_run_formatted = '';
          if ($last_run) {
            $last_run_formatted = \Drupal::service('date.formatter')->format($last_run, 'custom', 'M j, Y g:i A');
          }
          
          $configurations[] = [
            'id' => $import->id(),
            'label' => $import->label(),
            'description' => $import->get('description') ?? '',
            'api_url' => $this->buildApiUrl($import),
            'module' => $import->get('project_name') ?? '',
            'status_filters' => $status_labels,
            'tag_filters' => $tag_filters,
            'component_filters' => !empty($component_filter) ? [$component_filter] : [],
            'active' => $import->get('active') ?? FALSE,
            'last_run' => $last_run_formatted,
          ];
        }
      }
      catch (\Exception $e) {
        // Log error but continue
        \Drupal::logger('ai_dashboard')->error('Could not load module imports: @error', ['@error' => $e->getMessage()]);
      }
    }
    
    // If no configurations loaded, use defaults
    if (empty($configurations)) {
      $stored_configs = $this->getDefaultConfigurations();
      foreach ($stored_configs as $key => $config_data) {
        $configurations[] = [
          'id' => $key,
          'label' => $config_data['label'] ?? $key,
          'description' => $config_data['description'] ?? '',
          'api_url' => $config_data['api_url'] ?? '',
          'module' => $config_data['module'] ?? '',
          'status_filters' => $config_data['status_filters'] ?? [],
          'active' => $config_data['active'] ?? FALSE,
        ];
      }
    }
    
    // Sort by label
    usort($configurations, function($a, $b) {
      return strcasecmp($a['label'], $b['label']);
    });

    $build = [
      '#theme' => 'ai_import_configurations',
      '#configurations' => $configurations,
      '#attached' => [
        'library' => [
          'ai_dashboard/shared_components',
        ],
      ],
      '#cache' => [
        'tags' => ['module_import_list', 'config:ai_dashboard.import_settings'],
        'contexts' => ['user.permissions'],
      ],
    ];

    return $build;
  }

  /**
   * Get default configurations.
   */
  private function getDefaultConfigurations() {
    return [
      'all_open_active_issues' => [
        'label' => 'All Open Active Issues',
        'description' => 'Imports all open and active AI-related issues from drupal.org',
        'api_url' => 'https://www.drupal.org/api-d7/node.json',
        'module' => '',
        'status_filters' => ['1', '13', '8'], // Active, Needs work, Needs review
        'active' => TRUE,
      ],
      'openai_provider' => [
        'label' => 'OpenAI Provider Issues',
        'description' => 'Issues related to the OpenAI Provider module',
        'api_url' => 'https://www.drupal.org/api-d7/node.json',
        'module' => 'OpenAI',
        'status_filters' => ['1', '13', '8', '14'], // Active, Needs work, Needs review, RTBC
        'active' => TRUE,
      ],
      'ai_agents' => [
        'label' => 'AI Agents Issues',
        'description' => 'Issues related to the AI Agents module',
        'api_url' => 'https://www.drupal.org/api-d7/node.json',
        'module' => 'AI Agents',
        'status_filters' => ['1', '13', '8'],
        'active' => TRUE,
      ],
      'ai_automator' => [
        'label' => 'AI Automator Issues',
        'description' => 'Issues related to the AI Automator module',
        'api_url' => 'https://www.drupal.org/api-d7/node.json',
        'module' => 'AI Automator',
        'status_filters' => ['1', '13', '8'],
        'active' => FALSE,
      ],
      'ai_logging' => [
        'label' => 'AI Logging Issues',
        'description' => 'Issues related to the AI Logging module',
        'api_url' => 'https://www.drupal.org/api-d7/node.json',
        'module' => 'AI Logging',
        'status_filters' => ['1', '13', '8'],
        'active' => TRUE,
      ],
    ];
  }

  /**
   * Title callback.
   */
  public function title() {
    return 'Modules imported';
  }

  /**
   * Get human-readable status label.
   */
  private function getStatusLabel($status_id) {
    $statuses = [
      '1' => 'Active',
      '2' => 'Fixed',
      '3' => 'Closed (duplicate)',
      '4' => 'Postponed',
      '5' => "Closed (won't fix)",
      '6' => 'Closed (works as designed)',
      '7' => 'Closed (fixed)',
      '8' => 'Needs review',
      '13' => 'Needs work',
      '14' => 'RTBC',
      '16' => 'Postponed (maintainer needs more info)',
      '17' => 'Closed (outdated)',
      '18' => 'Closed (cannot reproduce)',
    ];
    
    return $statuses[$status_id] ?? "Status $status_id";
  }

  /**
   * Build API URL for a module import.
   */
  private function buildApiUrl($import) {
    $base_url = 'https://www.drupal.org/api-d7/node.json';
    $project_id = $import->get('project_id');
    
    if ($project_id) {
      return $base_url . '?field_project=' . $project_id;
    }
    
    return $base_url;
  }
}