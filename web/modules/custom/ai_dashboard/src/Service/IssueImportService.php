<?php

namespace Drupal\ai_dashboard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai_dashboard\Service\TagMappingService;
use Drupal\node\Entity\Node;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for importing issues from external APIs.
 */
class IssueImportService {

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
   * Import issues from a configuration.
   *
   * @param \Drupal\node\Entity\Node $config
   *   The import configuration node.
   * @param bool $use_batch
   *   Whether to use batch processing for large imports.
   *
   * @return array
   *   Import results with counts and messages.
   */
  public function importFromConfig(Node $config, bool $use_batch = true): array {
    $logger = $this->loggerFactory->get('ai_dashboard');
    
    try {
      $source_type = $config->get('field_import_source_type')->value;
      $project_id = $config->get('field_import_project_id')->value;
      $filter_tags = $this->getFilterTags($config);
      $status_filter = $this->getStatusFilter($config);
      $max_issues = $config->get('field_import_max_issues')->value;
      $max_issues = $max_issues ? (int) $max_issues : 1000;
      $date_filter = $config->get('field_import_date_filter')->value;

      $logger->info('Starting import from @source for project @project', [
        '@source' => $source_type,
        '@project' => $project_id,
      ]);

      // Use batch processing for large imports (over 100 issues) and web requests
      // Force batch processing for multi-status imports regardless of conditions
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
        'success' => false,
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
  protected function startBatchImport(Node $config, string $source_type, string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter): array {
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
          $i * $batch_size, // offset
          min($batch_size, $max_issues - ($i * $batch_size)), // limit
          $date_filter,
        ],
      ];
    }

    batch_set($batch);

    // For web requests, redirect to batch processing page
    if (PHP_SAPI !== 'cli') {
      // Store a flag so we know batch was started
      \Drupal::state()->set('ai_dashboard.batch_started', time());
      
      // Use batch_process() to redirect to the batch page
      $response = batch_process('/ai-dashboard/admin');
      
      return [
        'success' => true,
        'message' => 'Batch import started. Processing...',
        'redirect' => true,
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
      ];
    }

    // For CLI/drush execution, process immediately
    $batch =& batch_get();
    $batch['progressive'] = FALSE;
    batch_process();
    
    return [
      'success' => true,
      'message' => 'Batch import completed via CLI.',
      'imported' => 0, // Will be updated by batch
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

      // Update context with results
      if (!isset($context['results']['imported'])) {
        $context['results']['imported'] = 0;
        $context['results']['skipped'] = 0;
        $context['results']['errors'] = 0;
      }

      $context['results']['imported'] += $results['imported'];
      $context['results']['skipped'] += $results['skipped'];
      $context['results']['errors'] += $results['errors'];
      
      $context['message'] = t('Processed @imported issues (offset @offset)', [
        '@imported' => $results['imported'],
        '@offset' => $offset,
      ]);

    } catch (\Exception $e) {
      $context['results']['errors'][] = $e->getMessage();
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();
    
    // Clear the batch started flag
    \Drupal::state()->delete('ai_dashboard.batch_started');
    
    if ($success) {
      $imported = $results['imported'] ?? 0;
      $skipped = $results['skipped'] ?? 0;
      $errors = is_array($results['errors'] ?? []) ? count($results['errors']) : ($results['errors'] ?? 0);
      
      $messenger->addMessage(t('✅ Import completed successfully! @imported issues imported, @skipped skipped, @errors errors', [
        '@imported' => $imported,
        '@skipped' => $skipped,
        '@errors' => $errors,
      ]));
      
      if ($imported > 0) {
        $messenger->addMessage(t('You can view the imported issues at <a href="@url">Admin Tools → Issues</a>', [
          '@url' => '/ai-dashboard/admin/issues',
        ]));
      }
    } else {
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
   * @param \Drupal\node\Entity\Node $config
   *   The import configuration node.
   *
   * @return array
   *   Import results.
   */
  public function importFromDrupalOrg(string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter, Node $config): array {
    $logger = $this->loggerFactory->get('ai_dashboard');
    
    // If we have multiple statuses, import each one separately to avoid API limitations
    if (count($status_filter) > 1) {
      return $this->importMultipleStatusesSeparately($project_id, $filter_tags, $status_filter, $max_issues, $date_filter, $config);
    }
    
    // Build the API URL for single status import
    $url = 'https://www.drupal.org/api-d7/node.json';
    $params = [
      'type' => 'project_issue',
      'field_project' => $project_id,
      'limit' => $max_issues,
      'sort' => 'created',
      'direction' => 'DESC',
    ];

    // Add status filter if specified (single status only)
    if (!empty($status_filter)) {
      $params['field_issue_status'] = $status_filter[0];
    }

    // Add date filter if specified
    if ($date_filter) {
      $timestamp = strtotime($date_filter);
      if ($timestamp) {
        $params['created'] = '>=' . $timestamp;
      }
    }

    try {
      $results = [
        'success' => true,
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
        'message' => '',
      ];

      $page = 0;
      $per_page = 50; // drupal.org API limit
      $total_processed = 0;

      do {
        // Set pagination parameters
        $current_params = $params;
        $current_params['limit'] = min($per_page, $max_issues - $total_processed);
        $current_params['page'] = $page;

        $response = $this->httpClient->request('GET', $url, [
          'query' => $current_params,
          'timeout' => 60, // Increased timeout for large imports
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($data['list']) || !is_array($data['list'])) {
          throw new \Exception('Invalid response format from drupal.org API');
        }

        $page_issues = count($data['list']);
        if ($page_issues === 0) {
          break; // No more issues
        }

        foreach ($data['list'] as $issue_data) {
          if ($total_processed >= $max_issues) {
            break 2; // Break out of both loops
          }

          try {
            // Filter by tags if specified
            if (!empty($filter_tags) && !$this->issueMatchesTagFilter($issue_data, $filter_tags)) {
              $results['skipped']++;
              $total_processed++;
              continue;
            }

            $this->processIssue($issue_data, 'drupal_org', $config);
            $results['imported']++;
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
        
        // Continue if we got a full page and haven't reached the limit
      } while ($page_issues === $per_page && $total_processed < $max_issues);

      $results['message'] = sprintf(
        'Import completed: %d imported, %d skipped, %d errors',
        $results['imported'],
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
   * Import multiple statuses separately to avoid API limitations.
   */
  public function importMultipleStatusesSeparately(string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter, Node $config): array {
    $logger = $this->loggerFactory->get('ai_dashboard');
    
    $combined_results = [
      'success' => true,
      'imported' => 0,
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
    
    // Import each status separately
    foreach ($status_filter as $single_status) {
      $status_name = $status_names[$single_status] ?? "Status $single_status";
      $logger->info('Importing @status issues', ['@status' => $status_name]);
      
      try {
        // Import this single status
        $single_results = $this->importFromDrupalOrg($project_id, $filter_tags, [$single_status], $max_issues, $date_filter, $config);
        
        // Combine results
        $combined_results['imported'] += $single_results['imported'];
        $combined_results['skipped'] += $single_results['skipped'];
        $combined_results['errors'] += $single_results['errors'];
        
        $status_results[] = "$status_name: {$single_results['imported']} imported";
        
        if (!$single_results['success']) {
          $combined_results['success'] = false;
        }
        
      } catch (\Exception $e) {
        $logger->error('Failed to import @status: @message', [
          '@status' => $status_name,
          '@message' => $e->getMessage(),
        ]);
        $combined_results['errors']++;
        $combined_results['success'] = false;
        $status_results[] = "$status_name: ERROR";
      }
    }
    
    $combined_results['message'] = sprintf(
      'Multi-status import completed: %d total imported (%s)',
      $combined_results['imported'],
      implode(', ', $status_results)
    );
    
    return $combined_results;
  }

  /**
   * Import issues from drupal.org API for batch processing.
   */
  public function importFromDrupalOrgBatch(string $project_id, array $filter_tags, array $status_filter, int $offset, int $limit, ?string $date_filter, Node $config): array {
    $logger = $this->loggerFactory->get('ai_dashboard');
    
    // For batch processing, each operation handles exactly one API page
    // The offset and limit should align with API page boundaries (50 items per page)
    $api_page_size = 50;
    $page_number = floor($offset / $api_page_size);
    
    $results = [
      'success' => true,
      'imported' => 0,
      'skipped' => 0,
      'errors' => 0,
      'message' => '',
    ];
    
    // Build the API URL for this specific page
    $url = 'https://www.drupal.org/api-d7/node.json';
    $params = [
      'type' => 'project_issue',
      'field_project' => $project_id,
      'limit' => min($api_page_size, $limit),
      'page' => $page_number,
        'sort' => 'created',
        'direction' => 'DESC',
      ];

    // Add status filter if specified
    if (!empty($status_filter)) {
      $params['field_issue_status'] = implode(',', $status_filter);
    }

    // Add date filter if specified
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
      ]);

      $data = json_decode($response->getBody()->getContents(), true);
      
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
          break; // Don't exceed the requested limit for this batch
        }

        try {
          // Filter by tags if specified
          if (!empty($filter_tags) && !$this->issueMatchesTagFilter($issue_data, $filter_tags)) {
            $results['skipped']++;
            $total_processed++;
            continue;
          }

          $this->processIssue($issue_data, 'drupal_org', $config);
          $results['imported']++;
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
        'Page %d: %d imported, %d skipped, %d errors',
        $page_number,
        $results['imported'],
        $results['skipped'],
        $results['errors']
      );

    } catch (\Exception $e) {
      // Check if this is a 404 error (no more pages available)
      if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'Page doesn') !== false) {
        $results['message'] = sprintf('Page %d: No more data available (reached end of results)', $page_number);
        $logger->info('Reached end of available data at page @page', ['@page' => $page_number]);
      } else {
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
  protected function importFromGitLab(string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter, Node $config): array {
    throw new \Exception('GitLab import not yet implemented');
  }

  /**
   * Import from GitHub (placeholder for future implementation).
   */
  protected function importFromGitHub(string $project_id, array $filter_tags, array $status_filter, int $max_issues, ?string $date_filter, Node $config): array {
    throw new \Exception('GitHub import not yet implemented');
  }

  /**
   * Process a single issue from API data.
   *
   * @param array $issue_data
   *   The issue data from API.
   * @param string $source_type
   *   The source type.
   * @param \Drupal\node\Entity\Node $config
   *   The import configuration node.
   */
  protected function processIssue(array $issue_data, string $source_type, Node $config): void {
    $node_storage = $this->entityTypeManager->getStorage('node');
    
    // Map API data to Drupal fields based on source
    $mapped_data = $this->mapIssueData($issue_data, $source_type, $config);
    
    // Check for existing issue by external ID
    $existing = $this->findExistingIssue($mapped_data['external_id'], $source_type);
    
    if ($existing) {
      // Update existing issue
      $this->updateIssue($existing, $mapped_data);
    } else {
      // Create new issue
      $this->createIssue($mapped_data);
    }
  }

  /**
   * Map API issue data to Drupal field structure.
   *
   * @param array $issue_data
   *   Raw API data.
   * @param string $source_type
   *   Source type.
   * @param \Drupal\node\Entity\Node $config
   *   The import configuration node.
   *
   * @return array
   *   Mapped data.
   */
  protected function mapIssueData(array $issue_data, string $source_type, Node $config): array {
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
   * @param \Drupal\node\Entity\Node $config
   *   The import configuration node.
   *
   * @return array
   *   Mapped data.
   */
  protected function mapDrupalOrgIssue(array $issue_data, Node $config): array {
    // Extract tags from the issue
    $tags = [];
    if (isset($issue_data['taxonomy_vocabulary_9']) && is_array($issue_data['taxonomy_vocabulary_9'])) {
      foreach ($issue_data['taxonomy_vocabulary_9'] as $tag) {
        if (isset($tag['name'])) {
          // Tag has name (full API response)
          $tags[] = $tag['name'];
        } elseif (isset($tag['id'])) {
          // Tag has only ID, resolve it via API
          $tag_name = $this->resolveTagName($tag['id']);
          if ($tag_name) {
            $tags[] = $tag_name;
          }
        }
      }
    }

    // Process tags through mapping service
    $processed_tags = $this->tagMappingService->processTags($tags);

    // Extract drupal.org assignee information
    $do_assignee = '';
    if (isset($issue_data['field_issue_assigned']) && is_array($issue_data['field_issue_assigned'])) {
      $assignee_info = reset($issue_data['field_issue_assigned']);
      if (isset($assignee_info['user']) && isset($assignee_info['user']['name'])) {
        $do_assignee = $assignee_info['user']['name'];
      }
    }

    // Get module name based on project information
    $module_name = 'Unknown Module';
    
    // Get project ID from import configuration
    $project_id = $config->get('field_import_project_id')->value;
    
    // Map known project IDs to module names
    $project_mapping = [
      '3346420' => 'AI',
      // Add more mappings as needed in the future
    ];
    
    if (isset($project_mapping[$project_id])) {
      $module_name = $project_mapping[$project_id];
    } else {
      // Fallback to configuration title
      $module_name = $config->getTitle();
      if (strpos($module_name, ' Import Configuration') !== false) {
        $module_name = str_replace(' Import Configuration', '', $module_name);
      }
    }

    // Find or create the module node
    $module_node_id = $this->findOrCreateModule($module_name);

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
      return true;
    }

    $issue_status = $issue_data['field_issue_status'] ?? '';
    return in_array($issue_status, $status_filter);
  }

  /**
   * Check if issue matches tag filter.
   */
  protected function issueMatchesTagFilter(array $issue_data, array $filter_tags): bool {
    if (empty($filter_tags)) {
      return true;
    }

    $issue_tags = [];
    if (isset($issue_data['taxonomy_vocabulary_9']) && is_array($issue_data['taxonomy_vocabulary_9'])) {
      foreach ($issue_data['taxonomy_vocabulary_9'] as $tag) {
        if (isset($tag['name'])) {
          $issue_tags[] = strtolower($tag['name']);
        }
      }
    }

    foreach ($filter_tags as $filter_tag) {
      if (in_array(strtolower($filter_tag), $issue_tags)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Find existing issue by external ID.
   */
  protected function findExistingIssue(string $external_id, string $source_type): ?Node {
    $node_storage = $this->entityTypeManager->getStorage('node');
    
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_number', $external_id)
      ->accessCheck(FALSE)
      ->range(0, 1);
    
    $result = $query->execute();
    
    if (!empty($result)) {
      return $node_storage->load(reset($result));
    }
    
    return null;
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
    
    $issue->save();
    return $issue;
  }

  /**
   * Update existing issue.
   */
  protected function updateIssue(Node $issue, array $mapped_data): Node {
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
    $issue->setChangedTime($mapped_data['changed']);
    
    $issue->save();
    return $issue;
  }

  /**
   * Get filter tags from configuration.
   */
  protected function getFilterTags(Node $config): array {
    $tags = [];
    if ($config->hasField('field_import_filter_tags') && !$config->get('field_import_filter_tags')->isEmpty()) {
      $tags_string = $config->get('field_import_filter_tags')->value;
      if (!empty($tags_string)) {
        // Split by comma and clean up
        $tags = array_map('trim', explode(',', $tags_string));
        // Remove empty values
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
      
      // Clean up any orphaned field data
      $this->cleanupOrphanedFieldData();
      
      return count($issues);
    }
    
    return 0;
  }
  
  /**
   * Clean up orphaned field data for deleted issues.
   */
  private function cleanupOrphanedFieldData(): void {
    $database = \Drupal::database();
    
    // Get all existing AI issue node IDs
    $existing_nids = $database->select('node_field_data', 'nfd')
      ->fields('nfd', ['nid'])
      ->condition('nfd.type', 'ai_issue')
      ->execute()
      ->fetchCol();
    
    if (empty($existing_nids)) {
      // If no AI issues exist, delete all assignee field data for ai_issue
      $database->delete('node__field_issue_assignees')
        ->condition('bundle', 'ai_issue')
        ->execute();
    } else {
      // Delete assignee field data for non-existent nodes
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
    
    // Use static cache to avoid repeated API calls
    if (isset($tag_cache[$term_id])) {
      return $tag_cache[$term_id];
    }
    
    try {
      $response = $this->httpClient->request('GET', "https://www.drupal.org/api-d7/taxonomy_term/{$term_id}.json", [
        'timeout' => 10,
      ]);
      
      $data = json_decode($response->getBody()->getContents(), true);
      
      if (isset($data['name'])) {
        $tag_cache[$term_id] = $data['name'];
        return $data['name'];
      }
    } catch (\Exception $e) {
      // Log error but don't fail the import
      $logger = $this->loggerFactory->get('ai_dashboard');
      $logger->warning('Failed to resolve tag ID @id: @message', [
        '@id' => $term_id,
        '@message' => $e->getMessage(),
      ]);
    }
    
    $tag_cache[$term_id] = null;
    return null;
  }

  /**
   * Find existing module or create new one.
   *
   * @param string $module_name
   *   The module name.
   *
   * @return int|null
   *   The module node ID or null if creation failed.
   */
  protected function findOrCreateModule(string $module_name): ?int {
    $node_storage = $this->entityTypeManager->getStorage('node');
    
    // First, try to find existing module by title
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_module')
      ->condition('title', $module_name)
      ->accessCheck(FALSE)
      ->range(0, 1);
    
    $result = $query->execute();
    
    if (!empty($result)) {
      return (int) reset($result);
    }
    
    // Module doesn't exist, create it
    try {
      $module_node = Node::create([
        'type' => 'ai_module',
        'title' => $module_name,
        'status' => 1,
      ]);
      
      $module_node->save();
      return (int) $module_node->id();
    }
    catch (\Exception $e) {
      $logger = $this->loggerFactory->get('ai_dashboard');
      $logger->error('Failed to create module @name: @message', [
        '@name' => $module_name,
        '@message' => $e->getMessage(),
      ]);
      return null;
    }
  }

}