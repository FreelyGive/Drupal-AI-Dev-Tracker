<?php

namespace Drupal\ai_dashboard\Service;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;

/**
 * Dedicated service for batch import operations.
 */
class IssueImportOrchestrationService {

  use StringTranslationTrait;

  /**
   * Default value for max issues, when none is set in config.
   */

  const DEFAULT_MAX_ISSUES = 1000;

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
   * @var \Drupal\ai_dashboard\Service\IssueImportProcessService
   */
  protected $issueProcessService;

  /**
   * Constructs a new IssueImportOrchestrationService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\ai_dashboard\Service\IssueImportProcessService $issue_process_service
   *   The issue import service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
    IssueImportProcessService $issue_process_service,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;
    $this->issueProcessService = $issue_process_service;
  }


  /**
   * Import issues from configuration (wrapper method).
   *
   * @param ModuleImport $config
   *   The import configuration.
   *
   * @return array
   *   Array with success status and import results.
   */
  public function import(ModuleImport $config): array {
    $result = $this->importFromConfig($config, TRUE);
    
    // Update last run timestamp in State API on successful start.
    if ($result['success']) {
      \Drupal::state()->set('ai_dashboard:last_import:' . $config->id(), \Drupal::time()->getRequestTime());
    }
    
    return $result;
  }

    /**
   * Build import batch.
   *
   * @param ModuleImport $config
   *   The import configuration.
   *
   * @return array
   *   Batch definition to process.
   */
  public function buildImportBatch(ModuleImport $config): array {

    $batchBuilder = new BatchBuilder();

    $max_issues = $config->getMaxIssues() ?? self::DEFAULT_MAX_ISSUES;


    try {

      $page = 0;
      $per_page_max = $this->issueProcessService->getBatchSize($config);
      $total_processed = 0;

      do {
        $per_page = min($per_page_max, $max_issues - $total_processed);
        if($per_page <=0) break;

        $issues_data = $this->issueProcessService->loadPageOfIssues($config, $per_page, $page);

        if (empty($issues_data)) {
          break;
        }

        $page_issues = count($issues_data);
        $total_processed += $page_issues;

        $batchBuilder->addOperation(
          [IssueImportOrchestrationService::class, 'batchOperationProcessIssueBatch'],
        [$issues_data, $config->id()]);
        $page++;
      } while ($page_issues >= $per_page_max && $total_processed < $max_issues);

      return $batchBuilder->toArray();
    }
    catch (\Exception $e) {
      $source_type = $config->getSourceType();
      throw new \Exception("Failed to fetch data from {$source_type}: " . $e->getMessage());
    }
  }


   /**
   * Import issues from a configuration.
   *
   * @param ModuleImport $config
   *   The import configuration.
   * @param bool $use_batch
   *   Whether to use batch processing for large imports.
   *
   * @return array
   *   Import results with counts and messages.
   */
  public function importFromConfig(ModuleImport $config, bool $use_batch = TRUE): array {
    $logger = $this->loggerFactory->get('ai_dashboard');

    try {
      $source_type = $config->getSourceType();
      $max_issues = $config->getMaxIssues() ?? self::DEFAULT_MAX_ISSUES;
      $status_filter = $config->getStatusFilter();

      $logger->info('Starting import from @source for project @project', [
        '@source' => $source_type,
        '@project' => $config->getProjectMachineName(),
      ]);
      // Use batch processing for large imports (over 100 issues)
      // and web requests.
      // Force batch processing for multi-status Drupal.org imports.
      $multi_status_drupal_org = $source_type === "drupal_org" && count($status_filter) > 1;

      if ($use_batch && ($multi_status_drupal_org || ($max_issues > 100 && PHP_SAPI !== 'cli'))) {
        return $this->startBatchImport($config);
      }

      if ($multi_status_drupal_org) {
        return $this->importMultipleStatusesFromDrupalOrg($config, $max_issues);
      }

      return $this->issueProcessService->importFromApi($config, $max_issues);
    }
    catch (\Exception $e) {
      $logger->error('Import failed: @message', ['@message' => $e->getMessage()]);
      return [
        'success' => FALSE,
        'message' => 'Import failed: ' . $e->getMessage(),
        'imported' => 0,
        'skipped' => 0,
        'errors' => 1,
      ];
    }
  }


  /**
   * Start a batch import process.
   *
   * @param ModuleImport $config
   *   The import configuration.
   *
   * @return array
   *   Result array with success status and messages.
   */
  public function startBatchImport(ModuleImport $config): array {
    $logger = $this->loggerFactory->get('ai_dashboard');

    try {
      // Extract configuration parameters.
      $source_type = $config->getSourceType();
      $project_id = $config->getProjectId();
      $max_issues = $config->getMaxIssues() ?? self::DEFAULT_MAX_ISSUES;

      // Get filter parameters.
      $filter_tags = $config->getFilterTags();
      $status_filter = $config->getStatusFilter();
      $date_filter = $config->getDateFilter();

      $batch_size = $this->issueProcessService->getBatchSize($config);

      $logger->info('Starting batch import from @source for project @project with max @max issues', [
        '@source' => $source_type,
        '@project' => $project_id,
        '@max' => $max_issues,
      ]);

      $batch = [
        'title' => $this->t('Importing Issues from @source', ['@source' => ucfirst($source_type)]),
        'operations' => [],
        'init_message' => $this->t('Initializing import of up to @max issues...', ['@max' => $max_issues]),
        'progress_message' => $this->t('Processed @current out of @total operations.'),
        'error_message' => $this->t('Issue import has encountered an error.'),
        'finished' => [self::class, 'batchFinished'],
        'file' => \Drupal::service('extension.list.module')->getPath('ai_dashboard') . '/src/Service/IssueImportOrchestrationService.php',
      ];

      // STRATEGY 1: Multi-status Drupal.org import (multiple operations)
      if ($source_type === 'drupal_org' && count($status_filter) > 1) {

        $issues_per_status = max(1, floor($max_issues / count($status_filter)));
        $batches_per_status = ceil($issues_per_status / $batch_size);

        foreach ($status_filter as $single_status) {
          for ($i = 0; $i < $batches_per_status; $i++) {
          $batch['operations'][] = [
            [self::class, 'batchOperationSingleStatus'],
            [
              $config->id(),
              // Offset.
              $i * $batch_size,
              // Limit.
              min($batch_size, $issues_per_status - ($i * $batch_size)),
              // One specific status to import
              $single_status, 
            ],
          ];
          }
        }
      }
      // STRATEGY 2: Standard pagination-based import (for GitLab or single-status Drupal.org)
      else {

        $estimated_operations = ceil($max_issues / $batch_size);

        for ($i = 0; $i < $estimated_operations; $i++) {
          $offset = $i * $batch_size;
          $limit = min($batch_size, $max_issues - $offset);

          if ($limit <= 0) {
            // No more issues to process.
            break;
          }

          $batch['operations'][] = [
            [self::class, 'batchOperation'],
            [
              $config->id(),
              $offset,
              $limit,
            ],
          ];
        }
      }

      batch_set($batch);

      // For web requests, redirect to batch processing page.
      if (PHP_SAPI !== 'cli') {
        // Store a flag so we know batch was started.
        \Drupal::state()->set('ai_dashboard.batch_start_time', time());

        // Use batch_process() to redirect to the batch page.
        $response = batch_process('/ai-dashboard/admin');
        return [
          'success' => TRUE,
          'message' => $this->t('Batch import started.'),
          'redirect' => TRUE,
          'imported' => 0,
          'skipped' => 0,
          'errors' => 0,
        ];
      }

      // For CLI/drush execution, process immediately.
      $batch =& batch_get();
      $batch['progressive'] = FALSE;
      batch_process();

      return [
        'success' => TRUE,
        'message' => $this->t('Batch import completed via CLI.'),
        // Will be updated by batch.
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
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
  public static function batchOperation($config_id, $offset, $limit, &$context) {
    $logger = \Drupal::service('logger.factory')->get('ai_dashboard');
    /** @var ModuleImport $config */
    $config = \Drupal::entityTypeManager()
      ->getStorage('module_import')
      ->load($config_id);
    if (!$config) {
      $context['results']['errors'][] = 'Configuration not found';
      return;
    }

    try {

      $status_filter = $config->getStatusFilter();
      $process_service = \Drupal::service('ai_dashboard.issue_import_process');

      $source_type = $config->getSourceType();

      if ($source_type === 'drupal_org') {
        // $status_filter is either empty or has only once status (multi-status DO issues are handled by batchOperationSingleStatus)
        $single_status = reset($status_filter ?? []);
        $results = $process_service->importFromApiBatch($config, $offset, $limit, $single_status);
      } else {
        $results = $process_service->importFromApiBatch($config, $offset, $limit);
      }

      

      // Update context with results.
      if (!isset($context['results']['imported'])) {
        $context['results']['imported'] = 0;
        $context['results']['updated'] = 0;
        $context['results']['skipped'] = 0;
        $context['results']['errors'] = 0;
        $context['results']['total_operations'] = 0;
      }

      // Initialize sandbox progress tracking if not present.
      if (!isset($context['sandbox']['current_operation'])) {
        $context['sandbox']['current_operation'] = 0;
      }
      if (!isset($context['sandbox']['total_processed'])) {
        $context['sandbox']['total_processed'] = 0;
      }

      $context['results']['imported'] += $results['imported'];
      $context['results']['updated'] += $results['updated'];
      $context['results']['skipped'] += $results['skipped'];
      $context['results']['errors'] += $results['errors'];
      $context['results']['total_operations']++;

      // Update progress tracking.
      $context['sandbox']['current_operation']++;
      $context['sandbox']['total_processed'] += $results['imported'] + $results['skipped'];

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

      $context['finished'] = 1;
    }
    catch (\Exception $e) {
      $logger->error('Batch operation failed: @message', ['@message' => $e->getMessage()]);
      $context['results']['errors']++;
      $context['finished'] = 1;
    }
  }

  /**
   * Batch operation callback for single status import.
   */
  public static function batchOperationSingleStatus($config_id, $offset, $limit, $single_status, &$context) {
    $logger = \Drupal::service('logger.factory')->get('ai_dashboard');
    
    $config = \Drupal::entityTypeManager()
      ->getStorage('module_import')
      ->load($config_id);
    if (!$config) {
      $context['results']['errors'][] = 'Configuration not found';
      return;
    }

    // Initialize sandbox on first operation.
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
      
      $process_service = \Drupal::service('ai_dashboard.issue_import_process');

      // Import all issues for this single status.
      $results = $process_service->importFromApi(
        $config,
        $limit,
        $single_status,
      );

      // Update context with results.
      $context['results']['imported'] += $results['imported'];
      $context['results']['skipped'] += $results['skipped'];
      $context['results']['errors'] += $results['errors'];
      $context['results']['total_operations']++;

      // Update user message.
      $context['message'] = t('Completed @status: @imported imported, @skipped skipped', [
        '@status' => $single_status,
        '@imported' => $results['imported'],
        '@skipped' => $results['skipped'],
      ]);

      $logger->info('Single status import completed for @status: @imported imported, @skipped skipped, @errors errors', [
        '@status' => $single_status,
        '@imported' => $results['imported'],
        '@skipped' => $results['skipped'],
        '@errors' => $results['errors'],
      ]);

      // Mark this operation as finished.
      $context['finished'] = 1;

    }
    catch (\Exception $e) {
      $logger->error('Single status batch operation failed for @status: @message', [
        '@status' => $single_status,
        '@message' => $e->getMessage(),
      ]);
      $context['results']['errors']++;
      // Continue to next operation even on error.
      $context['finished'] = 1;
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

    // Clear batch state.
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

      // Invalidate caches after batch import completion.
      static::invalidateBatchImportCaches();

    }
    else {
      $message = t('❌ Import completed with some errors.');
      $messenger->addError($message);
      $messenger->addMessage(t('Check the <a href="@url">log messages</a> for detailed error information.', [
        '@url' => '/admin/reports/dblog',
      ]));

      $logger->error('Batch import completed with errors');
    }
  }

/**
   * Batch process callback.
   */
  public static function batchProcess($config_id, $offset, $limit, &$context) {
    $import_service = \Drupal::service('ai_dashboard.issue_import_process');
    $config = \Drupal::entityTypeManager()->getStorage('node')->load($config_id);

    if (!$config) {
      $context['results']['errors'][] = 'Configuration not found';
      return;
    }

    try {
      $results = $import_service->importFromApiBatch($config, $offset, $limit);

      // Update context with results.
      if (!isset($context['results']['imported'])) {
        $context['results']['imported'] = 0;
        $context['results']['updated'] = 0;
        $context['results']['skipped'] = 0;
        $context['results']['errors'] = 0;
      }

      $context['results']['imported'] += $results['imported'];
      $context['results']['updated'] += $results['updated'];
      $context['results']['skipped'] += $results['skipped'];
      $context['results']['errors'] += $results['errors'];

      $context['message'] = t('Processed @imported issues (offset @offset)', [
        '@imported' => $results['imported'],
        '@offset' => $offset,
      ]);

    }
    catch (\Exception $e) {
      $context['results']['errors'][] = $e->getMessage();
    }
  }

  protected function importMultipleStatusesFromDrupalOrg(ModuleImport $config, int $max_issues){
      $logger = $this->loggerFactory->get('ai_dashboard');

      $status_filter = $config->getStatusFilter();

      $combined_results = [
        'success' => TRUE,
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'message' => '',
      ];

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

      $status_results = [];
      $issues_per_status = max(1, floor($max_issues / count($status_filter)));

      // Import each status separately.
      foreach ($status_filter as $single_status) {
        $status_name = $status_names[$single_status] ?? "Status $single_status";
        $logger->info('Importing @status issues', ['@status' => $status_name]);

        try {
          // Import this single status with proportional limit.
          $single_results = $this->issueProcessService->importFromApi($config, $max_issues, $single_status);

          // Combine results.
          $combined_results['imported'] += $single_results['imported'];
          $combined_results['updated'] += $single_results['updated'];
          $combined_results['skipped'] += $single_results['skipped'];
          $combined_results['errors'] += $single_results['errors'];

          $status_results[] = "$status_name: {$single_results['imported']} imported, {$single_results['updated']} updated";

          if (!$single_results['success']) {
            $combined_results['success'] = FALSE;
          }

        }
        catch (\Exception $e) {
          $logger->error('Failed to import @status: @message', [
            '@status' => $status_name,
            '@message' => $e->getMessage(),
          ]);
          $combined_results['errors']++;
          $combined_results['success'] = FALSE;
          $status_results[] = "$status_name: ERROR";
        }
      }

      $combined_results['message'] = sprintf(
        'Multi-status import completed: %d imported, %d updated, %d skipped (%s)',
        $combined_results['imported'],
        $combined_results['updated'],
        $combined_results['skipped'],
        implode(', ', $status_results)
      );

      return $combined_results;
  }

  /**
   * Invalidate caches after batch import operations.
   */
  protected static function invalidateBatchImportCaches() {
    // Invalidate specific cache tags for dashboard data.
    $cache_tags = [
      'ai_dashboard:calendar',
      'node_list:ai_issue',
      'node_list:ai_contributor',
      'ai_dashboard:import',
    ];
    \Drupal::service('cache_tags.invalidator')->invalidateTags($cache_tags);

    // Invalidate dynamic page cache for dashboard pages.
    \Drupal::service('cache.dynamic_page_cache')->deleteAll();

    // Invalidate render cache for views and blocks.
    \Drupal::service('cache.render')->deleteAll();
  }

  /**
   * Batch operation callback for single status import.
   */
  public static function batchOperationProcessIssueBatch(
    array $issues,
    string $config_id,
    &$context) {
    $logger = \Drupal::service('logger.factory')->get('ai_dashboard');
    /** @var ModuleImport $config */
    $config = \Drupal::entityTypeManager()
      ->getStorage('module_import')
      ->load($config_id);
    assert($config instanceof ModuleImport);
    $sourceType = $config->getSourceType();

    // Initialize sandbox on first operation.
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['results'] = [
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
        'total_operations' => 0,
      ];
    }

    // Get import service and import this single status.
    /** @var IssueImportProcessService $import_service */
    $import_service = \Drupal::service('ai_dashboard.issue_import_process');
    foreach ($issues as $issue) {
      try {
        $import_service->processIssue($issue, $config);
      }
      catch (\Exception $e) {
        $logger->error($e->getMessage());
        $context['results']['errors']++;
        // Continue to next operation even on error.
        $context['finished'] = 1;
      }
    }
  }


}
