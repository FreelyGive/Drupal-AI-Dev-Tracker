<?php

namespace Drupal\ai_dashboard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ai_dashboard\Service\IssueImportService;
use Drupal\node\Entity\Node;

/**
 * Dedicated service for batch import operations.
 */
class IssueBatchImportService {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The issue import service.
   *
   * @var \Drupal\ai_dashboard\Service\IssueImportService
   */
  protected $issueImportService;

  /**
   * Constructs a new IssueBatchImportService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\ai_dashboard\Service\IssueImportService $issue_import_service
   *   The issue import service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
    IssueImportService $issue_import_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;
    $this->issueImportService = $issue_import_service;
  }

  /**
   * Start a batch import process.
   *
   * @param \Drupal\node\Entity\Node $config
   *   The import configuration node.
   *
   * @return array
   *   Result array with success status and messages.
   */
  public function startBatchImport(Node $config): array {
    $logger = $this->loggerFactory->get('ai_dashboard');
    
    try {
      // Extract configuration parameters
      $source_type = $config->get('field_import_source_type')->value;
      $project_id = $config->get('field_import_project_id')->value;
      $max_issues = $config->get('field_import_max_issues')->value;
      $max_issues = $max_issues ? (int) $max_issues : 1000;

      // Get filter parameters
      $filter_tags = $this->getFilterTags($config);
      $status_filter = $this->getStatusFilter($config);
      $date_filter = $config->get('field_import_date_filter')->value;

      $logger->info('Starting batch import from @source for project @project with max @max issues', [
        '@source' => $source_type,
        '@project' => $project_id,
        '@max' => $max_issues,
      ]);

      // If we have multiple statuses, create separate operations for each status
      if (count($status_filter) > 1) {
        return $this->createMultiStatusBatch($config, $source_type, $project_id, $filter_tags, $status_filter, $date_filter, $max_issues);
      }

      // Calculate total estimated operations based on API page size for single status
      // drupal.org API returns max 50 issues per page, so use that as batch size
      $issues_per_batch = 50; // Use API page size to avoid pagination conflicts
      $estimated_operations = ceil($max_issues / $issues_per_batch);

      // Build batch configuration
      $batch = [
        'title' => $this->t('Importing Issues from @source', ['@source' => ucfirst($source_type)]),
        'operations' => [],
        'init_message' => $this->t('Initializing import of up to @max issues...', ['@max' => $max_issues]),
        'progress_message' => $this->t('Processed @current out of @total operations.'),
        'error_message' => $this->t('Issue import has encountered an error.'),
        'finished' => [self::class, 'batchFinished'],
        'file' => \Drupal::service('extension.list.module')->getPath('ai_dashboard') . '/src/Service/IssueBatchImportService.php',
      ];

      // Create batch operations
      for ($i = 0; $i < $estimated_operations; $i++) {
        $offset = $i * $issues_per_batch;
        $limit = min($issues_per_batch, $max_issues - $offset);
        
        if ($limit <= 0) {
          break; // No more issues to process
        }

        $batch['operations'][] = [
          [self::class, 'batchOperation'],
          [
            $config->id(),
            $source_type,
            $project_id,
            $filter_tags,
            $status_filter,
            $date_filter,
            $offset,
            $limit,
          ],
        ];
      }

      // Set the batch
      batch_set($batch);

      // For web requests, indicate that batch processing should be handled by controller
      if (PHP_SAPI !== 'cli') {
        // Store start time for reporting
        \Drupal::state()->set('ai_dashboard.batch_start_time', time());
        
        // Return success with redirect flag - the controller will call batch_process()
        return [
          'success' => TRUE,
          'message' => $this->t('Batch import started with @count operations.', ['@count' => count($batch['operations'])]),
          'redirect' => TRUE,
          'imported' => 0,
          'skipped' => 0,
          'errors' => 0,
        ];
      }

      // For CLI, process immediately
      $batch =& batch_get();
      $batch['progressive'] = FALSE;
      batch_process();

      return [
        'success' => TRUE,
        'message' => $this->t('Batch import completed via CLI.'),
      ];

    } catch (\Exception $e) {
      $logger->error('Failed to start batch import: @message', ['@message' => $e->getMessage()]);
      return [
        'success' => FALSE,
        'message' => $this->t('Failed to start batch import: @error', ['@error' => $e->getMessage()]),
      ];
    }
  }

  /**
   * Batch operation callback.
   *
   * @param int $config_id
   *   The configuration node ID.
   * @param string $source_type
   *   The import source type.
   * @param string $project_id
   *   The project ID.
   * @param array $filter_tags
   *   Array of filter tags.
   * @param array $status_filter
   *   Array of status filters.
   * @param string|null $date_filter
   *   Date filter string.
   * @param int $offset
   *   Starting offset for this batch.
   * @param int $limit
   *   Number of items to process in this batch.
   * @param array $context
   *   Batch context array.
   */
  public static function batchOperation($config_id, $source_type, $project_id, $filter_tags, $status_filter, $date_filter, $offset, $limit, &$context) {
    $logger = \Drupal::service('logger.factory')->get('ai_dashboard');
    
    // Initialize sandbox on first operation
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['total_processed'] = 0;
      $context['sandbox']['current_operation'] = 0;
      $context['results'] = [
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
        'total_operations' => 0,
      ];
    }

    try {
      // Load configuration
      $config = \Drupal::entityTypeManager()->getStorage('node')->load($config_id);
      if (!$config) {
        throw new \Exception('Configuration node not found');
      }

      // Get import service
      $import_service = \Drupal::service('ai_dashboard.issue_import');

      // Process this batch
      switch ($source_type) {
        case 'drupal_org':
          $results = $import_service->importFromDrupalOrgBatch(
            $project_id,
            $filter_tags,
            $status_filter,
            $offset,
            $limit,
            $date_filter,
            $config
          );
          break;

        default:
          throw new \Exception("Batch import not supported for source type: {$source_type}");
      }

      // Update context with results
      $context['results']['imported'] += $results['imported'];
      $context['results']['skipped'] += $results['skipped'];
      $context['results']['errors'] += $results['errors'];
      $context['results']['total_operations']++;

      // Update progress tracking
      $context['sandbox']['current_operation']++;
      $context['sandbox']['total_processed'] += $results['imported'] + $results['skipped'];

      // Update user message
      $context['message'] = t('Processed batch @current: @imported imported, @skipped skipped from offset @offset', [
        '@current' => $context['sandbox']['current_operation'],
        '@imported' => $results['imported'],
        '@skipped' => $results['skipped'],
        '@offset' => $offset,
      ]);

      $logger->info('Batch operation @current completed: @imported imported, @skipped skipped, @errors errors', [
        '@current' => $context['sandbox']['current_operation'],
        '@imported' => $results['imported'],
        '@skipped' => $results['skipped'],
        '@errors' => $results['errors'],
      ]);

      // Mark this operation as finished
      $context['finished'] = 1;

    } catch (\Exception $e) {
      $logger->error('Batch operation failed: @message', ['@message' => $e->getMessage()]);
      $context['results']['errors']++;
      $context['finished'] = 1; // Continue to next operation even on error
    }
  }

  /**
   * Batch operation callback for single status import.
   */
  public static function batchOperationSingleStatus($config_id, $source_type, $project_id, $filter_tags, $status_filter, $date_filter, $status_name, $max_issues, &$context) {
    $logger = \Drupal::service('logger.factory')->get('ai_dashboard');
    
    // Initialize sandbox on first operation
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['results'] = [
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
        'total_operations' => 0,
      ];
    }

    try {
      // Load configuration
      $config = \Drupal::entityTypeManager()->getStorage('node')->load($config_id);
      if (!$config) {
        throw new \Exception('Configuration node not found');
      }

      // Get import service and import this single status
      $import_service = \Drupal::service('ai_dashboard.issue_import');
      
      // Import all issues for this single status
      $results = $import_service->importFromDrupalOrg(
        $project_id,
        $filter_tags,
        $status_filter, // Single status array
        $max_issues,
        $date_filter,
        $config
      );

      // Update context with results
      $context['results']['imported'] += $results['imported'];
      $context['results']['skipped'] += $results['skipped'];
      $context['results']['errors'] += $results['errors'];
      $context['results']['total_operations']++;

      // Update user message
      $context['message'] = t('Completed @status: @imported imported, @skipped skipped', [
        '@status' => $status_name,
        '@imported' => $results['imported'],
        '@skipped' => $results['skipped'],
      ]);

      $logger->info('Single status import completed for @status: @imported imported, @skipped skipped, @errors errors', [
        '@status' => $status_name,
        '@imported' => $results['imported'],
        '@skipped' => $results['skipped'],
        '@errors' => $results['errors'],
      ]);

      // Mark this operation as finished
      $context['finished'] = 1;

    } catch (\Exception $e) {
      $logger->error('Single status batch operation failed for @status: @message', [
        '@status' => $status_name,
        '@message' => $e->getMessage()
      ]);
      $context['results']['errors']++;
      $context['finished'] = 1; // Continue to next operation even on error
    }
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   Array of results from batch operations.
   * @param array $operations
   *   Array of operations that were performed.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::service('messenger');
    $logger = \Drupal::service('logger.factory')->get('ai_dashboard');
    
    // Clear batch state
    \Drupal::state()->delete('ai_dashboard.batch_start_time');

    if ($success) {
      $imported = $results['imported'] ?? 0;
      $skipped = $results['skipped'] ?? 0;
      $errors = $results['errors'] ?? 0;
      $total_operations = $results['total_operations'] ?? 0;

      $message = t('✅ Import completed successfully!');
      $details = t('@operations operations completed: @imported issues imported, @skipped skipped, @errors errors', [
        '@operations' => $total_operations,
        '@imported' => $imported,
        '@skipped' => $skipped,
        '@errors' => $errors,
      ]);

      $messenger->addMessage($message);
      $messenger->addMessage($details);

      if ($imported > 0) {
        $messenger->addMessage(t('📋 View imported issues: <a href="@url">Admin Tools → Issues</a>', [
          '@url' => '/ai-dashboard/admin/issues',
        ]));
      }

      $logger->info('Batch import completed: @imported imported, @skipped skipped, @errors errors in @operations operations', [
        '@imported' => $imported,
        '@skipped' => $skipped,
        '@errors' => $errors,
        '@operations' => $total_operations,
      ]);
      
      // Invalidate caches after batch import completion
      static::invalidateBatchImportCaches();

    } else {
      $message = t('❌ Import completed with some errors.');
      $messenger->addError($message);
      $messenger->addMessage(t('Check the <a href="@url">log messages</a> for detailed error information.', [
        '@url' => '/admin/reports/dblog',
      ]));

      $logger->error('Batch import completed with errors');
    }
  }

  /**
   * Create batch operations for multiple statuses separately.
   */
  protected function createMultiStatusBatch(Node $config, string $source_type, string $project_id, array $filter_tags, array $status_filter, ?string $date_filter, int $max_issues): array {
    $status_names = [
      '1' => 'Active',
      '13' => 'Needs work', 
      '8' => 'Needs review',
      '14' => 'RTBC',
      '15' => 'Patch (to be ported)',
      '2' => 'Fixed',
      '4' => 'Postponed',
      '16' => 'Postponed (maintainer needs more info)',
    ];

    // Build batch configuration for multi-status import
    $batch = [
      'title' => $this->t('Importing Issues from @source (Multi-Status)', ['@source' => ucfirst($source_type)]),
      'operations' => [],
      'init_message' => $this->t('Initializing import of @count status types...', ['@count' => count($status_filter)]),
      'progress_message' => $this->t('Processed @current out of @total status types.'),
      'error_message' => $this->t('Multi-status issue import has encountered an error.'),
      'finished' => [self::class, 'batchFinished'],
      'file' => \Drupal::service('extension.list.module')->getPath('ai_dashboard') . '/src/Service/IssueBatchImportService.php',
    ];

    // Create one operation per status
    foreach ($status_filter as $single_status) {
      $status_name = $status_names[$single_status] ?? "Status $single_status";
      
      $batch['operations'][] = [
        [self::class, 'batchOperationSingleStatus'],
        [
          $config->id(),
          $source_type,
          $project_id,
          $filter_tags,
          [$single_status], // Single status array
          $date_filter,
          $status_name,
          $max_issues,
        ],
      ];
    }

    // Set the batch
    batch_set($batch);

    // For web requests, indicate that batch processing should be handled by controller
    if (PHP_SAPI !== 'cli') {
      // Store start time for reporting
      \Drupal::state()->set('ai_dashboard.batch_start_time', time());
      
      // Return success with redirect flag - the controller will call batch_process()
      return [
        'success' => TRUE,
        'message' => $this->t('Multi-status batch import started with @count operations.', ['@count' => count($batch['operations'])]),
        'redirect' => TRUE,
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
      ];
    }

    // For CLI, process immediately
    $batch =& batch_get();
    $batch['progressive'] = FALSE;
    batch_process();

    return [
      'success' => TRUE,
      'message' => $this->t('Multi-status batch import completed via CLI.'),
    ];
  }

  /**
   * Get filter tags from configuration.
   */
  protected function getFilterTags(Node $config): array {
    $tags = [];
    if ($config->hasField('field_import_filter_tags') && !$config->get('field_import_filter_tags')->isEmpty()) {
      $tags_string = $config->get('field_import_filter_tags')->value;
      if (!empty($tags_string)) {
        $tags = array_map('trim', explode(',', $tags_string));
        $tags = array_filter($tags, function($tag) {
          return !empty($tag);
        });
      }
    }
    return $tags;
  }

  /**
   * Get status filter from configuration.
   */
  protected function getStatusFilter(Node $config): array {
    $statuses = [];
    if ($config->hasField('field_import_status_filter') && !$config->get('field_import_status_filter')->isEmpty()) {
      foreach ($config->get('field_import_status_filter') as $item) {
        if (!empty($item->value)) {
          if ($item->value === 'all_open') {
            // Return statuses that match drupal.org's complete open filter including postponed
            return ['1', '13', '8', '14', '15', '2', '4', '16'];
          }
          $statuses[] = $item->value;
        }
      }
    }
    return $statuses;
  }

  /**
   * Invalidate caches after batch import operations.
   */
  protected static function invalidateBatchImportCaches() {
    // Invalidate specific cache tags for dashboard data
    $cache_tags = [
      'ai_dashboard:calendar',
      'node_list:ai_issue',
      'node_list:ai_contributor',
      'ai_dashboard:import',
    ];
    \Drupal::service('cache_tags.invalidator')->invalidateTags($cache_tags);
    
    // Invalidate dynamic page cache for dashboard pages
    \Drupal::service('cache.dynamic_page_cache')->deleteAll();
    
    // Invalidate render cache for views and blocks
    \Drupal::service('cache.render')->deleteAll();
  }

}