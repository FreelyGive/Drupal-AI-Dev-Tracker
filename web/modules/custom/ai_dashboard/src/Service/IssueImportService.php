<?php

namespace Drupal\ai_dashboard\Service;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeStorageInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for importing issues from external APIs.
 */
class IssueImportService {

  const USER_AGENT = 'AI Dashboard Module/1.0';

  /**
   * Limit of per-page results using drupal.org REST API.
   */
  const BATCH_SIZE = 50;

  /**
   * Maximum number of tries before giving up.
   */
  const MAX_TRIES = 3;

  /**
   * Number of seconds to wait before retrying the request.
   */
  const RETRY_AFTER = 30;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The tag mapping service.
   *
   * @var \Drupal\ai_dashboard\Service\TagMappingService
   */
  protected $tagMappingService;

  /**
   * Constructs a new IssueImportService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\ai_dashboard\Service\TagMappingService $tag_mapping_service
   *   The tag mapping service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory, TagMappingService $tag_mapping_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->tagMappingService = $tag_mapping_service;
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
    // Build the API URL for single status import.
    $url = 'https://www.drupal.org/api-d7/node.json';
    $max_issues = $config->getMaxIssues();
    $params = [
      'type' => 'project_issue',
      'field_project' => $config->getProjectId(),
      'sort' => 'created',
      'direction' => 'DESC',
    ];

    if ($filter = $config->getStatusFilter()) {
      if (!is_array($filter)) {
        $filter = array_filter(explode(',', $filter));
      }
      $params['field_issue_status'] = count($filter) > 1 ? $filter : reset($filter);
    }
    if ($filter = $this->buildTagIds($config->getFilterTags())) {
      $params['taxonomy_vocabulary_9'] = implode(',', $filter);
    }
    if ($component = $config->getFilterComponent()) {
      $params['field_issue_component'] = $component;
    }

    // Add date filter if specified.
    if ($config->getDateFilter()) {
      $timestamp = strtotime($config->getDateFilter());
      if ($timestamp) {
        $params['created'] = '>=' . $timestamp;
      }
    }

    try {
      $page = 0;
      $per_page = self::BATCH_SIZE;
      $total_processed = 0;

      do {
        // Set pagination parameters.
        $current_params = $params;
        $current_params['limit'] = min($per_page, $max_issues - $total_processed);
        $current_params['page'] = $page;

        $response = $this->httpClient->request('GET', $url, [
          'query' => $current_params,
          // Increased timeout for large imports.
          'timeout' => 60,
          'headers' => [
            'User-Agent' => self::USER_AGENT,
          ],
        ]);

        $data = json_decode($response->getBody()->getContents(), TRUE);

        if (!isset($data['list']) || !is_array($data['list'])) {
          throw new \Exception('Invalid response format from drupal.org API');
        }

        $page_issues = count($data['list']);
        if ($page_issues === 0) {
          // No more issues.
          break;
        }
        $total_processed += $page_issues;
        $batchBuilder->addOperation(
          [IssueBatchImportService::class, 'batchOperationProcessIssueBatch'],
          [$data['list'], $config->id()]);
        $page++;
      }
        // Continue if we got a full page and haven't reached the limit.
      while ($page_issues === $per_page && $total_processed < $max_issues);
      return $batchBuilder->toArray();
    }
    catch (RequestException $e) {
      throw new \Exception('Failed to fetch data from drupal.org: ' . $e->getMessage());
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
      $project_id = $this->resolveProjectId($config);
      $filter_tags = $config->getFilterTags();
      $status_filter = $config->getStatusFilter();
      $max_issues = $config->getMaxIssues();
      $max_issues = $max_issues ? (int) $max_issues : 1000;
      $date_filter = $config->getDateFilter();

      $logger->info('Starting import from @source for project @project', [
        '@source' => $source_type,
        '@project' => $project_id,
      ]);

      // Use batch processing for large imports (over 100 issues)
      // and web requests.
      // Force batch processing for multi-status imports.
      if ($use_batch && (count($status_filter) > 1 || ($max_issues > 100 && PHP_SAPI !== 'cli'))) {
        $batch_service = \Drupal::service('ai_dashboard.batch_import');
        return $batch_service->startBatchImport($config);
      }

      switch ($source_type) {
        case 'drupal_org':
          return $this->importFromDrupalOrg($project_id, $filter_tags, $status_filter, $max_issues, $date_filter, $config);

        case 'gitlab':
          return $this->importFromGitLab($project_id, $filter_tags, $status_filter, $max_issues, $date_filter, $config);

        case 'github':
          return $this->importFromGitHub($project_id, $filter_tags, $status_filter, $max_issues, $date_filter, $config);

        default:
          throw new \InvalidArgumentException("Unsupported source type: {$source_type}");
      }
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
   * Start a batch import process for large imports.
   */
  protected function startBatchImport(ModuleImport $config, string $source_type, string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter): array {
    $batch = [
      'title' => t('Importing Issues'),
      'operations' => [],
      'init_message' => t('Starting issue import...'),
      'progress_message' => t('Processed @current out of @total batches.'),
      'error_message' => t('Issue import has encountered an error.'),
      'finished' => '\Drupal\ai_dashboard\Service\IssueImportService::batchFinished',
      'file' => \Drupal::service('extension.list.module')->getPath('ai_dashboard') . '/src/Service/IssueImportService.php',
    ];

    // Calculate number of batches (50 issues per batch due to API limit)
    $batch_size = 50;
    
    // Handle multiple status filters by creating separate batch operations for each status
    if (count($status_filter) > 1) {
      $issues_per_status = max(1, floor($max_issues / count($status_filter)));
      $batches_per_status = ceil($issues_per_status / $batch_size);
      
      foreach ($status_filter as $single_status) {
        for ($i = 0; $i < $batches_per_status; $i++) {
          $batch['operations'][] = [
            '\Drupal\ai_dashboard\Service\IssueImportService::batchProcess',
            [
              $config->id(),
              $source_type,
              $project_id,
              $filter_tags,
              [$single_status], // Single status array
              // Offset.
              $i * $batch_size,
              // Limit.
              min($batch_size, $issues_per_status - ($i * $batch_size)),
              $date_filter,
            ],
          ];
        }
      }
    } else {
      // Single status or no status filter - use original logic
      $num_batches = ceil($max_issues / $batch_size);
      
      for ($i = 0; $i < $num_batches; $i++) {
        $batch['operations'][] = [
          '\Drupal\ai_dashboard\Service\IssueImportService::batchProcess',
          [
            $config->id(),
            $source_type,
            $project_id,
            $filter_tags,
            $status_filter,
            // Offset.
            $i * $batch_size,
            // Limit.
            min($batch_size, $max_issues - ($i * $batch_size)),
            $date_filter,
          ],
        ];
      }
    }

    batch_set($batch);

    // For web requests, redirect to batch processing page.
    if (PHP_SAPI !== 'cli') {
      // Store a flag so we know batch was started.
      \Drupal::state()->set('ai_dashboard.batch_started', time());

      // Use batch_process() to redirect to the batch page.
      $response = batch_process('/ai-dashboard/admin');

      return [
        'success' => TRUE,
        'message' => 'Batch import started. Processing...',
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
      'message' => 'Batch import completed via CLI.',
    // Will be updated by batch.
      'imported' => 0,
      'skipped' => 0,
      'errors' => 0,
    ];
  }

  /**
   * Batch process callback.
   */
  public static function batchProcess($config_id, $source_type, $project_id, $filter_tags, $status_filter, $offset, $limit, $date_filter, &$context) {
    $import_service = \Drupal::service('ai_dashboard.issue_import');
    $config = \Drupal::entityTypeManager()->getStorage('node')->load($config_id);

    if (!$config) {
      $context['results']['errors'][] = 'Configuration not found';
      return;
    }

    try {
      switch ($source_type) {
        case 'drupal_org':
          $results = $import_service->importFromDrupalOrgBatch($project_id, $filter_tags, $status_filter, $offset, $limit, $date_filter, $config);
          break;

        default:
          throw new \InvalidArgumentException("Batch import not supported for source type: {$source_type}");
      }

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

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();

    // Clear the batch started flag.
    \Drupal::state()->delete('ai_dashboard.batch_started');

    if ($success) {
      $imported = $results['imported'] ?? 0;
      $updated = $results['updated'] ?? 0;
      $skipped = $results['skipped'] ?? 0;
      $errors = is_array($results['errors'] ?? []) ? count($results['errors']) : ($results['errors'] ?? 0);

      $messenger->addMessage(t('✅ Import completed successfully! @imported issues imported, @updated updated, @skipped skipped, @errors errors', [
        '@imported' => $imported,
        '@updated' => $updated,
        '@skipped' => $skipped,
        '@errors' => $errors,
      ]));

      if ($imported > 0) {
        $messenger->addMessage(t('You can view the imported issues at <a href="@url">Admin Tools → Issues</a>', [
          '@url' => '/ai-dashboard/admin/issues',
        ]));
      }
    }
    else {
      $messenger->addError(t('❌ Import finished with errors. Check the logs for details.'));
    }
  }

  /**
   * Import issues from drupal.org API.
   *
   * @param string $project_id
   *   The project ID (nid).
   * @param array $filter_tags
   *   Tags to filter by.
   * @param array $status_filter
   *   Status IDs to filter by.
   * @param int $max_issues
   *   Maximum issues to import.
   * @param string|null $date_filter
   *   Date filter for created date.
   * @param ModuleImport $config
   *   The import configuration node.
   *
   * @return array
   *   Import results.
   */
  public function importFromDrupalOrg(string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter, ModuleImport $config): array {
    // Clear import session cache at start of import.
    $this->clearImportSessionCache();

    $logger = $this->loggerFactory->get('ai_dashboard');

    // Build the API URL for single status import.
    $url = 'https://www.drupal.org/api-d7/node.json';
    $params = [
      'type' => 'project_issue',
      'field_project' => $project_id,
      'limit' => $max_issues,
      'sort' => 'created',
      'direction' => 'DESC',
    ];

    // Handle multiple status filters by processing each one separately
    // as drupal.org API doesn't support comma-separated status values reliably
    if (!empty($status_filter) && count($status_filter) > 1) {
      return $this->importMultipleStatuses($project_id, $filter_tags, $status_filter, $max_issues, $date_filter, $config);
    }
    
    if (!empty($status_filter)) {
      $params['field_issue_status'] = $status_filter[0];
    }

    // Add component filter if specified.
    if ($component = $config->getFilterComponent()) {
      $params['field_issue_component'] = $component;
    }

    // Add date filter if specified.
    if ($date_filter) {
      $timestamp = strtotime($date_filter);
      if ($timestamp) {
        $params['created'] = '>=' . $timestamp;
      }
    }

    try {
      $results = [
        'success' => TRUE,
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'message' => '',
      ];

      $page = 0;
      // drupal.org API limit.
      $per_page = 50;
      $total_processed = 0;

      do {
        // Set pagination parameters.
        $current_params = $params;
        $current_params['limit'] = min($per_page, $max_issues - $total_processed);
        $current_params['page'] = $page;

        $response = $this->httpClient->request('GET', $url, [
          'query' => $current_params,
        // Increased timeout for large imports.
          'timeout' => 60,
          'headers' => [
            'User-Agent' => self::USER_AGENT,
          ],
        ]);

        $data = json_decode($response->getBody()->getContents(), TRUE);

        if (!isset($data['list']) || !is_array($data['list'])) {
          throw new \Exception('Invalid response format from drupal.org API');
        }

        $page_issues = count($data['list']);
        if ($page_issues === 0) {
          // No more issues.
          break;
        }

        foreach ($data['list'] as $issue_data) {
          if ($total_processed >= $max_issues) {
            // Break out of both loops.
            break 2;
          }

          try {
            // Filter by tags if specified.
            if (!empty($filter_tags) && !$this->issueMatchesTagFilter($issue_data, $filter_tags)) {
              $results['skipped']++;
              $total_processed++;
              continue;
            }

            $result = $this->processIssue($issue_data, $config);
            if ($result === 'created') {
              $results['imported']++;
            } elseif ($result === 'updated') {
              $results['updated']++;
            } elseif ($result === 'skipped') {
              $results['skipped']++;
            }
            $total_processed++;
          }
          catch (\Exception $e) {
            $logger->warning('Failed to process issue @id: @message', [
              '@id' => $issue_data['nid'] ?? 'unknown',
              '@message' => $e->getMessage(),
            ]);
            $results['errors']++;
            $total_processed++;
          }
        }

        $page++;

        // Continue if we got a full page and haven't reached the limit.
      } while ($page_issues === $per_page && $total_processed < $max_issues);

      $results['message'] = sprintf(
        'Import completed: %d imported, %d updated, %d skipped, %d errors',
        $results['imported'],
        $results['updated'],
        $results['skipped'],
        $results['errors']
      );

      return $results;
    }
    catch (RequestException $e) {
      throw new \Exception('Failed to fetch data from drupal.org: ' . $e->getMessage());
    }
  }

  /**
   * Import multiple status filters separately to ensure all issues are captured.
   */
  protected function importMultipleStatuses(string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter, ModuleImport $config): array {
    $logger = $this->loggerFactory->get('ai_dashboard');

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
        $single_results = $this->importFromDrupalOrg($project_id, $filter_tags, [$single_status], $issues_per_status, $date_filter, $config);

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
   * Import issues from drupal.org API for batch processing.
   */
  public function importFromDrupalOrgBatch(string $project_id, array $filter_tags, array $status_filter, int $offset, int $limit, ?string $date_filter, ModuleImport $config): array {
    // Clear import session cache if this is the first batch (offset 0)
    if ($offset === 0) {
      $this->clearImportSessionCache();
    }

    $logger = $this->loggerFactory->get('ai_dashboard');

    // For batch processing, each operation handles exactly one API page.
    // The offset and limit should align with API page boundaries.
    $api_page_size = 50;
    $page_number = floor($offset / $api_page_size);

    $results = [
      'success' => TRUE,
      'imported' => 0,
      'updated' => 0,
      'skipped' => 0,
      'errors' => 0,
      'message' => '',
    ];

    // Build the API URL for this specific page.
    $url = 'https://www.drupal.org/api-d7/node.json';
    $params = [
      'type' => 'project_issue',
      'field_project' => $project_id,
      'limit' => min($api_page_size, $limit),
      'page' => $page_number,
      'sort' => 'created',
      'direction' => 'DESC',
    ];

    // Add status filter if specified - use first status only for batch processing
    // Multiple statuses are handled by creating separate batch operations
    if (!empty($status_filter)) {
      $params['field_issue_status'] = is_array($status_filter) ? $status_filter[0] : $status_filter;
    }

    // Add component filter if specified.
    if ($component = $config->getFilterComponent()) {
      $params['field_issue_component'] = $component;
    }

    // Add date filter if specified.
    if ($date_filter) {
      $timestamp = strtotime($date_filter);
      if ($timestamp) {
        $params['created'] = '>=' . $timestamp;
      }
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => $params,
        'timeout' => 60,
        'headers' => [
          'User-Agent' => self::USER_AGENT,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (!isset($data['list']) || !is_array($data['list'])) {
        throw new \Exception('Invalid API response format');
      }

      $page_results = count($data['list']);
      if ($page_results === 0) {
        $results['message'] = sprintf('Page %d: No more issues available', $page_number);
        return $results;
      }

      $total_processed = 0;
      foreach ($data['list'] as $issue_data) {
        if ($total_processed >= $limit) {
          // Don't exceed the requested limit for this batch.
          break;
        }

        try {
          // Filter by tags if specified.
          if (!empty($filter_tags) && !$this->issueMatchesTagFilter($issue_data, $filter_tags)) {
            $results['skipped']++;
            $total_processed++;
            continue;
          }

          $result = $this->processIssue($issue_data, $config);
          if ($result === 'created') {
            $results['imported']++;
          } elseif ($result === 'updated') {
            $results['updated']++;
          } elseif ($result === 'skipped') {
            $results['skipped']++;
          }
          $total_processed++;
        }
        catch (\Exception $e) {
          $logger->warning('Failed to process issue @id: @message', [
            '@id' => $issue_data['nid'] ?? 'unknown',
            '@message' => $e->getMessage(),
          ]);
          $results['errors']++;
          $total_processed++;
        }
      }

      $results['message'] = sprintf(
        'Page %d: %d imported, %d updated, %d skipped, %d errors',
        $page_number,
        $results['imported'],
        $results['updated'],
        $results['skipped'],
        $results['errors']
      );

    }
    catch (\Exception $e) {
      // Check if this is a 404 error (no more pages available)
      if (strpos($e->getMessage(), '404') !== FALSE || strpos($e->getMessage(), 'Page doesn') !== FALSE) {
        $results['message'] = sprintf('Page %d: No more data available (reached end of results)', $page_number);
        $logger->info('Reached end of available data at page @page', ['@page' => $page_number]);
      }
      else {
        $logger->error('API request failed for page @page: @message', [
          '@page' => $page_number,
          '@message' => $e->getMessage(),
        ]);
        $results['errors']++;
        $results['message'] = sprintf('Page %d: API request failed - %s', $page_number, $e->getMessage());
      }
    }

    return $results;
  }

  /**
   * Import from GitLab (placeholder for future implementation).
   */
  protected function importFromGitLab(string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter, ModuleImport $config): array {
    throw new \Exception('GitLab import not yet implemented');
  }

  /**
   * Import from GitHub (placeholder for future implementation).
   */
  protected function importFromGitHub(string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter, ModuleImport $config): array {
    throw new \Exception('GitHub import not yet implemented');
  }

  /**
   * Process a single issue from API data.
   *
   * @param array $issue_data
   *   The issue data from API.
   * @param string $source_type
   *   The source type.
   * @param ModuleImport $config
   *   The import configuration.
   */
  public function processIssue(array $issue_data, ModuleImport $config): string {
    // Map API data to Drupal fields based on source.
    $source_type = $config->getSourceType();
    $mapped_data = $this->mapIssueData($issue_data, $source_type, $config);

    // Check for existing issue by external ID.
    $existing = $this->findExistingIssue($mapped_data['external_id'], $source_type);

    if ($existing) {
      // Update existing issue.
      $this->updateIssue($existing, $mapped_data);
      return 'updated';
    }
    elseif ($this->shouldCreateIssue($config, $issue_data)) {
      $this->createIssue($mapped_data);
      return 'created';
    }
    
    return 'skipped';
  }

  /**
   * Map API issue data to Drupal field structure.
   *
   * @param array $issue_data
   *   Raw API data.
   * @param string $source_type
   *   Source type.
   * @param ModuleImport $config
   *   The import configuration.
   *
   * @return array
   *   Mapped data.
   */
  protected function mapIssueData(array $issue_data, string $source_type, ModuleImport $config): array {
    switch ($source_type) {
      case 'drupal_org':
        return $this->mapDrupalOrgIssue($issue_data, $config);

      default:
        throw new \InvalidArgumentException("Unsupported source type: {$source_type}");
    }
  }

  /**
   * Map drupal.org issue data.
   *
   * @param array $issue_data
   *   Raw drupal.org API data.
   * @param ModuleImport $config
   *   The import configuration node.
   *
   * @return array
   *   Mapped data.
   */
  protected function mapDrupalOrgIssue(array $issue_data, ModuleImport $config): array {
    /** @var NodeStorageInterface $nodeStorage */
    static $nodeStorage;
    // Array of contributor nodes, keyed by d.o. user id.
    static $contributors = [];

    // Extract tags from the issue.
    $tags = [];
    if (isset($issue_data['taxonomy_vocabulary_9']) && is_array($issue_data['taxonomy_vocabulary_9'])) {
      foreach ($issue_data['taxonomy_vocabulary_9'] as $tag) {
        if (isset($tag['name'])) {
          // Tag has name (full API response)
          $tags[] = $tag['name'];
        }
        elseif (isset($tag['id'])) {
          // Tag has only ID, resolve it via API.
          $tag_name = $this->resolveTagName($tag['id']);
          if ($tag_name) {
            $tags[] = $tag_name;
          }
        }
      }
    }

    // Process tags through mapping service.
    $processed_tags = $this->tagMappingService->processTags($tags);

    // Extract drupal.org assignee information.
    $do_assignee = '';
    $assignee_id = 0;

    if (isset($issue_data['field_issue_assigned']) && is_array($issue_data['field_issue_assigned'])) {
      if (isset($issue_data['field_issue_assigned']['id'])) {
        // We have the user ID, need to resolve it to username.
        $user_id = $issue_data['field_issue_assigned']['id'];
        if (!$nodeStorage) {
          $nodeStorage = $this->entityTypeManager->getStorage('node');
        }
        if (empty($contributors[$user_id])) {
          $candidates = $nodeStorage->loadByProperties(
            ['field_drupal_userid' => $user_id]);
          if (!empty($candidates)) {
            $contributors[$user_id] = reset($candidates);
          }
          else {
            $userData = $this->getUserData($user_id);
            // Can't find user by d.o. user id, but can by username?
            // Update local userid.
            if (!empty($userData['name'])) {
              $candidates = $nodeStorage->loadByProperties([
                'type' => 'ai_contributor',
                'field_drupal_username' => $userData['name'],
              ]);
              if (!empty($candidates)) {
                $contributors[$user_id] = reset($candidates);
                $contributors[$user_id]->set('field_drupal_userid', $user_id);
                $contributors[$user_id]->save();
              }
            }
          }
        }
        if (!empty($contributors[$user_id])) {
          $do_assignee = $contributors[$user_id]->get('field_drupal_username')
            ->getString();
          $assignee_id = $contributors[$user_id]->id();
        }
      }
    }

    // Log assignee resolution issues for debugging.
    if (isset($issue_data['nid']) && isset($issue_data['field_issue_assigned']) && empty($do_assignee)) {
      \Drupal::logger('ai_dashboard')->warning('Failed to resolve assignee for issue @nid, assigned field: @assigned', [
        '@nid' => $issue_data['nid'],
        '@assigned' => json_encode($issue_data['field_issue_assigned']),
      ]);
    }

    // Find or create the module node.
    $module_node_id = $this->findOrCreateModule($config->getProjectMachineName());

    return [
      'external_id' => $issue_data['nid'],
      'source_type' => 'drupal_org',
      'title' => $issue_data['title'] ?? 'Untitled Issue',
      'issue_number' => $issue_data['nid'],
      'issue_url' => $issue_data['url'] ?? '',
      'status' => $this->mapDrupalOrgStatus($issue_data['field_issue_status'] ?? '1'),
      'priority' => $this->mapDrupalOrgPriority($issue_data['field_issue_priority'] ?? '300'),
      'category' => $processed_tags['category'] ?? 'general',
      'tags' => $tags,
      'module' => $module_node_id,
      'do_assignee' => $do_assignee,
      'assignee_id' => $assignee_id,
      'created' => $issue_data['created'] ?? time(),
      'changed' => $issue_data['changed'] ?? time(),
    ];
  }

  /**
   * Map drupal.org status to our values.
   */
  protected function mapDrupalOrgStatus(string $status_id): string {
    $status_map = [
      '1' => 'active',
      '8' => 'needs_review',
      '13' => 'needs_work',
      '14' => 'rtbc',
      '2' => 'fixed',
      '3' => 'closed',
    ];

    return $status_map[$status_id] ?? 'active';
  }

  /**
   * Map drupal.org priority to our values.
   */
  protected function mapDrupalOrgPriority(string $priority_id): string {
    $priority_map = [
      '400' => 'critical',
      '300' => 'major',
      '200' => 'normal',
      '100' => 'minor',
    ];

    return $priority_map[$priority_id] ?? 'normal';
  }

  /**
   * Check if issue matches status filter.
   */
  protected function issueMatchesStatusFilter(array $issue_data, array $status_filter): bool {
    if (empty($status_filter)) {
      return TRUE;
    }

    $issue_status = $issue_data['field_issue_status'] ?? '';
    return in_array($issue_status, $status_filter);
  }

  /**
   * Resolve a drupal.org user ID to username via API.
   *
   * @param string $user_id
   *   The drupal.org user ID.
   *
   * @return array
   *   user data returned from d.o. REST API.
   */
  protected function getUserData(string $user_id): array {
    // Validate user ID format.
    if (empty($user_id) || !is_numeric($user_id)) {
      return [];
    }

    $result = $this->requestWithRetry('GET',
      "https://www.drupal.org/api-d7/user/{$user_id}.json");
    if (!$result['success']) {
      return [];
    }
    return empty($result['data']['name']) ? [] : $result['data'];
  }

  /**
   * Check if issue matches tag filter.
   */
  protected function issueMatchesTagFilter(array $issue_data, array $filter_tags): bool {
    if (empty($filter_tags)) {
      return TRUE;
    }

    $issue_tags = [];
    if (isset($issue_data['taxonomy_vocabulary_9']) && is_array($issue_data['taxonomy_vocabulary_9'])) {
      foreach ($issue_data['taxonomy_vocabulary_9'] as $tag) {
        if (!isset($tag['name'])) {
          $tag['name'] = $this->resolveTagName($tag['id']);
        }
        if (isset($tag['name'])) {
          $issue_tags[] = $tag['name'];
        }
      }
    }

    foreach ($filter_tags as $filter_tag) {
      if (in_array($filter_tag, $issue_tags)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Static cache of processed issues in the current import session.
   *
   * @var array
   */
  protected static $importSessionCache = [];

  /**
   * Clear the import session cache.
   */
  protected function clearImportSessionCache(): void {
    static::$importSessionCache = [];
  }

  /**
   * Find existing issue by external ID.
   */
  protected function findExistingIssue(string $external_id, string $source_type): ?Node {
    // First check if we've already processed this issue in this import session.
    if (isset(static::$importSessionCache[$external_id])) {
      $node_storage = $this->entityTypeManager->getStorage('node');
      return $node_storage->load(static::$importSessionCache[$external_id]);
    }

    $node_storage = $this->entityTypeManager->getStorage('node');

    // Clear entity cache to ensure fresh query.
    $node_storage->resetCache();

    $query = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_number', $external_id)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $result = $query->execute();

    if (!empty($result)) {
      $node_id = reset($result);
      // Cache this for the current import session.
      static::$importSessionCache[$external_id] = $node_id;
      return $node_storage->load($node_id);
    }

    return NULL;
  }

  /**
   * Create new issue.
   */
  protected function createIssue(array $mapped_data): Node {
    $issue = Node::create([
      'type' => 'ai_issue',
      'title' => $mapped_data['title'],
      'field_issue_number' => $mapped_data['issue_number'],
      'field_issue_url' => [
        'uri' => $mapped_data['issue_url'],
        'title' => 'Issue #' . $mapped_data['issue_number'],
      ],
      'field_issue_status' => $mapped_data['status'],
      'field_issue_priority' => $mapped_data['priority'],
      'field_issue_category' => $mapped_data['category'],
      'field_issue_tags' => $mapped_data['tags'],
      'field_issue_module' => $mapped_data['module'] ?? '',
      'field_issue_do_assignee' => $mapped_data['do_assignee'] ?? '',
      'created' => $mapped_data['created'],
      'changed' => $mapped_data['changed'],
      'status' => 1,
    ]);
    if (!empty($mapped_data['assignee_id'])) {
      $issue->set('field_issue_assignees', $mapped_data['assignee_id']);
    }

    $issue->save();

    // Cache this newly created issue to prevent duplicates in
    // the same import session.
    static::$importSessionCache[$mapped_data['issue_number']] = $issue->id();

    $this->invalidateImportCaches();
    return $issue;
  }

  /**
   * Update existing issue.
   */
  protected function updateIssue(Node $issue, array $mapped_data): Node {
    static $nodeStorage;
    static $contributors = [];
    // Log the update for debugging.
    $logger = $this->loggerFactory->get('ai_dashboard');
    $logger->info('Updating issue #@number: @title', [
      '@number' => $mapped_data['issue_number'],
      '@title' => $mapped_data['title'],
    ]);

    $issue->setTitle($mapped_data['title']);
    $issue->set('field_issue_url', [
      'uri' => $mapped_data['issue_url'],
      'title' => 'Issue #' . $mapped_data['issue_number'],
    ]);
    $issue->set('field_issue_status', $mapped_data['status']);
    $issue->set('field_issue_priority', $mapped_data['priority']);
    $issue->set('field_issue_category', $mapped_data['category']);
    $issue->set('field_issue_tags', $mapped_data['tags']);
    $issue->set('field_issue_module', $mapped_data['module'] ?? '');
    $issue->set('field_issue_do_assignee', $mapped_data['do_assignee'] ?? '');
    if (!empty($mapped_data['assignee_id'])) {
      $issue->set('field_issue_assignees', $mapped_data['assignee_id']);
    }

    $issue->setChangedTime($mapped_data['changed']);

    $issue->save();

    // Cache this updated issue in the session.
    static::$importSessionCache[$mapped_data['issue_number']] = $issue->id();

    $this->invalidateImportCaches();
    return $issue;
  }

  /**
   * Delete all imported issues.
   *
   * @return int
   *   Number of issues deleted.
   */
  public function deleteAllIssues(): int {
    $node_storage = $this->entityTypeManager->getStorage('node');

    $issue_ids = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($issue_ids)) {
      $issues = $node_storage->loadMultiple($issue_ids);
      $node_storage->delete($issues);

      // Clean up any orphaned field data.
      $this->cleanupOrphanedFieldData();

      // Invalidate caches after bulk deletion.
      $this->invalidateImportCaches();

      return count($issues);
    }

    return 0;
  }

  /**
   * Clean up orphaned field data for deleted issues.
   */
  private function cleanupOrphanedFieldData(): void {
    $database = \Drupal::database();

    // Get all existing AI issue node IDs.
    $existing_nids = $database->select('node_field_data', 'nfd')
      ->fields('nfd', ['nid'])
      ->condition('nfd.type', 'ai_issue')
      ->execute()
      ->fetchCol();

    if (empty($existing_nids)) {
      // If no AI issues exist, delete all assignee field data for ai_issue.
      $database->delete('node__field_issue_assignees')
        ->condition('bundle', 'ai_issue')
        ->execute();
    }
    else {
      // Delete assignee field data for non-existent nodes.
      $database->delete('node__field_issue_assignees')
        ->condition('bundle', 'ai_issue')
        ->condition('entity_id', $existing_nids, 'NOT IN')
        ->execute();
    }
  }

  /**
   * Resolve taxonomy term ID to name via API.
   *
   * @param string $term_id
   *   The taxonomy term ID.
   *
   * @return string|null
   *   The term name or null if not found.
   */
  protected function resolveTagName(string $term_id): ?string {
    static $tag_cache = [];

    // Use static cache to avoid repeated API calls.
    if (isset($tag_cache[$term_id])) {
      return $tag_cache[$term_id];
    }

    try {
      $response = $this->httpClient->request('GET', "https://www.drupal.org/api-d7/taxonomy_term/{$term_id}.json", [
        'timeout' => 10,
        'headers' => [
          'User-Agent' => self::USER_AGENT,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['name'])) {
        $tag_cache[$term_id] = $data['name'];
        return $data['name'];
      }
    }
    catch (\Exception $e) {
      // Log error but don't fail the import.
      $logger = $this->loggerFactory->get('ai_dashboard');
      $logger->warning('Failed to resolve tag ID @id: @message', [
        '@id' => $term_id,
        '@message' => $e->getMessage(),
      ]);
    }

    $tag_cache[$term_id] = NULL;
    return NULL;
  }

  /**
   * Resolve project ID from configuration.
   *
   * @param ModuleImport $config
   *   The import configuration.
   *
   * @return string
   *   The project ID.
   */
  protected function resolveProjectId(ModuleImport $config): string {
    // If project_id is set, use it (for backward compatibility).
    if ($config->getProjectId()) {
      return $config->getProjectId();
    }

    // Otherwise, resolve from machine name.
    $machine_name = $config->getProjectMachineName();
    if (empty($machine_name)) {
      throw new \InvalidArgumentException('Either project_id or project machine name must be provided');
    }

    return $this->resolveProjectIdFromMachineName($machine_name);
  }

  /**
   * Resolve project ID from machine name via drupal.org API.
   *
   * @param string $machine_name
   *   The project machine name.
   *
   * @return string
   *   The project ID.
   */
  protected function resolveProjectIdFromMachineName(string $machine_name): string {
    // Static cache to avoid repeated API calls.
    static $project_cache = [];
    
    if (isset($project_cache[$machine_name])) {
      return $project_cache[$machine_name];
    }

    try {
      // Query drupal.org API for project by machine name.
      $response = $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json', [
        'query' => [
          'type' => 'project_module',
          'field_project_machine_name' => $machine_name,
          'limit' => 1,
        ],
        'timeout' => 10,
        'headers' => [
          'User-Agent' => 'AI Dashboard Module/1.0',
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \Exception("API request failed with status: " . $response->getStatusCode());
      }

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (!isset($data['list']) || empty($data['list'])) {
        throw new \Exception("Project with machine name '{$machine_name}' not found on drupal.org");
      }

      $project = reset($data['list']);
      $project_id = $project['nid'];

      // Cache the result.
      $project_cache[$machine_name] = $project_id;

      return $project_id;
    }
    catch (\Exception $e) {
      throw new \Exception("Failed to resolve project ID for machine name '{$machine_name}': " . $e->getMessage());
    }
  }

  /**
   * Find existing module or create new one.
   *
   * @param string $machine_name
   *   The module machine name.
   *
   * @return int|null
   *   The module node ID or null if creation failed.
   */
  protected function findOrCreateModule(string $machine_name): ?int {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // First, try to find existing module by title.
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_module')
      ->condition('field_module_machine_name', $machine_name)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $result = $query->execute();

    if (!empty($result)) {
      return (int) reset($result);
    }

    // Module doesn't exist, create it.
    try {
      $module_node = Node::create([
        'type' => 'ai_module',
        'title' => $machine_name,
        'field_module_machine_name' => $machine_name,
        'status' => 1,
      ]);

      $module_node->save();
      return (int) $module_node->id();
    }
    catch (\Exception $e) {
      $logger = $this->loggerFactory->get('ai_dashboard');
      $module_name = '';
      $logger->error('Failed to create module @name: @message', [
        '@name' => $module_name,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Invalidate caches after import operations.
   */
  protected function invalidateImportCaches() {
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
   * Get tag IDs for drupal.org, given tag names.
   *
   * @param array $tag_names
   *   Tag names.
   * @return array
   *   Tag IDs matching d.o. vocabulary 9 (Issue tags).
   */
  protected function buildTagIds(array $tag_names): array {
    if (empty($tag_names)) {
      return [];
    }
    $terms = [];
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($termStorage->loadByProperties([
        'vid' => 'do_tags',
        'name' => $tag_names,
      ]) as $term) {
      $terms[$term->label()] = $term->get('field_external_id')->getString();
    }
    $tagsToQuery = [];
    foreach ($tag_names as $tag_name) {
      if (isset($terms[$tag_name])) {
        continue;
      }
      $tagsToQuery[] = $tag_name;
    }
    if (!empty($tagsToQuery)) {
      $result = $this->requestWithRetry('GET',
        'https://www.drupal.org/api-d7/taxonomy_term.json', [
            'vocabulary' => 9,
            'name' => implode(',', $tagsToQuery),
          ],
      );
      if (!$result['success']) {
        return [];
      }
      foreach ($result['data']['list'] as $doData) {
        $term = $termStorage->create([
          'vid' => 'do_tags',
          'name' => $doData['name'],
          'field_external_id' => $doData['tid'],
        ]);
        $termStorage->save($term);
        $terms[$doData['name']] = $doData['tid'];
      }
    }
    return $terms;
  }

  /**
   * @param \Drupal\ai_dashboard\Entity\ModuleImport $config
   * @param int $timestamp
   *
   * @return array
   *   Array if issue data chunks, up to 50 items in a chunk.
   */
  public function getModuleIssuesSince(ModuleImport $config, int $timestamp) : array {
    // Build the API URL for single status import.
    $url = 'https://www.drupal.org/api-d7/node.json';
    $params = [
      'type' => 'project_issue',
      'field_project' => $config->getProjectId(),
      'sort' => 'changed',
      'direction' => 'DESC',
      'limit' => self::BATCH_SIZE,
    ];
    $page = 0;
    $chunks = [];
    $lastPage = 0;
    do {
      $filterTags = $this->buildTagIds($config->getFilterTags());
      if (!empty($filterTags)) {
        foreach ($filterTags as $filterTag) {
          // For now, support only one tag.
          $params['taxonomy_vocabulary_9'] = $filterTag;
        }
      }
      else {
        unset($params['taxonomy_vocabulary_9']);
      }
      $response = $this->requestWithRetry('GET', $url, $params);
      if (!$response['success']) {
        // Multiple failures during fetch, exit.
        return $chunks;
      }
      $data = $response['data'];
      if (!$lastPage && preg_match('/&page=(\d+)/', $data['last'], $matches)) {
        $lastPage = $matches[1];
      }
      $params['page'] = ++$page;
      $timestampHit = FALSE;
      foreach ($data['list'] as $doData) {
        if ($doData['changed'] <= $timestamp) {
          $timestampHit = TRUE;
          break;
        }
      }
      if ($timestampHit) {
        $data['list'] = array_filter($data['list'],
          function ($item) use ($timestamp) {
            return $item['changed'] > $timestamp;
        });
      }
      if ($data['list']) {
        $chunks[] = $data['list'];
      }
      if ($page > $lastPage || $timestampHit) {
        return $chunks;
      }
    }
    while (TRUE);
    return $chunks;
  }

  /**
   * On initial import, we pull all issues to avoid 429 responses from API.
   *
   * From another side, there is no sense in creating issues that do not fit
   * the criteria set in module import configuration.
   *
   * @param $config
   * @param $issue_data
   *
   * @return bool
   */
  protected function shouldCreateIssue($config, array $issue_data) : bool {
    // Check status filter - use raw API status values
    if ($status_filter = $config->getStatusFilter()) {
      $issue_status = $issue_data['field_issue_status'] ?? '1';
      if (!in_array($issue_status, $status_filter)) {
        return FALSE;
      }
    }
    
    // Check tag filter if specified
    if ($tag_filter = $config->getFilterTags()) {
      return $this->issueMatchesTagFilter($issue_data, $tag_filter);
    }
    
    return TRUE;
  }

  /**
   * Simple wrapper around ClientInterface to overcome 429 too many requests.
   *
   * @param string $method
   *  HTTP method.
   * @param string $url
   *  URL to request from.
   *
   * @return array
   *   Processed response. Possible keys:
   *   - code. HTTP status code.
   *   - success. Boolean, TRUE in case of success.
   *   - data. Response data.
   */
  protected function requestWithRetry(string $method, string $url, array $query = []) : array {
    $result = [
      'success' => FALSE,
      'attempts' => 0,
    ];
    do {
      try {
        $response = $this->httpClient->request($method, $url, [
          'query' => $query,
          // Increased timeout for large imports.
          'timeout' => 60,
          'headers' => [
            'User-Agent' => self::USER_AGENT,
          ],
        ]);
        $result['code'] = $response->getStatusCode();
        if ($result['code'] === 200) {
          $result['data'] = json_decode($response->getBody()->getContents(),
            TRUE);
          $result['success'] = TRUE;
        }
      }
      catch (ClientException $e) {
        if ($e->getCode() === 429) {
          $result['code'] = 429;
          sleep(self::RETRY_AFTER);
          continue;
        }
      }
    }
    while (!$result['success'] && (++$result['attempts']) < self::MAX_TRIES);
    return $result;
  }

}
