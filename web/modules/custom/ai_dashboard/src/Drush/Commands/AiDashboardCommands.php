<?php

namespace Drupal\ai_dashboard\Drush\Commands;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drush\Attributes\Command;
use Drush\Commands\DrushCommands;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for AI Dashboard module.
 */
class AiDashboardCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Generate sample tag mappings for AI Dashboard.
   *
   * @command ai-dashboard:generate-tag-mappings
   * @aliases aid-tags
   * @usage ai-dashboard:generate-tag-mappings
   *   Generate sample tag mappings for the AI Dashboard.
   */
  public function generateTagMappings() {
    $this->output()->writeln('Generating sample tag mappings...');

    $mappings_data = [
      // Category mappings.
      ['AI Logging', 'category', 'ai_integration'],
      ['AI Core', 'category', 'ai_integration'],
      ['Provider Integration', 'category', 'provider_integration'],
      ['Content Generation', 'category', 'content_generation'],
      ['Performance', 'category', 'performance'],
      ['Documentation', 'category', 'documentation'],
      ['Security', 'category', 'security'],
      ['API Integration', 'category', 'api_integration'],

      // Month mappings.
      ['January', 'month', '2024-01'],
      ['February', 'month', '2024-02'],
      ['March', 'month', '2024-03'],
      ['April', 'month', '2024-04'],
      ['May', 'month', '2024-05'],
      ['June', 'month', '2024-06'],
      ['July', 'month', '2024-07'],
      ['August', 'month', '2024-08'],
      ['September', 'month', '2024-09'],
      ['October', 'month', '2024-10'],
      ['November', 'month', '2024-11'],
      ['December', 'month', '2024-12'],

      // Priority mappings.
      ['Critical', 'priority', 'critical'],
      ['Major', 'priority', 'major'],
      ['Normal', 'priority', 'normal'],
      ['Minor', 'priority', 'minor'],
      ['Trivial', 'priority', 'trivial'],

      // Status mappings.
      ['Active', 'status', 'active'],
      ['Needs Review', 'status', 'needs_review'],
      ['Needs Work', 'status', 'needs_work'],
      ['RTBC', 'status', 'rtbc'],
      ['Fixed', 'status', 'fixed'],
      ['Closed', 'status', 'closed'],

      // Module/Component mappings.
      ['AI Module', 'module', 'ai'],
      ['OpenAI Provider', 'module', 'ai_provider_openai'],
      ['Anthropic Provider', 'module', 'ai_provider_anthropic'],
      ['CKEditor AI', 'module', 'ckeditor_ai'],
      ['Image Alt Text', 'module', 'ai_image_alt_text'],

      // Custom mappings (for specific tags that don't fit other categories)
      ['Drupal 11', 'custom', 'drupal11_compatibility'],
      ['Migration', 'custom', 'migration_task'],
      ['Testing', 'custom', 'testing_required'],
      ['Translation', 'custom', 'i18n_support'],
    ];

    $created_count = 0;
    foreach ($mappings_data as $data) {
      $mapping = Node::create([
        'type' => 'ai_tag_mapping',
        'title' => 'Map "' . $data[0] . '" to ' . ucfirst($data[1]),
        'field_source_tag' => $data[0],
        'field_mapping_type' => $data[1],
        'field_mapped_value' => $data[2],
        'status' => 1,
      ]);
      $mapping->save();
      $created_count++;
    }

    $this->output()->writeln('Generated ' . $created_count . ' tag mappings.');
    $this->output()->writeln('You can now manage these mappings at /ai-dashboard/admin/tag-mappings');
  }

  /**
   * Generate dummy content for AI Dashboard.
   *
   * @command ai-dashboard:generate-dummy
   * @aliases aid-gen
   * @usage ai-dashboard:generate-dummy
   *   Generate dummy content for the AI Dashboard.
   */
  public function generateDummy() {
    $this->output()->writeln('Generating dummy content for AI Dashboard...');

    // Clear existing content first.
    $this->clearExistingContent();

    // Generate companies.
    $companies = $this->generateCompanies();
    $this->output()->writeln('Generated ' . count($companies) . ' companies.');

    // Generate modules.
    $modules = $this->generateModules();
    $this->output()->writeln('Generated ' . count($modules) . ' modules.');

    // Generate contributors.
    $contributors = $this->generateContributors($companies);
    $this->output()->writeln('Generated ' . count($contributors) . ' contributors.');

    // Generate issues.
    $issues = $this->generateIssues($modules, $contributors);
    $this->output()->writeln('Generated ' . count($issues) . ' issues.');

    // Generate resource allocations.
    $allocations = $this->generateResourceAllocations($contributors, $issues);
    $this->output()->writeln('Generated ' . count($allocations) . ' resource allocations.');

    $this->output()->writeln('Dummy content generation complete!');
  }

  /**
   * Clear existing AI Dashboard content.
   */
  private function clearExistingContent() {
    $types = ['ai_company', 'ai_contributor', 'ai_module', 'ai_issue', 'ai_resource_allocation'];

    foreach ($types as $type) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => $type]);
      if (!empty($nodes)) {
        $this->entityTypeManager->getStorage('node')->delete($nodes);
      }
    }
  }

  /**
   * Generate dummy companies.
   */
  private function generateCompanies() {
    $companies_data = [
      ['Acquia', 'enterprise', 'https://acquia.com'],
      ['Lullabot', 'medium', 'https://lullabot.com'],
      ['Phase2', 'medium', 'https://phase2technology.com'],
      ['Pantheon', 'large', 'https://pantheon.io'],
      ['AI Innovations Inc', 'startup', 'https://aiinnovations.com'],
      ['TechCorp Solutions', 'large', 'https://techcorp.com'],
      ['Digital Frontier', 'small', 'https://digitalfrontier.com'],
      ['CloudAI Systems', 'medium', 'https://cloudai.com'],
      ['Neural Networks Ltd', 'startup', 'https://neuralnetworks.com'],
      ['Enterprise AI Group', 'enterprise', 'https://enterpriseai.com'],
    ];

    $companies = [];
    foreach ($companies_data as $data) {
      $company = Node::create([
        'type' => 'ai_company',
        'title' => $data[0],
        'field_company_size' => $data[1],
        'field_company_website' => [
          'uri' => $data[2],
          'title' => $data[0] . ' Website',
        ],
        'status' => 1,
      ]);
      $company->save();
      $companies[] = $company;
    }

    return $companies;
  }

  /**
   * Generate dummy modules.
   */
  private function generateModules() {
    $modules_data = [
      ['AI', 'ai', 'core_ai', 'https://drupal.org/project/ai'],
      ['AI Provider OpenAI', 'ai_provider_openai', 'provider', 'https://drupal.org/project/ai'],
      ['AI Provider Anthropic', 'ai_provider_anthropic', 'provider', 'https://drupal.org/project/ai'],
      ['AI Agents', 'ai_agents', 'integration', 'https://drupal.org/project/ai'],
      ['AI Image Alt Text', 'ai_image_alt_text', 'integration', 'https://drupal.org/project/ai'],
      ['CKEditor AI', 'ckeditor_ai', 'integration', 'https://drupal.org/project/ckeditor_ai'],
      ['Smart Content', 'smart_content', 'related', 'https://drupal.org/project/smart_content'],
      ['AI Translation', 'ai_translation', 'utility', 'https://drupal.org/project/ai_translation'],
    ];

    $modules = [];
    foreach ($modules_data as $data) {
      $module = Node::create([
        'type' => 'ai_module',
        'title' => $data[0],
        'field_module_machine_name' => $data[1],
        'field_module_category' => $data[2],
        'field_module_project_url' => [
          'uri' => $data[3],
          'title' => $data[0] . ' Project Page',
        ],
        'status' => 1,
      ]);
      $module->save();
      $modules[] = $module;
    }

    return $modules;
  }

  /**
   * Generate dummy contributors.
   */
  private function generateContributors($companies) {
    $contributors_data = [
      ['Marcus Johansson', 'marcus_johansson', 'Senior AI Developer', 'marcus@example.com'],
      ['Sarah Chen', 'sarah_chen', 'Machine Learning Engineer', 'sarah@example.com'],
      ['Alex Rodriguez', 'alex_r', 'Full Stack Developer', 'alex@example.com'],
      ['Emma Thompson', 'emma_t', 'AI Research Scientist', 'emma@example.com'],
      ['David Kim', 'dkim', 'Technical Lead', 'david@example.com'],
      ['Lisa Park', 'lpark', 'Frontend Developer', 'lisa@example.com'],
      ['Michael Johnson', 'mjohnson', 'AI Specialist', 'michael@example.com'],
      ['Anna Kowalski', 'anna_k', 'Solutions Architect', 'anna@example.com'],
      ['James Wilson', 'jwilson', 'DevOps Engineer', 'james@example.com'],
      ['Maria Garcia', 'mgarcia', 'Product Manager', 'maria@example.com'],
      ['Robert Lee', 'rlee', 'Senior Developer', 'robert@example.com'],
      ['Jennifer Brown', 'jbrown', 'AI Engineer', 'jennifer@example.com'],
    ];

    $contributors = [];
    foreach ($contributors_data as $index => $data) {
      $company = $companies[array_rand($companies)];

      $contributor = Node::create([
        'type' => 'ai_contributor',
        'title' => $data[0],
        'field_drupal_username' => $data[1],
        'field_contributor_role' => $data[2],
        'field_contributor_email' => $data[3],
        'field_contributor_company' => ['target_id' => $company->id()],
        'status' => 1,
      ]);
      $contributor->save();
      $contributors[] = $contributor;
    }

    return $contributors;
  }

  /**
   * Generate dummy issues.
   */
  private function generateIssues($modules, $contributors) {
    $issues_data = [
      ['Add OpenAI GPT-4 support', 'active', 'major', ['feature', 'ai-core']],
      ['Implement token usage tracking', 'needs_review', 'normal', ['enhancement', 'tracking']],
      ['Fix memory leak in AI processing', 'needs_work', 'critical', ['bug', 'performance']],
      ['Add Anthropic Claude 3 integration', 'active', 'major', ['feature', 'provider']],
      ['Improve error handling for API failures', 'rtbc', 'normal', ['bug', 'api']],
      ['Add batch processing for large datasets', 'active', 'minor', ['feature', 'performance']],
      ['Update documentation for new AI providers', 'needs_work', 'minor', ['task', 'documentation']],
      ['Implement AI response caching', 'needs_review', 'normal', ['enhancement', 'performance']],
      ['Add support for custom AI models', 'active', 'major', ['feature', 'extensibility']],
      ['Fix compatibility with Drupal 11', 'fixed', 'critical', ['bug', 'compatibility']],
      ['Add AI-powered content suggestions', 'active', 'normal', ['feature', 'content']],
      ['Improve AI prompt templates', 'needs_review', 'minor', ['enhancement', 'templates']],
      ['Add multi-language support for AI', 'active', 'major', ['feature', 'i18n']],
      ['Optimize AI response parsing', 'rtbc', 'normal', ['enhancement', 'performance']],
      ['Add AI audit logging', 'needs_work', 'normal', ['feature', 'security']],
    ];

    $issues = [];
    $issue_number_start = 3412340;

    foreach ($issues_data as $index => $data) {
      $module = $modules[array_rand($modules)];
      $issue_number = $issue_number_start + $index;

      // Assign 1-3 random contributors.
      $assignee_count = rand(1, 3);
      $assignees = array_rand($contributors, $assignee_count);
      if (!is_array($assignees)) {
        $assignees = [$assignees];
      }

      $assignee_refs = [];
      foreach ($assignees as $assignee_index) {
        $assignee_refs[] = ['target_id' => $contributors[$assignee_index]->id()];
      }

      $issue = Node::create([
        'type' => 'ai_issue',
        'title' => $data[0],
        'field_issue_number' => $issue_number,
        'field_issue_url' => [
          'uri' => 'https://drupal.org/project/ai/issues/' . $issue_number,
          'title' => 'Issue #' . $issue_number,
        ],
        'field_issue_module' => ['target_id' => $module->id()],
        'field_issue_status' => $data[1],
        'field_issue_priority' => $data[2],
        'field_issue_assignees' => $assignee_refs,
        'field_issue_tags' => $data[3],
        'status' => 1,
      ]);
      $issue->save();
      $issues[] = $issue;
    }

    return $issues;
  }

  /**
   * Generate dummy resource allocations.
   */
  private function generateResourceAllocations($contributors, $issues) {
    $allocations = [];

    // Generate allocations for the past 8 weeks.
    $start_date = new \DateTime('-8 weeks');
    // Start on Monday.
    $start_date->modify('monday this week');

    for ($week = 0; $week < 8; $week++) {
      $week_date = clone $start_date;
      $week_date->modify('+' . $week . ' weeks');

      foreach ($contributors as $contributor) {
        // Some contributors might not have allocations every week.
        if (rand(1, 10) > 7) {
          continue;
        }

        // Random allocation between 0.5 and 4 days.
        $days = (rand(5, 40) / 10);

        // Randomly assign some issues to this allocation.
        $related_issues = [];
        $issue_count = rand(0, 3);
        if ($issue_count > 0) {
          $random_issues = array_rand($issues, min($issue_count, count($issues)));
          if (!is_array($random_issues)) {
            $random_issues = [$random_issues];
          }
          foreach ($random_issues as $issue_index) {
            $related_issues[] = ['target_id' => $issues[$issue_index]->id()];
          }
        }

        $allocation = Node::create([
          'type' => 'ai_resource_allocation',
          'title' => $contributor->getTitle() . ' - Week of ' . $week_date->format('Y-m-d'),
          'field_allocation_contributor' => ['target_id' => $contributor->id()],
          'field_allocation_week' => $week_date->format('Y-m-d'),
          'field_allocation_days' => $days,
          'field_allocation_issues' => $related_issues,
          'status' => 1,
        ]);
        $allocation->save();
        $allocations[] = $allocation;
      }
    }

    return $allocations;
  }

  /**
   * Test issue import from drupal.org with status filtering.
   *
   * @param string $config_id
   *   Node ID of import configuration.
   *
   * @command ai-dashboard:test-import
   * @aliases aid-import
   * @usage ai-dashboard:test-import [config_id] [--batch]
   *   Test issue import functionality with the specified config ID.
   * @option batch Use batch processing for the import
   */
  #[Command(name: 'ai-dashboard:import')]
  public function importSingleConfiguration(string $config_id, array $options = []) {
    $this->output()->writeln('Importing issues from drupal.org');

    // Load import configuration.
    /** @var ModuleImport $config */
    $config = $this->entityTypeManager->getStorage('module_import')
      ->load($config_id);
    if (!$config) {
      $this->output()->writeln('<error>Import configuration not found or invalid.</error>');
      return;
    }

    $this->output()->writeln('Configuration: ' . $config->label());

    // Show status filter values.
    $status_filter = $config->getStatusFilter();
    $this->output()->writeln('Status filter: ' . implode(', ', $status_filter));

    $moduleNode = $this->entityTypeManager->getStorage('node')
      ->loadByProperties([
        'field_module_machine_name' => $config->getProjectMachineName(),
      ]);
    if (!$moduleNode) {
      $this->output()->writeln('<error>Project ' . $config->getProjectMachineName()
      . ' not found.</error>');
    }
    $moduleNode = reset($moduleNode);

    // Count current issues.
    $current_count = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->condition('field_issue_module', $moduleNode->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    $this->output()->writeln('Current issues: ' . $current_count);

    // Get import service and run import.
    $import_service = \Drupal::service('ai_dashboard.issue_import');

    // Use the configured max issues or 500 if blank for full test.
    $original_max = $config->getMaxIssues();
    if (!$original_max) {
      $config->setMaxIssues(500);
    }

    try {
      // Always use batch mode with drush.
      $batch = $import_service->buildImportBatch($config);
      drush_backend_batch_process($batch);

      $final_count = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      $this->output()->writeln('Final issues: ' . $final_count);
      $this->output()->writeln('Net change: ' . ($final_count - $current_count));
    }
    catch (\Exception $e) {
      $this->output()->writeln('<error>Import failed: ' . $e->getMessage() . '</error>');
    }

    // Restore original max issues.
    $config->setMaxIssues($original_max);
    $config->save();
  }

  /**
   * Import all active configurations.
   */
  #[Command(name: 'ai-dashboard:import-all')]
  public function importAllConfigurations() {
    $storage = $this->entityTypeManager->getStorage('module_import');
    $activeConfigurations = $storage->loadByProperties(['active' => TRUE]);
    if (!$activeConfigurations) {
      $this->output()->writeln('No active import configurations.');
      return;
    }
    foreach ($activeConfigurations as $configuration) {
      $this->importSingleConfiguration($configuration->id());
    }
  }

  /**
   * Clean up unsupported status filters from configurations.
   *
   * @command ai-dashboard:clean-status-filters
   * @aliases aidash:clean-status
   */
  public function cleanStatusFilters() {
    $config_factory = \Drupal::configFactory();

    // Update field storage configuration.
    $field_storage_config = $config_factory->getEditable('field.storage.node.field_import_status_filter');
    if ($field_storage_config && !$field_storage_config->isNew()) {
      $allowed_values = $field_storage_config->get('settings.allowed_values');

      // Remove unsupported status values.
      // Need review (maintainer), Needs tests, Needs clarification.
      $unsupported_values = ['7', '17', '5'];
      $updated_values = [];
      $removed_count = 0;

      foreach ($allowed_values as $value) {
        if (!in_array($value['value'], $unsupported_values)) {
          $updated_values[] = $value;
        }
        else {
          $removed_count++;
          $this->output()->writeln("Removing unsupported status: {$value['label']} ({$value['value']})");
        }
      }

      if ($removed_count > 0) {
        $field_storage_config->set('settings.allowed_values', $updated_values);
        $field_storage_config->save();
        $this->output()->writeln("Updated field storage configuration.");
      }
    }

    // Update existing ai_import_config nodes to remove unsupported statuses.
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_import_config')
      ->accessCheck(FALSE);

    $nids = $query->execute();
    $updated_nodes = 0;

    if (!empty($nids)) {
      $nodes = $node_storage->loadMultiple($nids);
      $unsupported_values = ['7', '17', '5'];

      foreach ($nodes as $node) {
        if ($node->hasField('field_import_status_filter') && !$node->get('field_import_status_filter')->isEmpty()) {
          $updated_values = [];
          $has_changes = FALSE;

          foreach ($node->get('field_import_status_filter') as $item) {
            if (!in_array($item->value, $unsupported_values)) {
              $updated_values[] = ['value' => $item->value];
            }
            else {
              $has_changes = TRUE;
            }
          }

          if ($has_changes) {
            $node->set('field_import_status_filter', $updated_values);
            $node->save();
            $updated_nodes++;
            $this->output()->writeln("Updated import config: {$node->getTitle()}");
          }
        }
      }
    }

    // Clear field and form caches.
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    \Drupal::service('plugin.manager.field.widget')->clearCachedDefinitions();
    \Drupal::service('plugin.manager.field.formatter')->clearCachedDefinitions();

    // Clear all caches to ensure the changes take effect.
    drupal_flush_all_caches();

    $this->output()->writeln("✅ Cleanup completed!");
    $this->output()->writeln("Updated {$updated_nodes} import configuration nodes.");
    $this->output()->writeln("Removed unsupported status filters: Need review (maintainer), Needs tests, Needs clarification");
  }

}
