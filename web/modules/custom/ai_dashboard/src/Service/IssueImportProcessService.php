<?php

namespace Drupal\ai_dashboard\Service;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeStorageInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Drupal\Core\Site\Settings;

/**
 * Service for importing issues from external APIs.
 */
class IssueImportProcessService {

  const USER_AGENT = 'AI Dashboard Module/1.0';

  /**
   * Maximum number of tries before giving up.
   */
  const MAX_TRIES = 3;

  /**
   * Number of seconds to wait before retrying the request.
   */
  const RETRY_AFTER = 30;

  const STATUS_MAP = [

    '1' => 'active',
    '8' => 'needs_review',
    '13' => 'needs_work',
    '14' => 'rtbc',
    '2' => 'fixed',
    '3' => 'closed_duplicate',
    '17' => 'closed_outdated',
    '5' => 'closed_wontfix',
    // Duplicating some status names because these is a mismatch between config status options and issue status options
    '15' => 'fixed', // 'patch'
    '4' => 'closed_wontfix', // 'postponed'
    '16' => 'closed_wontfix', // 'postponed(needs more info)'
  ];

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
   * The metadata parser service.
   *
   * @var \Drupal\ai_dashboard\Service\MetadataParserService
   */
  protected $metadataParserService;

  protected $settings;

  /**
   * Constructs a new IssueImportProcessService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\ai_dashboard\Service\TagMappingService $tag_mapping_service
   *   The tag mapping service.
   * @param \Drupal\ai_dashboard\Service\MetadataParserService $metadata_parser_service
   *   The metadata parser service.
   * @param Drupal\Core\Site\Settings
   *   The Drupal settings object.
   * 
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory, TagMappingService $tag_mapping_service, MetadataParserService $metadata_parser_service, Settings $settings) {
    $this->entityTypeManager = $entity_type_manager;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->tagMappingService = $tag_mapping_service;
    $this->metadataParserService = $metadata_parser_service;
    $this->settings = $settings;
  }

  public function loadPageOfIssues(ModuleImport $config, int $per_page, int $page, $extra_options = NULL){

    $api_details = $this->getSourceApiDetails($config);
    $params = $this->getSourceSpecificFilters($config, $api_details['base_params'], $extra_options);
    $source_type = $config->getSourceType();
    $url = $api_details['url'];

    $current_params = $this->getPaginationParams($source_type, $params, $per_page, $page);

    $headers = ['User-Agent' => self::USER_AGENT];
    if (isset($api_details['auth'])) {
      $headers[$api_details['auth']['type']] = $api_details['auth']['value'];
    }

    $response = $this->httpClient->request('GET', $url, [
      'query' => $current_params,
      // Increased timeout for large imports.
      'timeout' => 60,
      'headers' => $headers,
    ]);

    $response_body = json_decode($response->getBody()->getContents(), TRUE);
    $issues_data = $this->deriveIssuesData($source_type, $response_body);

    return $issues_data;

  }

  /**
   * Import issues from API for batch processing.
   */
  public function importFromApiBatch(ModuleImport $config, int $offset, int $limit, $single_status = NULL): array {
    // Clear import session cache if this is the first batch (offset 0).
    if ($offset === 0) {
      $this->clearImportSessionCache();
    }

    $logger = $this->loggerFactory->get('ai_dashboard');


    $per_page_max = $this->getBatchSize($config);
    $page_number = floor($offset / $per_page_max);

    $results = [
      'success' => TRUE,
      'imported' => 0,
      'updated' => 0,
      'skipped' => 0,
      'errors' => 0,
      'message' => '',
    ];

    try {

      $per_page = min($per_page_max, $limit);

      $issues_data = $this->loadPageOfIssues($config, $per_page, $page_number, ['single_status' => $single_status]);

      if (empty($issues_data)) {
        $results['message'] = sprintf('Page %d: No more issues available', $page_number);
        return $results;
      }

      $total_processed = 0;
      foreach ($issues_data as $issue_data) {
        if ($total_processed >= $limit) {
          break;
        }

        try {

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
            '@id' => $issue_data['nid'] ?? $issue_data['iid'] ?? 'unknown',
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
   * Import from API.
   *
   * @param ModuleImport $config
   *   The import configuration node.
   *
   * @param $single_status
   *  To be set when we want to only import issues with given status. This is used for DO imports where we cannot reliably import several statuses at a time.
   *
   * @return array
   *   Import results.
   *
   */
  public function importFromApi(ModuleImport $config, int $max_issues, $single_status = NULL): array {
    $this->clearImportSessionCache();
    $logger = $this->loggerFactory->get('ai_dashboard');
    $source_type = $config->getSourceType();

    $do_status = $single_status;

    // No need to handle multi-status DO imports - this case is handled by IssueImportOrchestrationService
    if ($source_type === "drupal_org" && !$do_status) {
        $status_filter = $config->getStatusFilter();
        if($status_filter){
          if (!is_array($status_filter)) {
            $status_filter = explode(',', $status_filter);
          }
            $do_status = reset($status_filter);
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
      $total_processed = 0;
      $per_page_max = $this->getBatchSize($config);

      do {


        $per_page = min($per_page_max, $max_issues - $total_processed);

        $issues_data = $this->loadPageOfIssues($config, $per_page, $page, ["single_status" => $single_status]);

        if (empty($issues_data)) {
          $results['message'] = sprintf('Page %d: No more issues available', $page);
          break;
        }

        foreach ($issues_data as $issue_data) {
          if ($total_processed >= $max_issues) {
            break 2;
          }

          try {
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
              '@id' => $issue_data['nid'] ?? $issue_data['iid'] ?? 'unknown',
              '@message' => $e->getMessage(),
            ]);
            $results['errors']++;
            $total_processed++;
          }
        }

        $results['message'] = sprintf(
          'Page %d: %d imported, %d updated, %d skipped, %d errors',
          $page,
          $results['imported'],
          $results['updated'],
          $results['skipped'],
          $results['errors']
        );
        $page++;
      } while ($total_processed < $max_issues);

      return $results;
    }
    catch (\Exception $e) {
      $logger->error('API request failed: @message', ['@message' => $e->getMessage()]);
      $results['success'] = FALSE;
      $results['message'] = $e->getMessage();
      return $results;
    }
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
    $mapped_data = $this->mapIssueData($issue_data, $config);

    // Check for existing issue by external ID.
    $existing = $this->findExistingIssue($mapped_data);

    if ($existing) {
      // Update existing issue.
      $this->updateIssue($existing, $mapped_data);
      return 'updated';
    }
    elseif ($this->shouldCreateIssue($config, $mapped_data)) {
      $this->createIssue($mapped_data);
      return 'created';
    }

    return 'skipped';
  }

  public function getBatchSize(ModuleImport $config){
    $api_details = $this->getSourceApiDetails($config);
    return $api_details['per_page_max'];
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
  protected function mapIssueData(array $issue_data, ModuleImport $config): array {
    $source_type = $config->getSourceType();
    switch ($source_type) {
      case 'drupal_org':
        return $this->mapDrupalOrgIssue($issue_data, $config);
      case 'gitlab':
        return $this->mapGitLabIssue($issue_data, $config);
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
          $tag_name = $this->resolveDrupalOrgTagName($tag['id']);
          if ($tag_name) {
            $tags[] = $tag_name;
          }
        }
      }
    }

    // Process tags through mapping service.
    $processed_tags = $this->tagMappingService->processTags($tags);

    // Extract and parse AI Tracker metadata from issue summary
    $issue_summary = $this->extractIssueSummary($issue_data);
    $parsed_metadata = $this->metadataParserService->parseMetadata($issue_summary);

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
            $userData = $this->getDrupalOrgUserData($user_id);
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
              else {
                // Contributor not found, but we have the username from API
                // Store it so the untracked users report can find it
                $do_assignee = $userData['name'];
                // Mark as not found in our system
                $contributors[$user_id] = FALSE;
              }
            }
          }
        }
        if (!empty($contributors[$user_id]) && $contributors[$user_id] !== FALSE) {
          $do_assignee = $contributors[$user_id]->get('field_drupal_username')
            ->getString();
          $assignee_id = $contributors[$user_id]->id();
        }
      }
    }

    // Log assignee resolution issues.
    if (isset($issue_data['nid']) && isset($issue_data['field_issue_assigned']) && empty($do_assignee)) {
      \Drupal::logger('ai_dashboard')->warning('Failed to resolve assignee for issue @nid, assigned field: @assigned', [
        '@nid' => $issue_data['nid'],
        '@assigned' => json_encode($issue_data['field_issue_assigned']),
      ]);
    }

    // Find or create the module node.
    $module_node_id = $this->findOrCreateModule($config->getProjectMachineName());

    // Determine non-developer flag: presence of a non-developer tag.
    $non_dev_flag = FALSE;
    foreach ($tags as $t) {
      if (strcasecmp(trim($t), 'non-developer') === 0 || strcasecmp(trim($t), 'non_developer') === 0) {
        $non_dev_flag = TRUE;
        break;
      }
    }

    return [
      'external_id' => $issue_data['nid'],
      'source_type' => 'drupal_org',
      'title' => $issue_data['title'] ?? 'Untitled Issue',
      'issue_number' => $issue_data['nid'],
      'issue_url' => $issue_data['url'] ?? '',
      'status' => $this->mapDrupalOrgStatus($issue_data['field_issue_status'] ?? '1'),
      'priority' => $this->mapDrupalOrgPriority($issue_data['field_issue_priority'] ?? '300'),
      'category' => $processed_tags['category'] ?? 'general',
      'track' => $processed_tags['track'] ?? '',
      'workstream' => $processed_tags['workstream'] ?? '',
      'issue_summary' => $issue_summary,
      'tags' => $tags,
      'module' => $module_node_id,
      'do_assignee' => [$do_assignee],
      'assignee_id' => [$assignee_id],
      'created' => $issue_data['created'] ?? time(),
      'changed' => $issue_data['changed'] ?? time(),
      'non_developer' => $non_dev_flag,
      'config' => $config,
      // AI Tracker metadata fields.
      'blocked_by' => $parsed_metadata['blocked_by'] ?? '',
      'update_summary' => $parsed_metadata['update_summary'] ?? '',
      'short_title' => $parsed_metadata['short_title'] ?? '',
      'short_description' => $parsed_metadata['short_description'] ?? '',
      'checkin_date' => $parsed_metadata['checkin_date'] ?? '',
      'due_date' => $parsed_metadata['due_date'] ?? '',
      'additional_collaborators' => $parsed_metadata['additional_collaborators'] ?? '',
    ];
  }

  protected function mapGitLabIssue(array $issue_data, ModuleImport $config): array {
    /** @var NodeStorageInterface $nodeStorage */
    static $nodeStorage;
    // Array of contributor nodes, keyed by d.o. username.
    static $contributors;


    // Extract tags and key-value labels from GitLab issue labels.
    $tags = [];
    $properties = [];

    $issueLabels = $issue_data['labels'] ?? [];
    foreach($issueLabels as $label){
        if (str_contains($label, "::")) {
          [$key, $value] = explode("::", $label);
          $properties[$key] = $value;
        } else {
          $tags[] = $label;
        }
    }

    // Extract and parse AI Tracker metadata from issue description.
    $issue_description = $issue_data['description'] ?? '';
    $parsed_metadata = $this->metadataParserService->parseMetadata($issue_description);

    // Extract GitLab assignee info and find the corresponding Drupal user.
    $do_assignees = [];

    if (!empty($issue_data['assignees'])) {
      if (!$nodeStorage) {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
      }
      foreach ($issue_data['assignees'] as $assignee) {

        $do_username = $assignee['username'];
        $contributor = $contributors[$do_username];

        if (!$contributor) {
          // Try to find d.o. user by their username
          $candidates = $nodeStorage->loadByProperties([
            'type' => 'ai_contributor',
            'field_drupal_username' => $do_username,
          ]);
          if (!empty($candidates)){
            $contributor = reset($candidates);
            $contributors[$do_username] = $contributor;
          }
        }

        if ($contributor) {
          $do_assignees[] = $ontributor;
        } else {
          \Drupal::logger('ai_dashboard')->warning('Failed to find user @username among Drupal users', [
            '@username' => $do_username,
          ]);
        }

      }
    }

    // Find or create the module node.
    $module_node_id = $this->findOrCreateModule($config->getProjectMachineName());

    // Determine non-developer flag: presence of a non-developer tag.
    $non_dev_flag = FALSE;
    foreach ($tags as $t) {
      if (strcasecmp(trim($t), 'non-developer') === 0 || strcasecmp(trim($t), 'non_developer') === 0) {
        $non_dev_flag = TRUE;
        break;
      }
    }

    $assignee_usernames = [];
    $assignee_ids = [];

    foreach($do_assignees as $do_assignee){
        $assignee_usernames[] = $do_assignee->get->get('field_drupal_username')->getString();
        $assignee_ids[] = $do_assignee->id();
    }

    return [
      'external_id' => (string) $issue_data['iid'],
      'source_type' => 'gitlab',
      'title' => $issue_data['title'] ?? 'Untitled Issue',
      'issue_number' => (string) $issue_data['iid'],
      'issue_url' => $issue_data['web_url'] ?? '',
      'status' => $this->mapGitLabStatus($properties['state']?? 'active'),
      'priority' => $properties['priority'] ?? 'normal',
      'category' => $properties['category'] ?? 'general',
      'track' => $properties['track'] ?? '',
      'workstream' => $properties['workstream'] ?? '',
      'issue_summary' => $issue_description,
      'tags' => $tags,
      'module' => $module_node_id,
      'do_assignee' => $assignee_usernames,
      'assignee_id' => $assignee_ids,
      'created' => isset($issue_data['created_at']) ? strtotime($issue_data['created_at']) : time(),
      'changed' => isset($issue_data['updated_at']) ? strtotime($issue_data['updated_at']) : time(),
      'non_developer' => $non_dev_flag,
      'config' => $config,
      // AI Tracker metadata fields.
      'blocked_by' => $parsed_metadata['blocked_by'] ?? '',
      'update_summary' => $parsed_metadata['update_summary'] ?? '',
      'short_title' => $parsed_metadata['short_title'] ?? '',
      'short_description' => $parsed_metadata['short_description'] ?? '',
      'checkin_date' => $parsed_metadata['checkin_date'] ?? '',
      'due_date' => $parsed_metadata['due_date'] ?? '',
      'additional_collaborators' => $parsed_metadata['additional_collaborators'] ?? '',
    ];
  }


  /**
   * Extract issue summary/body from drupal.org API data.
   *
   * @param array $issue_data
   *   The issue data from drupal.org API.
   *
   * @return string
   *   The issue summary/body text.
   */
  protected function extractIssueSummary(array $issue_data): string {
    // Try different possible field names for the issue body in drupal.org API
    $possible_fields = [
      'body',
      'field_issue_body',
      'description',
      'field_body',
      'field_description',
    ];

    foreach ($possible_fields as $field_name) {
      if (isset($issue_data[$field_name])) {
        $field_data = $issue_data[$field_name];

        // Handle different formats the field data might be in
        if (is_string($field_data)) {
          // Log successful field extraction for debugging
          $this->loggerFactory->get('ai_dashboard')->info('Found issue summary in field @field for issue @nid', [
            '@field' => $field_name,
            '@nid' => $issue_data['nid'] ?? 'unknown',
          ]);
          return $field_data;
        }
        elseif (is_array($field_data)) {
          // Check for Drupal field format with value/summary
          if (isset($field_data['value'])) {
            // Log successful field extraction for debugging
            $this->loggerFactory->get('ai_dashboard')->info('Found issue summary in field @field.value for issue @nid', [
              '@field' => $field_name,
              '@nid' => $issue_data['nid'] ?? 'unknown',
            ]);
            return $field_data['value'];
          }
          // Check for array of field items
          elseif (isset($field_data[0])) {
            if (is_string($field_data[0])) {
              // Log successful field extraction for debugging
              $this->loggerFactory->get('ai_dashboard')->info('Found issue summary in field @field[0] for issue @nid', [
                '@field' => $field_name,
                '@nid' => $issue_data['nid'] ?? 'unknown',
              ]);
              return $field_data[0];
            }
            elseif (is_array($field_data[0]) && isset($field_data[0]['value'])) {
              // Log successful field extraction for debugging
              $this->loggerFactory->get('ai_dashboard')->info('Found issue summary in field @field[0].value for issue @nid', [
                '@field' => $field_name,
                '@nid' => $issue_data['nid'] ?? 'unknown',
              ]);
              return $field_data[0]['value'];
            }
          }
        }
      }
    }

    // Log when no body field is found to help with debugging
    $available_fields = array_keys($issue_data);
    $this->loggerFactory->get('ai_dashboard')->warning('No issue summary field found for issue @nid. Available fields: @fields', [
      '@nid' => $issue_data['nid'] ?? 'unknown',
      '@fields' => implode(', ', $available_fields),
    ]);

    // If no body field found, return empty string
    return '';
  }

  /**
   * Map drupal.org status to our values.
   */
  protected function mapDrupalOrgStatus(string $status_id): string {

    return self::STATUS_MAP[$status_id] ?? 'active';
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
   * Map GitLab issue status to our values.
   */
  protected function mapGitLabStatus(string $state): string {
     $status_map = [
      'active' => 'active',
      'needsReview' => 'needs_review',
      'needsWork' => 'needs_work',
      'rtbc' => 'rtbc',
      'fixed' => 'fixed',
      'closed' => 'closed',
      'accepted' => 'accepted',
    ];

    return $status_map[$state] ?? 'active';
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
  protected function getDrupalOrgUserData(string $user_id): array {
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
  protected function findExistingIssue(array $mapped_data): ?Node {

    $external_id = $mapped_data['external_id'];

    $cache_id = $this->createCacheId($mapped_data);

    // First check if we've already processed this issue in this import session.
    if (isset(static::$importSessionCache[$cache_id])) {
      $node_storage = $this->entityTypeManager->getStorage('node');
      return $node_storage->load(static::$importSessionCache[$cache_id]);
    }

    $node_storage = $this->entityTypeManager->getStorage('node');

    // Clear entity cache to ensure fresh query.
    $node_storage->resetCache();

    $query = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_module', $mapped_data['module'])
      ->condition('field_issue_number', $external_id)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $result = $query->execute();

    if (!empty($result)) {
      $node_id = reset($result);
      // Cache this for the current import session.
      static::$importSessionCache[$cache_id] = $node_id;
      return $node_storage->load($node_id);
    }

    return NULL;
  }

  /**
   * Detect if an issue should be marked as meta.
   *
   * @param string $title
   *   The issue title.
   * @param array $tags
   *   The issue tags.
   *
   * @return bool
   *   TRUE if the issue should be marked as meta.
   */
  protected function detectMetaIssue(string $title, array $tags = []): bool {
    // Check if title contains [META] or [Meta] or [meta] (case-insensitive)
    if (preg_match('/\[meta\]/i', $title)) {
      return TRUE;
    }

    // Check if tags contain 'meta' or 'Meta issue' (case-insensitive)
    foreach ($tags as $tag) {
      if (is_string($tag) && preg_match('/^meta(\s+issue)?$/i', $tag)) {
        return TRUE;
      }
    }

    return FALSE;
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
      'field_track' => $mapped_data['track'],
      'field_workstream' => $mapped_data['workstream'],
      'field_issue_summary' => $mapped_data['issue_summary'] ?? '',
      'field_issue_tags' => !empty($mapped_data['tags']) ? array_map(function($tag) { return ['value' => $tag]; }, $mapped_data['tags']) : [],
      'field_issue_module' => $mapped_data['module'] ?? '',
      'field_issue_do_assignee' => $mapped_data['do_assignee']? implode(',', $mapped_data['do_assignee']) : '',
      'created' => $mapped_data['created'],
      'changed' => $mapped_data['changed'],
      'status' => 1,
    ]);
    // Set non-developer flag if provided and field exists.
    if (isset($mapped_data['non_developer']) && $issue->hasField('field_issue_non_developer')) {
      $issue->set('field_issue_non_developer', $mapped_data['non_developer'] ? 1 : 0);
    }
    if (!empty($mapped_data['assignee_id'])) {
      $issue->set('field_issue_assignees', $mapped_data['assignee_id']);
    }

    // Detect and set meta issue flag
    if ($issue->hasField('field_is_meta_issue')) {
      $is_meta = $this->detectMetaIssue($mapped_data['title'], $mapped_data['tags'] ?? []);
      $issue->set('field_is_meta_issue', $is_meta ? 1 : 0);
    }

    // Set dashboard category from import config audiences or mapped flag.
    $audiences = [];
    if (!empty($mapped_data['config']) && method_exists($mapped_data['config'], 'getImportAudiences')) {
      $audiences = $mapped_data['config']->getImportAudiences();
    }
    if (empty($audiences)) {
      $audiences = !empty($mapped_data['non_developer']) ? ['non_dev'] : ['dev'];
    }
    if ($issue->hasField('field_issue_dashboard_category')) {
      $issue->set('field_issue_dashboard_category', array_map(function ($v) { return ['value' => $v]; }, $audiences));
    }

    // Set AI Tracker metadata fields if they exist and have values.
    if (!empty($mapped_data['blocked_by']) && $issue->hasField('field_issue_blocked_by')) {
      $issue->set('field_issue_blocked_by', $mapped_data['blocked_by']);
    }
    if (!empty($mapped_data['update_summary']) && $issue->hasField('field_update_summary')) {
      $issue->set('field_update_summary', $mapped_data['update_summary']);
    }
    if (!empty($mapped_data['short_title']) && $issue->hasField('field_short_title')) {
      $issue->set('field_short_title', $mapped_data['short_title']);
    }
    if (!empty($mapped_data['short_description']) && $issue->hasField('field_short_description')) {
      // Truncate to 255 characters to fit field max length.
      $issue->set('field_short_description', mb_substr($mapped_data['short_description'], 0, 255));
    }
    if (!empty($mapped_data['checkin_date']) && $issue->hasField('field_checkin_date')) {
      // Convert date format if needed (MM/DD/YYYY to Y-m-d).
      $checkin_date = $this->convertDateFormat($mapped_data['checkin_date']);
      if ($checkin_date) {
        $issue->set('field_checkin_date', $checkin_date);
      }
    }
    if (!empty($mapped_data['due_date']) && $issue->hasField('field_due_date')) {
      // Convert date format if needed (MM/DD/YYYY to Y-m-d).
      $due_date = $this->convertDateFormat($mapped_data['due_date']);
      if ($due_date) {
        $issue->set('field_due_date', $due_date);
      }
    }
    if (!empty($mapped_data['additional_collaborators']) && $issue->hasField('field_additional_collaborators')) {
      // Additional collaborators would need to be resolved to user entities
      // For now, store as a text field if the field type supports it
      $issue->set('field_additional_collaborators', $mapped_data['additional_collaborators']);
    }

    $issue->save();

    // Create AssignmentRecords for current week if assignees exist.
    if (!empty($mapped_data['assignee_id'])) {
      $this->createAssignmentRecords($issue, $mapped_data);
    }

    // Cache this newly created issue to prevent duplicates in
    // the same import session.
    static::$importSessionCache[$this->createCacheId($mapped_data)] = $issue->id();

    $this->invalidateImportCaches();
    return $issue;
  }

  /**
   * Update existing issue.
   */
  protected function updateIssue(Node $issue, array $mapped_data): Node {
    static $nodeStorage;
    static $contributors = [];
    // Log the update.
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
    $issue->set('field_track', $mapped_data['track']);
    $issue->set('field_workstream', $mapped_data['workstream']);
    $issue->set('field_issue_summary', $mapped_data['issue_summary'] ?? '');
    $issue->set('field_issue_tags', !empty($mapped_data['tags']) ? array_map(function($tag) { return ['value' => $tag]; }, $mapped_data['tags']) : []);
    $issue->set('field_issue_module', $mapped_data['module'] ?? '');
    $issue->set('field_issue_do_assignee', $mapped_data['do_assignee']? implode(',', $mapped_data['do_assignee']) : '');
    // Update non-developer flag if provided.
    if (isset($mapped_data['non_developer']) && $issue->hasField('field_issue_non_developer')) {
      $issue->set('field_issue_non_developer', $mapped_data['non_developer'] ? 1 : 0);
    }
    if (!empty($mapped_data['assignee_id'])) {
      $issue->set('field_issue_assignees', $mapped_data['assignee_id']);
    }

    // Detect and update meta issue flag
    if ($issue->hasField('field_is_meta_issue')) {
      $is_meta = $this->detectMetaIssue($mapped_data['title'], $mapped_data['tags'] ?? []);
      $issue->set('field_is_meta_issue', $is_meta ? 1 : 0);
    }

    // Update dashboard category as well.
    $audiences = [];
    if (!empty($mapped_data['config']) && method_exists($mapped_data['config'], 'getImportAudiences')) {
      $audiences = $mapped_data['config']->getImportAudiences();
    }
    if (empty($audiences)) {
      $audiences = !empty($mapped_data['non_developer']) ? ['non_dev'] : ['dev'];
    }
    if ($issue->hasField('field_issue_dashboard_category')) {
      $issue->set('field_issue_dashboard_category', array_map(function ($v) { return ['value' => $v]; }, $audiences));
    }

    // Update AI Tracker metadata fields if they exist and have values.
    if ($issue->hasField('field_issue_blocked_by')) {
      if (!empty($mapped_data['blocked_by'])) {
        $issue->set('field_issue_blocked_by', $mapped_data['blocked_by']);
      }
      else {
        // Clear previously saved placeholders/invalid values.
        $issue->set('field_issue_blocked_by', []);
      }
    }
    if ($issue->hasField('field_update_summary')) {
      if (!empty($mapped_data['update_summary'])) {
        $issue->set('field_update_summary', $mapped_data['update_summary']);
      }
      else {
        $issue->set('field_update_summary', '');
      }
    }
    if ($issue->hasField('field_short_title')) {
      if (!empty($mapped_data['short_title'])) {
        $issue->set('field_short_title', $mapped_data['short_title']);
      }
      else {
        $issue->set('field_short_title', '');
      }
    }
    if ($issue->hasField('field_short_description')) {
      if (!empty($mapped_data['short_description'])) {
        // Truncate to 255 characters to fit field max length.
        $issue->set('field_short_description', mb_substr($mapped_data['short_description'], 0, 255));
      }
      else {
        $issue->set('field_short_description', '');
      }
    }
    if (!empty($mapped_data['checkin_date']) && $issue->hasField('field_checkin_date')) {
      // Convert date format if needed (MM/DD/YYYY to Y-m-d).
      $checkin_date = $this->convertDateFormat($mapped_data['checkin_date']);
      if ($checkin_date) {
        $issue->set('field_checkin_date', $checkin_date);
      }
    }
    if (!empty($mapped_data['due_date']) && $issue->hasField('field_due_date')) {
      // Convert date format if needed (MM/DD/YYYY to Y-m-d).
      $due_date = $this->convertDateFormat($mapped_data['due_date']);
      if ($due_date) {
        $issue->set('field_due_date', $due_date);
      }
    }
    if ($issue->hasField('field_additional_collaborators')) {
      if (!empty($mapped_data['additional_collaborators'])) {
        // Additional collaborators would need to be resolved to user entities
        // For now, store as a text field if the field type supports it
        $issue->set('field_additional_collaborators', $mapped_data['additional_collaborators']);
      }
      else {
        $issue->set('field_additional_collaborators', '');
      }
    }

    $issue->setChangedTime($mapped_data['changed']);

    $issue->save();

    // Create AssignmentRecords for current week if assignees exist.
    if (!empty($mapped_data['assignee_id'])) {
      $this->createAssignmentRecords($issue, $mapped_data);

    }

    // Cache this updated issue in the session.
    static::$importSessionCache[$this->createCacheId($mapped_data)] = $issue->id();

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
  protected function resolveDrupalOrgTagName(string $term_id): ?string {
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
   * Resolve project ID from machine name via drupal.org API.
   *
   * @param string $machine_name
   *   The project machine name.
   * 
   * @param string $source_type
   *   The project source type.
   *
   * @return string
   *   The project ID.
   * 
   */

    public function resolveProjectIdFromMachineName(string $machine_name, string $source_type){
        switch ($source_type) {
          case 'drupal_org':
            return $this->resolveDrupalOrgProjectIdFromMachineName($machine_name);
          case 'gitlab':
            return $this->resolveGitLabProjectIdFromMachineName($machine_name);
          default:
            throw new \Exception('Could not resolve project id from machine name: invalid source type ' . $source_type);
        }
    }


    protected function resolveDrupalOrgProjectIdFromMachineName(string $machine_name): string {
    // Static cache to avoid repeated API calls.
    static $project_cache = [];

    if (isset($project_cache[$machine_name])) {
      return $project_cache[$machine_name];
    }

    // Try multiple project types commonly used on drupal.org.
    // Some initiatives or non-module projects are not 'project_module'.
    $project_types = [
      'project_module',
      'project_theme',
      'project_distribution',
      'project_core',
      'project_profile',
      'project_general',  // Used for recipes and other general projects.
      // Fallback types (rare but included for resilience):
      'project_theme_engine',
      'project_translation',
    ];

    $last_error = NULL;
    foreach ($project_types as $type) {
      try {
        $response = $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json', [
          'query' => [
            'type' => $type,
            'field_project_machine_name' => $machine_name,
            'limit' => 1,
          ],
          'timeout' => 10,
          'headers' => [
            'User-Agent' => self::USER_AGENT,
          ],
        ]);

        if ($response->getStatusCode() !== 200) {
          $last_error = "API request failed with status: " . $response->getStatusCode();
          continue;
        }

        $data = json_decode($response->getBody()->getContents(), TRUE);
        if (!empty($data['list'])) {
          $project = reset($data['list']);
          if (!empty($project['nid'])) {
            $project_id = (string) $project['nid'];
            $project_cache[$machine_name] = $project_id;
            return $project_id;
          }
        }
      }
      catch (\Exception $e) {
        $last_error = $e->getMessage();
        // Try next type.
        continue;
      }
    }

    // Give a clear guidance if not found.
    $hint = 'Ensure this is a drupal.org project with an issue queue. If it is not a module (e.g., an initiative), provide the numeric Project ID instead.';
    $msg = "Failed to resolve project ID for machine name '{$machine_name}'. {$hint}";
    if ($last_error) {
      $msg .= ' Last error: ' . $last_error;
    }
    throw new \Exception($msg);
  }

  protected function resolveGitLabProjectIdFromMachineName(string $machine_name){

    // Static cache to avoid repeated API calls.
    static $project_cache = [];

    if (isset($project_cache[$machine_name])) {
      return $project_cache[$machine_name];
    }

    $url = 'https://git.drupalcode.org/api/v4/projects/project%2F' + $machine_name;
    $response = $this->httpClient->request('GET', $url,[
      'headers' => [
        'User-Agent' => self::USER_AGENT,
      ],
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);

       if ($data && isset($data['id'])) {
            $project_id = $data['id'];
            $project_cache[$machine_name] = $project_id;
            return $project_id;
          }
        }

    throw new \Exception("Could not find project id for {$machine_name}.");

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
  protected function buildDrupalOrgTagIds(array $tag_names): array {
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
   *   Array of issue data chunks, up to 50 items in a chunk.
   */
  public function getModuleIssuesSince(ModuleImport $config, int $timestamp) : array {
    $status_filter = $config->getStatusFilter();
    if (empty($status_filter) || !is_array($status_filter)) {
      return [];
    }

    $source_type = $config->getSourceType();


    if ($source_type === "drupal_org") {
      if (count($status_filter) > 1 ) {
        $all_chunks = [];
        foreach($status_filter as $single_status){
          $status_chunks = $this->getIssuesSince($config, $timestamp, ["single_status" => $single_status]);
          $all_chunks = array_merge($all_chunks, $status_chunks);
        }
        return $all_chunks;
      } else {
        return $this->getIssuesSince($config, $timestamp, ["single_status" => $status_filter[0]]);
      }
    }


    return $this->getIssuesSince($config, $timestamp);

  }

  public function getUpdateTime($issue_data, $config){
        switch ($config->getSourceType()){
          case 'drupal_org': 
            return $issue_data['changed'];
          case 'gitlab': 
            $changed = 0;
            if(isset($issue_data['updated_at'])){
              return strtotime($issue_data['updated_at']);
            }else{
              return strtotime($issue_data['created_at']);
            }
        }
  }

  protected function getIssuesSince(ModuleImport $config, int $timestamp, array $extra_options = []) : array {
    $page = 0;
    $chunks = [];
    $per_page_max = $this->getBatchSize($config);
    $page_issues_count = $per_page_max;

    $api_details = $this->getSourceApiDetails($config);
    $params = $this->getSourceSpecificFilters($config, $api_details['base_params'], $extra_options);
    $source_type = $config->getSourceType();
    $url = $api_details['url'];

    $headers = ['User-Agent' => self::USER_AGENT];
      if (isset($api_details['auth'])) {
        $headers[$api_details['auth']['type']] = $api_details['auth']['value'];
      }

    do {

      $current_params = $this->getPaginationParams($source_type, $params, $per_page_max, $page);

      $response = $this->requestWithRetry('GET', $url, $params, $headers);
      if (!$response['success']) {
        // Multiple failures during fetch, exit.
        return $chunks;
      }

      $res_body = $response['data'];

      $issues_data = $this->deriveIssuesData($source_type, $res_body);
      $page_issues_count = count($issues_data);

      $chunks[] = $issues_data;

      $page++;
    }
    while ($page_issues_count >= $per_page_max);
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
  protected function shouldCreateIssue($config, array $mapped_issue_data) : bool {

    $status_filter = $config->getStatusFilter();


  // Check status filter
    if ($status_filter) {
      $status_filter_text = array_map(function ($status_code) {
        return self::STATUS_MAP[$status_code];
      }, $status_filter);
      if (!in_array($mapped_issue_data['status'], $status_filter_text)) {
        return FALSE;
      }
    }

    $filter_tags = $config->getFilterTags();

    if(!$filter_tags || empty($filter_tags)) return TRUE;

    foreach ($filter_tags as $filter_tag) {
      if (in_array($filter_tag, $mapped_issue_data['tags'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Convert date from MM/DD/YYYY format to Y-m-d format for Drupal date fields.
   *
   * @param string $date_string
   *   Date string in MM/DD/YYYY format.
   *
   * @return string|null
   *   Date in Y-m-d format or null if conversion fails.
   */
  protected function convertDateFormat(string $date_string): ?string {
    // Handle MM/DD/YYYY format (US format as documented).
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_string, $matches)) {
      $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
      $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
      $year = $matches[3];

      // Validate the date.
      if (checkdate((int)$month, (int)$day, (int)$year)) {
        return "$year-$month-$day";
      }
    }

    // Try to parse other common date formats as fallback.
    $timestamp = strtotime($date_string);
    if ($timestamp !== FALSE) {
      return date('Y-m-d', $timestamp);
    }

    return NULL;
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
  protected function requestWithRetry(string $method, string $url, array $query = [], $headers = NULL) : array {
    $result = [
      'success' => FALSE,
      'attempts' => 0,
    ];

    $req_headers = $headers ?? [ 'User-Agent' => self::USER_AGENT ];

    do {
      try {
        $result['attempts']++;
        $response = $this->httpClient->request($method, $url, [
          'query' => $query,
          // Increased timeout for large imports.
          'timeout' => 60,
          'headers' => $req_headers,
        ]);
        $result['code'] = $response->getStatusCode();
        if ($result['code'] === 200) {
          $result['data'] = json_decode($response->getBody()->getContents(),
            TRUE);
          $result['success'] = TRUE;
        }
      }
      catch (ClientException $e) {
          \Drupal::logger('Issue Process service')->debug('Client exception: ' . $e->getCode());
          sleep(self::RETRY_AFTER);
          continue;
      }
    }
    while (!$result['success'] && ($result['attempts'] < self::MAX_TRIES));
    return $result;
  }

  // Deduplicate issues with same external ids but different sources

  protected function createCacheId(array $mapped_data){
        return $mapped_data['source_type'] . '--' . $mapped_data['external_id'];
  }

  protected function createAssignmentRecords(Node $issue, array $mapped_data){
    $current_week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::getCurrentWeekId();
    $issue_status = $mapped_data['status'] ?? 'active';
    $assignment_source = $mapped_data['source_type'] . '_sync';

    foreach($mapped_data['assignee_id'] as $assignee_id){
      // Check if assignment already exists to avoid duplicates.
      if (!\Drupal\ai_dashboard\Entity\AssignmentRecord::assignmentExists(
        $issue->id(),
        $assignee_id,
        $current_week_id
      )) {
        \Drupal\ai_dashboard\Entity\AssignmentRecord::createAssignment(
          $issue->id(),
          $assignee_id,
          $current_week_id,
          $assignment_source,
          $issue_status
        );
      }
    }

  }


  /**
   * Returns source-specific API details.
   *
   * @param ModuleImport $config
   *   The import configuration.
   *
   * @return array
   *   Array containing url, base_params, and pagination_type.
   */
  protected function getSourceApiDetails(ModuleImport $config): array {
    $source_type = $config->getSourceType();
    $project_id = $config->getProjectId();

    switch ($source_type) {
      case 'drupal_org':
        return [
          'url' => 'https://www.drupal.org/api-d7/node.json',
          'base_params' => [
            'type' => 'project_issue',
            'field_project' => $project_id,
            'sort' => 'changed',
            'direction' => 'DESC',
          ],
          // drupal.org API limit.
          'per_page_max'=> 50,
        ];

      case 'gitlab':
        $token = $this->settings->get('gitlab_api_token');
        if (!$token) {
          throw new \Exception('GITLAB_API_TOKEN environment variable not set');
        }
        return [
          'url' => "https://git.drupalcode.org/api/v4/projects/{$project_id}/issues",
          'base_params' => [],
          'auth' => [
            'type' => 'Private-Token',
            'value' => $token,
          ],
          // GitLab API limit
          'per_page_max' => 100
        ];

      default:
        throw new \InvalidArgumentException("Unsupported source type: {$source_type}");
    }
  }

    /**
   * Gets source-specific filters (tags, status, component, date).
   *
   * @param ModuleImport $config
   *   The import configuration.
   * @param array $base_params
   *   The base parameters from getSourceApiDetails.
   *
   * @return array
   *   The modified parameters with source-specific filters.
   */
  protected function getSourceSpecificFilters(ModuleImport $config, array $base_params, array $extra_data = []): array {
    $params = $base_params;
    $source_type = $config->getSourceType();

    $timestamp = NULL;
    if (isset($extra_data['timestamp'])) $timestamp = $extra_data['timestamp'];
    if (isset($extra_data['date_filter'])) $timestamp =strtotime($extra_data['date_filter']);
    if(!$timestamp && $config->getDateFilter()) $timestamp = strtotime($config->getDateFilter());

    switch ($source_type) {
      case 'drupal_org':
          if ($filter = $this->buildDrupalOrgTagIds($config->getFilterTags())) {
            $params['taxonomy_vocabulary_9'] = implode(',', $filter);
          }
          if ($component = $config->getFilterComponent()) {
            $params['field_issue_component'] = $component;
          }
          if ($timestamp) {
            $params['changed'] = '>=' . $timestamp;
          if(isset($extra_data['single_status'])){
            $params['field_issue_status'] = $extra_data['single_status'];
          }
        }
        break;
      case 'gitlab':
         $status_filter = isset($extra_data['single_status']) ? [$extra_data['single_status']] : $config->getStatusFilter();
          if (!empty($status_filter)) {
            $has_open = false;
            $has_closed = false;
            foreach ($status_filter as $s) {
              if (in_array($s, ['1', '13', '8', '14', '15'])) $has_open = true;
              if (in_array($s, ['2', '4', '16'])) $has_closed = true;
            }
            $params['state'] = ($has_open && $has_closed) ? 'all' : ($has_closed ? 'closed' : 'open');
          }
          if ($filter_tags = $config->getFilterTags()) {
            $params['labels'] = implode(',', $filter_tags);
          }
          if ($timestamp) {
            $params['updated_after'] = date('c', $timestamp);
          }
           break;

        default:
          throw new \InvalidArgumentException("Unsupported source type: {$source_type}");
    }





    return $params;
  }

  protected function getPaginationParams(string $source_type, array $params, int $per_page, int $current_page): array{
    $params_with_pagination = $params;

    switch ($source_type) {
      case 'drupal_org':
        $params_with_pagination['page'] = $current_page;
        $params_with_pagination['limit'] = $per_page;
        break;

      case 'gitlab':
        $params_with_pagination['page'] = $current_page;
        $params_with_pagination['per_page'] = $per_page;
        break;

      default:
        throw new \InvalidArgumentException("Unsupported source type: {$source_type}");
    }

    return $params_with_pagination;
  }

  protected function deriveIssuesData(string $source_type, $data){
      switch ($source_type) {
        case 'drupal_org':
          $issues_list = $data['list'];
          if(!isset($issues_list) || !is_array($issues_list)) {
            throw new \Exception('Invalid response format from drupal.org API');
          }
          return $issues_list;
        case 'gitlab':
          return $data;
        default:
          throw new \InvalidArgumentException("Unsupported source type: {$source_type}");
      }
    }

}
