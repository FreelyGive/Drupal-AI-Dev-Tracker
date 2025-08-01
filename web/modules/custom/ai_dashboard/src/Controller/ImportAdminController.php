<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_dashboard\Service\IssueImportService;
use Drupal\Core\Link;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Controller for AI Dashboard import administration.
 */
class ImportAdminController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The issue import service.
   *
   * @var \Drupal\ai_dashboard\Service\IssueImportService
   */
  protected $importService;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ImportAdminController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ai_dashboard\Service\IssueImportService $import_service
   *   The import service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, IssueImportService $import_service, MessengerInterface $messenger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->importService = $import_service;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ai_dashboard.issue_import'),
      $container->get('messenger')
    );
  }

  /**
   * Import management page.
   */
  public function importManagement() {
    $build = [];

    // Add admin navigation.
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('import_issues');

    // Page header.
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['import-admin-header']],
      '#markup' => '<h1>Import Management</h1><p>Configure and manage API imports for issues from external sources.</p>',
    ];

    // Get import configurations.
    $configs = $this->getImportConfigurations();

    if (empty($configs)) {
      // No configurations exist, show setup message.
      $build['setup'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['import-setup']],
        '#markup' => '
          <div style="background: #f0f8ff; border: 1px solid #b3d9ff; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <h3>🚀 Get Started with Imports</h3>
            <p>No import configurations found. Create your first configuration to start importing issues.</p>
            <a href="' . Url::fromRoute('node.add', ['node_type' => 'ai_import_config'])->toString() . '" class="button button--primary" style="background: #0073aa; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; margin-right: 10px;">+ Create Import Configuration</a>
          </div>',
      ];
    }
    else {
      // Show existing configurations and controls.
      $build['configurations'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['import-configurations']],
      ];

      $build['configurations']['header'] = [
        '#markup' => '<h2>Import Configurations</h2>',
      ];

      // Create configurations table.
      $build['configurations']['table'] = [
        '#type' => 'table',
        '#header' => [
          'Name',
          'Source',
          'Project ID',
          'Filter Tags',
          'Max Issues',
          'Status',
          'Actions',
        ],
        '#rows' => [],
      ];

      /** @var ModuleImport $config */
      foreach ($configs as $config) {
        $source_type = $config->getSourceType() ?? 'drupal_org';
        $project_id = $config->getProjectId() ?? '';
        $filter_tags = $config->getFilterTags() ?? [];
        $max_issues = $config->getMaxIssues() ?? 'Unlimited';
        $active = $config->isActive();

        $build['configurations']['table']['#rows'][] = [
          $config->label(),
          ucfirst(str_replace('_', '.', $source_type)),
          $project_id,
          $filter_tags ?? 'None',
          $max_issues,
          $active ? '✅ Active' : '❌ Inactive',
          // @todo: remove inline styles and JS.
          $config->toLink($this->t('Edit'), 'edit-form', ['attributes' => [
            'style' => 'color: #0073aa; text-decoration: none; margin-right: 10px',
          ]]),
          Link::fromTextAndUrl('▶ ' . $this->t('Run import'), Url::fromRoute('ai_dashboard.module_import.run',
            ['module_import' => $config->id()], ['attributes' => [
              'style' => 'color: #28a745; text-decoration: none; margin-right: 10px;',
              'onclick' => 'return confirm("' . $this->t('Are you sure you want to run this import?') . '")',
            ]])),
        ];
      }

      // Import controls.
      $build['controls'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['import-controls']],
        '#markup' => '
          <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <h3>Import Controls</h3>
            <div style="margin: 15px 0;">
              <a href="' . Url::fromRoute('ai_dashboard.module_import.add')->toString() . '" class="button" style="background: #0073aa; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; margin-right: 10px;">+ Add Configuration</a>
              <a href="/ai-dashboard/admin/import/run-all" class="button" style="background: #28a745; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; margin-right: 10px;" onclick="return confirm(\'Run all active import configurations?\')">▶ Run All Active Imports</a>
              <a href="/ai-dashboard/admin/import/delete-all" class="button" style="background: #dc3545; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px;" onclick="return confirm(\'This will delete ALL issues! Are you sure?\')">🗑 Delete All Issues</a>
            </div>
          </div>',
      ];
    }

    // Status information.
    $issue_count = $this->getIssueCount();
    $build['status'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['import-status']],
      '#markup' => '
        <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;">
          <h4>📊 Current Status</h4>
          <p><strong>Total Issues:</strong> ' . $issue_count . '</p>
          <p><strong>Import Configurations:</strong> ' . count($configs) . '</p>
        </div>',
    ];

    return $build;
  }

  /**
   * Run import from specific configuration.
   */
  public function runImport(NodeInterface $node) {
    try {
      $results = $this->importService->importFromConfig($node, TRUE);

      // Check if this is a batch import that requires redirection.
      if ($results['success'] && isset($results['redirect']) && $results['redirect']) {
        // The batch has been set up and will be processed by Drupal.
        // Don't add a message here as the batch system will handle messaging.
        return batch_process('/ai-dashboard/admin/import');
      }

      if ($results['success']) {
        $this->messenger->addStatus($results['message']);
      }
      else {
        $this->messenger->addError($results['message']);
      }
    }
    catch (\Exception $e) {
      $this->messenger->addError('Import failed: ' . $e->getMessage());
    }

    return new RedirectResponse(Url::fromRoute('ai_dashboard.admin.import')->toString());
  }


  /**
   * Run import from specific configuration.
   */
  public function runModuleImport(ModuleImport $module_import) {
    try {
      $results = $this->importService->importFromConfig($module_import, TRUE);

      // Check if this is a batch import that requires redirection.
      if ($results['success'] && isset($results['redirect']) && $results['redirect']) {
        // The batch has been set up and will be processed by Drupal.
        // Don't add a message here as the batch system will handle messaging.
        return batch_process('/ai-dashboard/admin/import');
      }

      if ($results['success']) {
        $this->messenger->addStatus($results['message']);
      }
      else {
        $this->messenger->addError($results['message']);
      }
    }
    catch (\Exception $e) {
      $this->messenger->addError('Import failed: ' . $e->getMessage());
    }

    return new RedirectResponse(Url::fromRoute('ai_dashboard.admin.import')->toString());
  }

  /**
   * Run all active imports.
   */
  public function runAllImports(Request $request) {
    $configs = $this->getActiveImportConfigurations();

    if (empty($configs)) {
      $this->messenger->addWarning('No active import configurations found.');
      return new RedirectResponse(Url::fromRoute('ai_dashboard.admin.import')->toString());
    }

    // For multiple configurations, use the dedicated batch import service.
    $batch_service = \Drupal::service('ai_dashboard.batch_import');
    $total_imported = 0;
    $total_errors = 0;
    $batch_started = FALSE;

    foreach ($configs as $config) {
      try {
        $results = $batch_service->startBatchImport($config);

        // If this is the first batch operation, redirect to batch processing.
        if ($results['success'] && isset($results['redirect']) && $results['redirect'] && !$batch_started) {
          $batch_started = TRUE;
          return batch_process('/ai-dashboard/admin/import');
        }

        $total_imported += $results['imported'] ?? 0;
        $total_errors += $results['errors'] ?? 0;
      }
      catch (\Exception $e) {
        $total_errors++;
        $this->messenger->addError('Import failed for "' . $config->getTitle() . '": ' . $e->getMessage());
      }
    }

    if (!$batch_started) {
      $this->messenger->addStatus(sprintf(
        'Import completed: %d issues imported from %d configurations, %d errors',
        $total_imported,
        count($configs),
        $total_errors
      ));
    }

    return new RedirectResponse(Url::fromRoute('ai_dashboard.admin.import')->toString());
  }

  /**
   * Delete all issues.
   */
  public function deleteAllIssues(Request $request) {
    try {
      $deleted_count = $this->importService->deleteAllIssues();
      $this->messenger->addStatus(sprintf('Deleted %d issues.', $deleted_count));
    }
    catch (\Exception $e) {
      $this->messenger->addError('Failed to delete issues: ' . $e->getMessage());
    }

    return new RedirectResponse(Url::fromRoute('ai_dashboard.admin.import')->toString());
  }

  /**
   * Get all import configurations.
   */
  protected function getImportConfigurations(): array {
    return $this->entityTypeManager->getStorage('module_import')
      ->loadByProperties(['status' => 1]);
  }

  /**
   * Get active import configurations.
   */
  protected function getActiveImportConfigurations(): array {
    return $this->entityTypeManager->getStorage('module_import')
      ->loadByProperties(['active' => 1]);
  }

  /**
   * Get filter tags from configuration.
   */
  protected function getConfigFilterTags($config): array {
    $tags = [];
    if ($config->hasField('field_import_filter_tags') && !$config->get('field_import_filter_tags')->isEmpty()) {
      $tags_string = $config->get('field_import_filter_tags')->value;
      if (!empty($tags_string)) {
        // Split by comma and clean up.
        $tags = array_map('trim', explode(',', $tags_string));
        // Remove empty values.
        $tags = array_filter($tags, function ($tag) {
          return !empty($tag);
        });
      }
    }
    return $tags;
  }

  /**
   * Get total issue count.
   */
  protected function getIssueCount(): int {
    $node_storage = $this->entityTypeManager->getStorage('node');

    return $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
