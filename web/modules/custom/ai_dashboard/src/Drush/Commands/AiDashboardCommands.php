<?php

namespace Drupal\ai_dashboard\Drush\Commands;

use Drupal\ai_dashboard\Entity\ModuleImport;
use Drupal\ai_dashboard\Service\IssueImportService;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use Drush\Attributes as CLI;
use Drush\Attributes\Command;
use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for AI Dashboard module.
 */
class AiDashboardCommands extends DrushCommands {

  const QUEUE_NAME = 'module_import_full_do';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected QueueWorkerManagerInterface $workerManager;

  /**
   * @var \Drupal\ai_dashboard\Service\IssueImportService
   */
  protected IssueImportService $importService;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->queueFactory = $container->get('queue');
    $instance->workerManager = $container->get('plugin.manager.queue_worker');
    $instance->importService = $container->get('ai_dashboard.issue_import');
    $instance->state = $container->get('state');
    $instance->fileSystem = $container->get('file_system');
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }









  /**
   * Import issues from drupal.org for a given project.
   *
   * When --full-from option is not specified, imports issues from the last
   * update, or from the beginning of time otherwise.
   */
  #[CLI\Command(name: 'ai-dashboard:import')]
  #[CLI\Argument('config_id', 'Machine name of module import configuration.')]
  #[CLI\Option(
    name: 'full-from',
    description: 'Perform full sync starting from the date in format YYYY-mm-dd')
  ]
  #[CLI\Usage('--full-from=2025-07-01')]
  public function importSingleConfiguration(string $config_id, array $options = [
    'full-from' => NULL,
  ]) {
    $output = $this->output();
    $output->writeln('Importing issues from drupal.org');

    // Load import configuration.
    /** @var ModuleImport $config */
    $config = $this->entityTypeManager->getStorage('module_import')
      ->load($config_id);
    if (!$config) {
      $output->writeln('<error>Import configuration is not found or invalid.</error>');
      return;
    }

    $output->writeln('Configuration: ' . $config->label());
    if (!empty($options['full-from'])) {
      $start = \DateTime::createFromFormat('Y-m-d', $options['full-from'])
        ->getTimestamp();
    }
    else {
      $start = $this->state->get('ai_dashboard:last_import:' . $config_id) ?: 0;
    }
    $output->writeln('Importing issue updates since '
      . ($start ? date('Y-m-d H:i:s', $start) : ' beginning of time'));
    $chunks = $this->importService->getModuleIssuesSince($config, $start);
    if (!$chunks) {
      $this->output()->writeln('<error>No issues found.</error>');
      return;
    }
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $numIssues = 0;
    $lastUpdate = 0;
    foreach ($chunks as $chunk) {
      // Issues are sorted by updated time.
      if (!$lastUpdate) {
        $lastUpdate = $chunk[0]['changed'];
      }
      $numIssues += count($chunk);
      $queue->createItem([$config_id, $chunk]);
    }
    // This will update the timestamp of last module import.
    if ($lastUpdate) {
      $queue->createItem([$config_id, [], $lastUpdate]);
    }
    $output->writeln(strtr('Queued @issues for processing', ['@issues' => $numIssues]));
    $output->writeln('Starting queue processing');
    $output->writeln('It is safe to stop the processing at any moment');
    $output->writeln('To resume, run drush queue-run module_import_full_do');
    $worker = $this->workerManager->createInstance(self::QUEUE_NAME);
    while ($item = $queue->claimItem()) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        if (!empty($item->data[1])) {
          $output->writeln(strtr('Processed @num issues',
            ['@num' => count($item->data[1])]));
        }
      }
      catch (\Exception $e) {
        $queue->releaseItem($item);
      }
    }
    $output->writeln('Finished queue processing.');
  }

  /**
   * Import all active configurations.
   */
  #[Command(name: 'ai-dashboard:import-all')]
  #[CLI\Option(name: 'full-from', description: 'Force full sync from specified date (YYYY-mm-dd). Ignores last run timestamp.')]
  public function importAllConfigurations($options = ['full-from' => NULL]) {
    $storage = $this->entityTypeManager->getStorage('module_import');
    $activeConfigurations = $storage->loadByProperties(['active' => TRUE]);
    if (!$activeConfigurations) {
      $this->output()->writeln('No active import configurations.');
      return;
    }
    foreach ($activeConfigurations as $configuration) {
      $this->importSingleConfiguration($configuration->id(), $options);
    }

    // Sync drupal.org assignments after importing all issues
    $this->output()->writeln("\n📋 Starting assignment sync from drupal.org...");
    $this->syncAllAssignments();

    // Update organizations for any new untracked users
    $this->output()->writeln("\n🏢 Updating organizations for untracked users...");
    $this->updateNewOrganizations($options);

    $this->output()->writeln("✅ Import and sync complete!");
  }


  /**
   * Sync all drupal.org assignments for current week with history preservation.
   *
   * @command ai-dashboard:sync-assignments
   * @aliases aid-sync
   * @option week-offset Week offset from current week (0 = current, 1 = next, -1 = previous)
   * @usage ai-dashboard:sync-assignments
   *   Sync all drupal.org assignments for the current week
   * @usage ai-dashboard:sync-assignments --week-offset=1
   *   Sync all drupal.org assignments for next week
   */
  public function syncAllAssignments(array $options = ['week-offset' => 0]) {
    $week_offset = (int) $options['week-offset'];
    $this->output()->writeln("Syncing drupal.org assignments with history preservation...");
    
    // Calculate the target week.
    $target_date = new \DateTime();
    if ($week_offset !== 0) {
      $target_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
    }
    $target_date->modify('Monday this week');
    $week_string = $target_date->format('Y-m-d');
    
    if ($week_offset === 0) {
      $this->output()->writeln("Target week: {$week_string} (current week)");
    } elseif ($week_offset > 0) {
      $this->output()->writeln("Target week: {$week_string} (+{$week_offset} weeks from current)");
    } else {
      $this->output()->writeln("Target week: {$week_string} ({$week_offset} weeks from current)");
    }

    try {
      // Create a minimal request object to simulate the web request.
      $request = new \Symfony\Component\HttpFoundation\Request();
      $request->request->set('week_offset', $week_offset);

      // Create calendar controller instance directly.
      $calendar_controller = new \Drupal\ai_dashboard\Controller\CalendarController(
        \Drupal::entityTypeManager(),
        \Drupal::service('ai_dashboard.tag_mapping')
      );

      // Call the sync method.
      $response = $calendar_controller->syncAllDrupalAssignments($request);
      $data = json_decode($response->getContent(), TRUE);

      if ($data['success']) {
        $this->output()->writeln("✅ " . $data['message']);
      } else {
        $this->output()->writeln("❌ Error: " . $data['message']);
      }
    }
    catch (\Exception $e) {
      $this->output()->writeln("❌ Error during sync: " . $e->getMessage());
      \Drupal::logger('ai_dashboard')->error('Drush sync error: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Fetch and update organization data for untracked users.
   *
   * @command ai-dashboard:update-organizations
   * @aliases aid-orgs
   * @option full-from Reprocess all users from specified date (YYYY-MM-DD) to refresh organization data
   * @usage ai-dashboard:update-organizations
   *   Fetch organization data from drupal.org for all untracked users without organizations
   * @usage ai-dashboard:update-organizations --full-from=2025-01-01
   *   Refresh organization data for all untracked users from specified date
   */
  public function updateOrganizations(array $options = ['full-from' => NULL]) {
    $this->output()->writeln("Fetching organization data for untracked users...");
    $this->output()->writeln("Note: This will make multiple API calls to drupal.org and may take a while.");
    $this->output()->writeln("");

    $database = \Drupal::database();
    $calendar_controller = new \Drupal\ai_dashboard\Controller\CalendarController(
      \Drupal::entityTypeManager(),
      \Drupal::service('ai_dashboard.tag_mapping')
    );

    // Get all unique untracked usernames
    $query = $database->select('assignment_record', 'ar')
      ->fields('ar', ['assignee_username'])
      ->isNull('ar.assignee_id')
      ->isNotNull('ar.assignee_username')
      ->groupBy('ar.assignee_username');

    // If full-from is specified, include all users (even those with organizations)
    // Otherwise, only get users without organizations
    if (empty($options['full-from'])) {
      $query->isNull('ar.assignee_organization');
      $this->output()->writeln("Processing users without organizations...");
    } else {
      // Clear cache for all users if doing full refresh
      $timestamp = strtotime($options['full-from']);
      if ($timestamp) {
        $query->condition('ar.assigned_date', $timestamp, '>=');
        $this->output()->writeln("Refreshing all users from " . $options['full-from'] . "...");
      }
    }

    $usernames = $query->execute()->fetchCol();

    if (empty($usernames)) {
      $this->output()->writeln("No untracked users found.");
      return;
    }

    $this->output()->writeln("Found " . count($usernames) . " untracked users to process.");
    $this->output()->writeln("");

    $updated = 0;
    $processed = 0;
    $reflection = new \ReflectionClass($calendar_controller);
    $method = $reflection->getMethod('getUserOrganization');
    $method->setAccessible(true);

    foreach ($usernames as $username) {
      $processed++;
      $this->output()->write("[{$processed}/" . count($usernames) . "] Fetching organization for $username... ");

      // Clear cache if doing full refresh
      if (!empty($options['full-from'])) {
        \Drupal::state()->delete('ai_dashboard.user_org.' . $username);
      }

      $organization = $method->invoke($calendar_controller, $username);

      if ($organization) {
        // Update all assignment records for this user
        $count = $database->update('assignment_record')
          ->fields(['assignee_organization' => $organization])
          ->condition('assignee_username', $username)
          ->execute();

        $this->output()->writeln("✅ $organization ({$count} records updated)");
        $updated++;
      } else {
        $this->output()->writeln("(no organization found)");
      }

      // Small delay to avoid hitting API rate limits - increase delay after first few requests
      if ($processed > 1) {
        usleep(500000); // 0.5 seconds between requests
      }
    }

    $this->output()->writeln("");
    $this->output()->writeln(str_repeat('=', 60));
    $this->output()->writeln("Summary:");
    $this->output()->writeln("  Total users processed: {$processed}");
    $this->output()->writeln("  Users with organizations: {$updated}");
    $this->output()->writeln("  Users without organizations: " . ($processed - $updated));
    $this->output()->writeln("");
    $this->output()->writeln("✅ Organization update complete!");

    // Clear cache to ensure fresh data in reports
    \Drupal::cache('data')->invalidateAll();
  }

  /**
   * Update organizations for new untracked users during import.
   *
   * This is a lightweight version that only fetches organizations for users
   * that don't already have them.
   */
  protected function updateNewOrganizations(array $options = []) {
    $database = \Drupal::database();

    // Only get users without organizations
    $query = $database->select('assignment_record', 'ar')
      ->fields('ar', ['assignee_username'])
      ->isNull('ar.assignee_id')
      ->isNotNull('ar.assignee_username')
      ->isNull('ar.assignee_organization')
      ->groupBy('ar.assignee_username');

    // If full-from is specified, process all users from that date
    if (!empty($options['full-from'])) {
      $timestamp = strtotime($options['full-from']);
      if ($timestamp) {
        // For full-from, get ALL users (even with organizations) from that date
        $query = $database->select('assignment_record', 'ar')
          ->fields('ar', ['assignee_username'])
          ->isNull('ar.assignee_id')
          ->isNotNull('ar.assignee_username')
          ->condition('ar.assigned_date', $timestamp, '>=')
          ->groupBy('ar.assignee_username');
      }
    }

    $usernames = $query->execute()->fetchCol();

    if (empty($usernames)) {
      $this->output()->writeln("No new untracked users need organization data.");
      return;
    }

    $this->output()->writeln("Found " . count($usernames) . " users needing organization data.");

    $calendar_controller = new \Drupal\ai_dashboard\Controller\CalendarController(
      \Drupal::entityTypeManager(),
      \Drupal::service('ai_dashboard.tag_mapping')
    );

    $reflection = new \ReflectionClass($calendar_controller);
    $method = $reflection->getMethod('getUserOrganization');
    $method->setAccessible(true);

    $updated = 0;
    foreach ($usernames as $username) {
      // Clear cache if doing full refresh
      if (!empty($options['full-from'])) {
        \Drupal::state()->delete('ai_dashboard.user_org.' . $username);
      }

      $organization = $method->invoke($calendar_controller, $username);

      if ($organization) {
        // Update all assignment records for this user
        $database->update('assignment_record')
          ->fields(['assignee_organization' => $organization])
          ->condition('assignee_username', $username)
          ->execute();

        $this->output()->writeln("  ✓ {$username}: {$organization}");
        $updated++;
      }

      // Small delay to avoid hitting API rate limits
      usleep(500000); // 0.5 seconds
    }

    if ($updated > 0) {
      $this->output()->writeln("Updated {$updated} users with organization data.");
    }
  }

  /**
   * Apply current tag mappings to all existing AI issues.
   *
   * @command ai-dashboard:update-tag-mappings
   * @aliases aid-map
   * @usage ai-dashboard:update-tag-mappings
   *   Apply current tag mappings to all existing AI issues
   */
  public function updateTagMappings() {
    $this->output()->writeln("Applying current tag mappings to all existing AI issues...");
    
    try {
      // Create calendar controller instance to use its tag mapping update functionality.
      $calendar_controller = new \Drupal\ai_dashboard\Controller\CalendarController(
        \Drupal::entityTypeManager(),
        \Drupal::service('ai_dashboard.tag_mapping')
      );

      // Get all AI issues.
      $node_storage = $this->entityTypeManager->getStorage('node');
      $query = $node_storage->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('status', 1)
        ->accessCheck(FALSE);
      
      $issue_ids = $query->execute();
      
      if (empty($issue_ids)) {
        $this->output()->writeln("No AI issues found to update.");
        return;
      }
      
      $issues = $node_storage->loadMultiple($issue_ids);
      $total_count = count($issues);
      $this->output()->writeln("Found {$total_count} AI issues to update.");
      
      // Use the calendar controller's updateIssueMappings method.
      $reflection = new \ReflectionClass($calendar_controller);
      $method = $reflection->getMethod('updateIssueMappings');
      $method->setAccessible(true);
      
      $updated_count = $method->invoke($calendar_controller, $issues);
      
      $this->output()->writeln("✅ Successfully updated {$updated_count} AI issues with current tag mappings.");
      
      if ($updated_count < $total_count) {
        $skipped = $total_count - $updated_count;
        $this->output()->writeln("   {$skipped} issues had no changes and were skipped.");
      }
    }
    catch (\Exception $e) {
      $this->output()->writeln("❌ Error applying tag mappings: " . $e->getMessage());
      \Drupal::logger('ai_dashboard')->error('Tag mapping update error: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Process AI Tracker metadata for all existing AI issues.
   *
   * @command ai-dashboard:process-metadata
   * @aliases aid-meta
   * @usage ai-dashboard:process-metadata
   *   Re-process AI Tracker metadata and meta issue detection for all existing AI issues
   */
  public function processMetadata() {
    $this->output()->writeln("Re-processing AI Tracker metadata and meta issue detection for all existing AI issues...");
    
    try {
      // Get metadata parser service
      $metadata_parser = \Drupal::service('ai_dashboard.metadata_parser');
      
      // Get all AI issues that have summaries
      $node_storage = $this->entityTypeManager->getStorage('node');
      $query = $node_storage->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('status', 1)
        ->exists('field_issue_summary')
        ->accessCheck(FALSE);
      
      $issue_ids = $query->execute();
      
      if (empty($issue_ids)) {
        $this->output()->writeln("No AI issues with summaries found to process.");
        return;
      }
      
      $issues = $node_storage->loadMultiple($issue_ids);
      $total_count = count($issues);
      $processed_count = 0;
      $updated_count = 0;
      
      $this->output()->writeln("Found {$total_count} AI issues with summaries to process.");
      
      foreach ($issues as $issue) {
        $issue_number = $issue->hasField('field_issue_number') ? $issue->get('field_issue_number')->value : 'unknown';
        $this->output()->write("Processing issue #{$issue_number}... ");
        
        $needs_save = false;
        $parsed_metadata = [];

        // Get issue summary and parse AI Tracker metadata
        if ($issue->hasField('field_issue_summary') && !$issue->get('field_issue_summary')->isEmpty()) {
          $summary = $issue->get('field_issue_summary')->value;

          // Parse metadata
          $parsed_metadata = $metadata_parser->parseMetadata($summary);

          if (!empty($parsed_metadata)) {
            
            // Apply metadata to fields using the same logic as the import service
            foreach ($parsed_metadata as $key => $value) {
              if (empty($value)) continue;
              
              switch ($key) {
                case 'update_summary':
                  if ($issue->hasField('field_update_summary')) {
                    $current = $issue->get('field_update_summary')->value ?? '';
                    if ($current !== $value) {
                      $issue->set('field_update_summary', $value);
                      $needs_save = true;
                    }
                  }
                  break;
                  
                case 'checkin_date':
                  if ($issue->hasField('field_checkin_date')) {
                    $converted_date = $this->convertDateFormat($value);
                    $current = $issue->get('field_checkin_date')->value ?? '';
                    if ($current !== $converted_date) {
                      $issue->set('field_checkin_date', $converted_date);
                      $needs_save = true;
                    }
                  }
                  break;
                  
                case 'due_date':
                  if ($issue->hasField('field_due_date')) {
                    $converted_date = $this->convertDateFormat($value);
                    $current = $issue->get('field_due_date')->value ?? '';
                    if ($current !== $converted_date) {
                      $issue->set('field_due_date', $converted_date);
                      $needs_save = true;
                    }
                  }
                  break;
                  
                case 'blocked_by':
                  if ($issue->hasField('field_issue_blocked_by')) {
                    $current = $issue->get('field_issue_blocked_by')->value ?? '';
                    if ($current !== $value) {
                      $issue->set('field_issue_blocked_by', $value);
                      $needs_save = true;
                    }
                  }
                  break;
                  
                case 'additional_collaborators':
                  if ($issue->hasField('field_additional_collaborators')) {
                    $current = $issue->get('field_additional_collaborators')->value ?? '';
                    if ($current !== $value) {
                      $issue->set('field_additional_collaborators', $value);
                      $needs_save = true;
                    }
                  }
                  break;

                case 'short_title':
                  if ($issue->hasField('field_short_title')) {
                    $current = $issue->get('field_short_title')->value ?? '';
                    if ($current !== $value) {
                      $issue->set('field_short_title', $value);
                      $needs_save = true;
                    }
                  }
                  break;

                case 'short_description':
                  if ($issue->hasField('field_short_description')) {
                    $current = $issue->get('field_short_description')->value ?? '';
                    if ($current !== $value) {
                      $issue->set('field_short_description', $value);
                      $needs_save = true;
                    }
                  }
                  break;
              }
            }
            
          }
        }

        // Always check for meta issue detection (regardless of AI Tracker metadata)
        if ($issue->hasField('field_is_meta_issue')) {
          $title = $issue->getTitle();
          $tags = $issue->hasField('field_issue_tags') ? $issue->get('field_issue_tags')->value : '';
          $tag_array = [];
          if (!empty($tags)) {
            foreach (explode(',', $tags) as $tag) {
              $tag_array[] = trim(strtolower($tag));
            }
          }

          // Check if this should be a meta issue
          $should_be_meta = false;
          if (preg_match('/\[meta\]/i', $title)) {
            $should_be_meta = true;
          } else {
            foreach ($tag_array as $tag) {
              if (preg_match('/^meta(\s+issue)?$/i', $tag)) {
                $should_be_meta = true;
                break;
              }
            }
          }

          $current_meta = (bool) $issue->get('field_is_meta_issue')->value;
          if ($current_meta !== $should_be_meta) {
            $issue->set('field_is_meta_issue', $should_be_meta ? 1 : 0);
            $needs_save = true;
            $parsed_metadata['meta_status'] = $should_be_meta ? 'meta' : 'not-meta';
          }
        }

        // Save if needed and report results
        if ($needs_save) {
          $issue->save();
          $updated_count++;
          if (!empty($parsed_metadata)) {
            $this->output()->writeln("✅ Updated with: " . implode(', ', array_keys($parsed_metadata)));
          } else {
            $this->output()->writeln("✅ Updated");
          }
        } else {
          if (!empty($parsed_metadata)) {
            $this->output()->writeln("✓ Already current");
          } else {
            $this->output()->writeln("- No updates needed");
          }
        }
        
        $processed_count++;
      }
      
      $this->output()->writeln("✅ Processing complete!");
      $this->output()->writeln("   Processed: {$processed_count} issues");
      $this->output()->writeln("   Updated: {$updated_count} issues");
      $this->output()->writeln("   Skipped: " . ($processed_count - $updated_count) . " issues");
      
    }
    catch (\Exception $e) {
      $this->output()->writeln("❌ Error processing metadata: " . $e->getMessage());
      \Drupal::logger('ai_dashboard')->error('Metadata processing error: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Export all configuration and content from the site.
   */
  #[CLI\Command(name: 'ai-dashboard:content-export', aliases: ['aid-cexport'])]
  #[CLI\Usage(name: 'ai-dashboard:content-export', description: 'Export config and all content to public files directory')]
  public function contentExport() {
    $this->output()->writeln("🚀 Starting AI Dashboard export...\n");

    // Step 1: Export Drupal configuration
    $this->output()->writeln("📋 Step 1/7: Exporting Drupal configuration...");
    $this->exportConfiguration();

    // Step 2: Create export directory using Drupal File API
    $export_dir = 'public://ai-exports';
    if (!$this->fileSystem->prepareDirectory($export_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY)) {
      $this->output()->writeln("<error>Failed to create export directory: {$export_dir}</error>");
      return;
    }

    // Export all content types
    $this->output()->writeln("\n📦 Step 2/7: Exporting Tag Mappings...");
    $this->exportTagMappings($export_dir);

    $this->output()->writeln("\n📦 Step 3/7: Exporting AI Projects...");
    $this->exportProjects($export_dir);

    $this->output()->writeln("\n📦 Step 4/7: Exporting Assignment History...");
    $this->exportAssignmentHistory($export_dir);

    $this->output()->writeln("\n📦 Step 5/7: Exporting Project-Issue Relationships...");
    $this->exportProjectIssues($export_dir);

    $this->output()->writeln("\n📦 Step 6/7: Exporting Roadmap Ordering...");
    $this->exportRoadmapOrder($export_dir);

    // Step 7: Check for config changes and create zip if needed
    $this->output()->writeln("\n📦 Step 7/7: Checking for config changes...");
    $this->exportConfigZipIfChanged($export_dir);

    // Output public URLs
    $base_url = \Drupal::request()->getSchemeAndHttpHost();
    $this->output()->writeln("\n✅ Export complete! Files available at:");
    $this->output()->writeln("   {$base_url}/sites/default/files/ai-exports/tag-mappings.json");
    $this->output()->writeln("   {$base_url}/sites/default/files/ai-exports/projects.json");
    $this->output()->writeln("   {$base_url}/sites/default/files/ai-exports/assignment-history.json");
    $this->output()->writeln("   {$base_url}/sites/default/files/ai-exports/project-issues.json");
    $this->output()->writeln("   {$base_url}/sites/default/files/ai-exports/roadmap-order.json");
  }

  /**
   * Export Drupal configuration.
   */
  private function exportConfiguration() {
    try {
      // Use Drush's process manager with command array
      $process = \Drush\Drush::process(['drush', 'config:export', '-y']);
      $process->setTimeout(120);
      $process->run();

      if ($process->isSuccessful()) {
        $this->output()->writeln("   ✅ Configuration exported");
      }
      else {
        $this->output()->writeln("<comment>   ⚠️  Config export had warnings: " . $process->getErrorOutput() . "</comment>");
      }
    }
    catch (\Throwable $e) {
      $this->output()->writeln("<error>   ❌ Config export failed: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Check for uncommitted config changes and create zip if needed.
   */
  private function exportConfigZipIfChanged($export_dir) {
    // ALLOWLIST: Only these config patterns are safe to sync publicly
    // Everything else is skipped for security
    $allowed_patterns = [
      'field.field.',                    // Field instance configs
      'field.storage.',                  // Field storage configs
      'views.view.',                     // Views configs
      'core.entity_form_display.',       // Form display configs
      'core.entity_view_display.',       // View display configs
      'core.menu.static_menu_link_overrides',  // Menu overrides
      'node.type.',                      // Content type configs
      'taxonomy.vocabulary.',            // Taxonomy vocabulary configs
      'ai_dashboard.module_import.',     // AI Dashboard import configs
    ];

    try {
      // Check if there are uncommitted changes in config/sync
      // Run from project root to ensure git finds the repo
      $project_root = DRUPAL_ROOT . '/..';
      $process = \Drush\Drush::process(['git', 'status', '--porcelain', 'config/sync/'], $project_root);
      $process->setTimeout(30);
      $process->run();

      $changes = trim($process->getOutput());

      if (empty($changes)) {
        $this->output()->writeln("   ✅ No config changes to export");
        // Remove old zip if it exists
        $zip_path = $export_dir . '/config-sync.zip';
        $real_path = $this->fileSystem->realpath($zip_path);
        if ($real_path && file_exists($real_path)) {
          unlink($real_path);
        }
        return;
      }

      // Parse changed files from git status output
      $changed_files = [];
      $skipped_files = [];
      foreach (explode("\n", $changes) as $line) {
        $line = trim($line);
        if (empty($line)) {
          continue;
        }
        // Git status format: " M config/sync/file.yml" or "?? config/sync/file.yml"
        $parts = preg_split('/\s+/', $line, 2);
        if (count($parts) === 2) {
          $file_path = $parts[1];
          $filename = basename($file_path);

          // Check if file matches allowed patterns (allowlist approach)
          $is_allowed = FALSE;
          foreach ($allowed_patterns as $pattern) {
            if (strpos($filename, $pattern) === 0) {
              $is_allowed = TRUE;
              break;
            }
          }

          if ($is_allowed) {
            $changed_files[] = $file_path;
          }
          else {
            $skipped_files[] = $filename;
          }
        }
      }

      if (empty($changed_files)) {
        $this->output()->writeln("   ✅ No allowed config changes to export");
        if (!empty($skipped_files)) {
          $this->output()->writeln("   ℹ️  Skipped (not in allowlist): " . implode(', ', $skipped_files));
        }
        return;
      }

      // There are changes - create a zip of only allowed changed files
      $this->output()->writeln("   📦 Config changes detected, creating zip...");

      $config_dir = DRUPAL_ROOT . '/../config/sync';
      $zip_destination = $this->fileSystem->realpath($export_dir) . '/config-sync.zip';

      // Create zip file
      $zip = new \ZipArchive();
      if ($zip->open($zip_destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
        throw new \Exception("Cannot create zip file: {$zip_destination}");
      }

      // Add only the allowed changed files
      foreach ($changed_files as $file_path) {
        $full_path = DRUPAL_ROOT . '/../' . $file_path;
        if (file_exists($full_path)) {
          $zip->addFile($full_path, basename($file_path));
        }
      }

      $zip->close();

      $base_url = \Drupal::request()->getSchemeAndHttpHost();
      $this->output()->writeln("   ✅ Config zip created: {$base_url}/sites/default/files/ai-exports/config-sync.zip");
      $this->output()->writeln("   📋 Included files:");
      foreach ($changed_files as $file) {
        $this->output()->writeln("      " . basename($file));
      }
      if (!empty($skipped_files)) {
        $this->output()->writeln("   ℹ️  Skipped (not in allowlist): " . implode(', ', $skipped_files));
      }

      // Restore config/sync to last committed state so git stays clean
      $restore_process = \Drush\Drush::process(['git', 'checkout', '--', 'config/sync/'], $project_root);
      $restore_process->setTimeout(30);
      $restore_process->run();
      if ($restore_process->isSuccessful()) {
        $this->output()->writeln("   🧹 Restored config/sync/ to git state");
      }
    }
    catch (\Throwable $e) {
      $this->output()->writeln("<error>   ❌ Config zip failed: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Export tag mappings to JSON.
   */
  private function exportTagMappings($export_dir) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');
      $mapping_ids = $node_storage->getQuery()
        ->condition('type', 'ai_tag_mapping')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();

      $export_data = [];
      if (!empty($mapping_ids)) {
        $mappings = $node_storage->loadMultiple($mapping_ids);
        foreach ($mappings as $mapping) {
          if ($mapping->hasField('field_source_tag') && !$mapping->get('field_source_tag')->isEmpty() &&
              $mapping->hasField('field_mapping_type') && !$mapping->get('field_mapping_type')->isEmpty() &&
              $mapping->hasField('field_mapped_value') && !$mapping->get('field_mapped_value')->isEmpty()) {

            $export_data[] = [
              'title' => $mapping->getTitle(),
              'source_tag' => $mapping->get('field_source_tag')->value,
              'mapping_type' => $mapping->get('field_mapping_type')->value,
              'mapped_value' => $mapping->get('field_mapped_value')->value,
            ];
          }
        }
      }

      $this->writeJsonFile($export_dir . '/tag-mappings.json', $export_data);
      $this->output()->writeln("   ✅ Exported " . count($export_data) . " tag mappings");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Export AI Projects to JSON.
   */
  private function exportProjects($export_dir) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');
      $project_ids = $node_storage->getQuery()
        ->condition('type', 'ai_project')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();

      $export_data = [];
      if (!empty($project_ids)) {
        $projects = $node_storage->loadMultiple($project_ids);
        foreach ($projects as $project) {
          $data = [
            'title' => $project->getTitle(),
            'body' => $project->hasField('body') && !$project->get('body')->isEmpty() ? $project->get('body')->value : '',
            'field_project_tags' => $project->hasField('field_project_tags') && !$project->get('field_project_tags')->isEmpty() ? $project->get('field_project_tags')->value : '',
            'field_project_deliverable_issue_number' => NULL,
          ];

          // Resolve deliverable NID to issue number
          if ($project->hasField('field_project_deliverable') && !$project->get('field_project_deliverable')->isEmpty()) {
            $deliverable_nid = $project->get('field_project_deliverable')->target_id;
            if ($deliverable_nid) {
              $issue = $node_storage->load($deliverable_nid);
              if ($issue && $issue->hasField('field_issue_number')) {
                $data['field_project_deliverable_issue_number'] = $issue->get('field_issue_number')->value;
              }
            }
          }

          $export_data[] = $data;
        }
      }

      $this->writeJsonFile($export_dir . '/projects.json', $export_data);
      $this->output()->writeln("   ✅ Exported " . count($export_data) . " projects");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Export Assignment History to JSON.
   */
  private function exportAssignmentHistory($export_dir) {
    try {
      $assignment_storage = $this->entityTypeManager->getStorage('assignment_record');
      $assignments = $assignment_storage->loadMultiple();

      $export_data = [];
      $node_storage = $this->entityTypeManager->getStorage('node');

      foreach ($assignments as $assignment) {
        // Resolve issue NID to issue number
        $issue_number = NULL;
        if ($assignment->get('issue_id')->target_id) {
          $issue = $node_storage->load($assignment->get('issue_id')->target_id);
          if ($issue && $issue->hasField('field_issue_number')) {
            $issue_number = $issue->get('field_issue_number')->value;
          }
        }

        // Skip if no issue number (orphaned record)
        if (!$issue_number) {
          continue;
        }

        // Use assignee_username field (already portable)
        $assignee_username = $assignment->hasField('assignee_username') && !$assignment->get('assignee_username')->isEmpty()
          ? $assignment->get('assignee_username')->value
          : NULL;

        // Skip if no assignee username
        if (!$assignee_username) {
          continue;
        }

        $export_data[] = [
          'issue_number' => $issue_number,
          'assignee_username' => $assignee_username,
          'assignee_organization' => $assignment->hasField('assignee_organization') && !$assignment->get('assignee_organization')->isEmpty()
            ? $assignment->get('assignee_organization')->value
            : '',
          'week_id' => $assignment->get('week_id')->value,
          'week_date' => $assignment->get('week_date')->value,
          'issue_status_at_assignment' => $assignment->get('issue_status_at_assignment')->value,
          'assigned_date' => $assignment->get('assigned_date')->value,
          'source' => $assignment->get('source')->value,
        ];
      }

      $this->writeJsonFile($export_dir . '/assignment-history.json', $export_data);
      $this->output()->writeln("   ✅ Exported " . count($export_data) . " assignment records");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Export Project-Issue relationships to JSON.
   */
  private function exportProjectIssues($export_dir) {
    try {
      $database = \Drupal::database();
      $query = $database->select('ai_dashboard_project_issue', 'pi')
        ->fields('pi', ['project_nid', 'issue_nid', 'weight', 'indent_level', 'parent_issue_nid']);
      $results = $query->execute()->fetchAll();

      $export_data = [];
      $node_storage = $this->entityTypeManager->getStorage('node');

      foreach ($results as $row) {
        // Resolve project NID to title
        $project = $node_storage->load($row->project_nid);
        $project_title = $project ? $project->getTitle() : NULL;

        // Resolve issue NID to issue number
        $issue = $node_storage->load($row->issue_nid);
        $issue_number = ($issue && $issue->hasField('field_issue_number')) ? $issue->get('field_issue_number')->value : NULL;

        // Resolve parent issue NID to issue number
        $parent_issue_number = NULL;
        if ($row->parent_issue_nid) {
          $parent_issue = $node_storage->load($row->parent_issue_nid);
          if ($parent_issue && $parent_issue->hasField('field_issue_number')) {
            $parent_issue_number = $parent_issue->get('field_issue_number')->value;
          }
        }

        // Skip if missing key data
        if (!$project_title || !$issue_number) {
          continue;
        }

        $export_data[] = [
          'project_title' => $project_title,
          'issue_number' => $issue_number,
          'weight' => (int) $row->weight,
          'indent_level' => (int) $row->indent_level,
          'parent_issue_number' => $parent_issue_number,
        ];
      }

      $this->writeJsonFile($export_dir . '/project-issues.json', $export_data);
      $this->output()->writeln("   ✅ Exported " . count($export_data) . " project-issue relationships");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Export Roadmap ordering to JSON.
   */
  private function exportRoadmapOrder($export_dir) {
    try {
      $database = \Drupal::database();
      $query = $database->select('ai_dashboard_roadmap_order', 'ro')
        ->fields('ro', ['issue_nid', 'column_name', 'weight']);
      $results = $query->execute()->fetchAll();

      $export_data = [];
      $node_storage = $this->entityTypeManager->getStorage('node');

      foreach ($results as $row) {
        // Resolve issue NID to issue number
        $issue = $node_storage->load($row->issue_nid);
        $issue_number = ($issue && $issue->hasField('field_issue_number')) ? $issue->get('field_issue_number')->value : NULL;

        // Skip if no issue number
        if (!$issue_number) {
          continue;
        }

        $export_data[] = [
          'issue_number' => $issue_number,
          'column_name' => $row->column_name,
          'weight' => (int) $row->weight,
        ];
      }

      $this->writeJsonFile($export_dir . '/roadmap-order.json', $export_data);
      $this->output()->writeln("   ✅ Exported " . count($export_data) . " roadmap orderings");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Write data to JSON file using Drupal File API.
   */
  private function writeJsonFile($file_uri, array $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($this->fileSystem->saveData($json, $file_uri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE) === FALSE) {
      throw new \Exception("Failed to write file: {$file_uri}");
    }
  }

  /**
   * Import all configuration and content to the site.
   */
  #[CLI\Command(name: 'ai-dashboard:content-import', aliases: ['aid-cimport'])]
  #[CLI\Option(name: 'replace', description: 'Replace existing content instead of skipping')]
  #[CLI\Option(name: 'source', description: 'Source: live or local (default: live)')]
  #[CLI\Option(name: 'live-url', description: 'Override live site URL')]
  #[CLI\Usage(name: 'ai-dashboard:content-import', description: 'Import config and all content from live site')]
  #[CLI\Usage(name: 'ai-dashboard:content-import --source=local', description: 'Import from local files only')]
  #[CLI\Usage(name: 'ai-dashboard:content-import --replace', description: 'Replace existing content during import')]
  #[CLI\Usage(name: 'ai-dashboard:content-import --live-url=https://other-site.com', description: 'Import from a different live site')]
  public function contentImport(array $options = ['replace' => FALSE, 'source' => 'live', 'live-url' => NULL]) {
    $this->output()->writeln("🚀 Starting AI Dashboard import...\n");

    // Step 1: Determine source and get files
    $this->output()->writeln("📥 Step 1/7: Getting export files...");
    $import_path = $this->getImportFiles($options['source'], $options['live-url']);

    if (!$import_path) {
      $this->output()->writeln("<error>❌ Could not get import files. Aborting.</error>");
      return;
    }

    // Step 2-6: Import all content
    $this->output()->writeln("\n📦 Step 2/7: Importing Tag Mappings...");
    $this->importTagMappingsFromFile($import_path, $options['replace']);

    $this->output()->writeln("\n📦 Step 3/7: Importing AI Projects...");
    $this->importProjectsFromFile($import_path, $options['replace']);

    $this->output()->writeln("\n📦 Step 4/7: Importing Assignment History...");
    $this->importAssignmentHistoryFromFile($import_path, $options['replace']);

    $this->output()->writeln("\n📦 Step 5/7: Importing Project-Issue Relationships...");
    $this->importProjectIssuesFromFile($import_path, $options['replace']);

    $this->output()->writeln("\n📦 Step 6/7: Importing Roadmap Ordering...");
    $this->importRoadmapOrderFromFile($import_path, $options['replace']);

    // Step 7: Import configuration
    $this->output()->writeln("\n📋 Step 7/7: Importing Drupal configuration...");
    $this->importConfiguration();

    // Clear caches
    $this->output()->writeln("\n🧹 Clearing caches...");
    drupal_flush_all_caches();

    $this->output()->writeln("\n✅ Import complete!");
  }

  /**
   * Get import files from source.
   */
  private function getImportFiles($source, $live_url) {
    $export_dir = 'public://ai-exports';
    $local_path = $this->fileSystem->realpath($export_dir);

    if ($source === 'local') {
      if ($local_path && file_exists($local_path)) {
        $this->output()->writeln("   ✅ Using local files from: {$local_path}");
        return $local_path;
      }
      else {
        $this->output()->writeln("<error>   ❌ Local export directory not found: {$export_dir}</error>");
        return NULL;
      }
    }

    // Download from live site
    if (!$live_url) {
      // Get from config or use default
      $config = \Drupal::config('ai_dashboard.settings');
      $live_url = $config->get('live_site_url') ?? 'https://www.drupalstarforge.ai';
    }

    $this->output()->writeln("   📡 Downloading from: {$live_url}");

    // Ensure export directory exists using File API
    if (!$this->fileSystem->prepareDirectory($export_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY)) {
      $this->output()->writeln("<error>   ❌ Failed to create export directory: {$export_dir}</error>");
      return NULL;
    }

    $files = ['tag-mappings.json', 'projects.json', 'assignment-history.json', 'project-issues.json', 'roadmap-order.json'];
    $success = TRUE;

    foreach ($files as $file) {
      $url = "{$live_url}/sites/default/files/ai-exports/{$file}";
      $destination = $export_dir . '/' . $file;

      try {
        $response = $this->httpClient->get($url, ['timeout' => 30]);
        $content = (string) $response->getBody();

        // Use Drupal File API to save
        if ($this->fileSystem->saveData($content, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE) === FALSE) {
          throw new \Exception("Failed to save file: {$destination}");
        }

        $this->output()->writeln("      ✅ Downloaded: {$file}");
      }
      catch (\Exception $e) {
        $this->output()->writeln("<comment>      ⚠️  Failed to download {$file}: " . $e->getMessage() . "</comment>");
        $success = FALSE;
      }
    }

    if (!$success) {
      $this->output()->writeln("<comment>   ⚠️  Some downloads failed. Checking for local files...</comment>");
      $local_path = $this->fileSystem->realpath($export_dir);
      if ($local_path && file_exists($local_path)) {
        $this->output()->writeln("   ✅ Falling back to local files");
        return $local_path;
      }
      else {
        $this->output()->writeln("<error>   ❌ No local files available. Use --source=local to skip download.</error>");
        return NULL;
      }
    }

    // Try to download config zip if it exists
    $this->downloadAndExtractConfigZip($live_url, $export_dir);

    return $this->fileSystem->realpath($export_dir);
  }

  /**
   * Download and extract config zip if it exists on live.
   */
  private function downloadAndExtractConfigZip($live_url, $export_dir) {
    $zip_url = "{$live_url}/sites/default/files/ai-exports/config-sync.zip";
    $zip_destination = $export_dir . '/config-sync.zip';

    try {
      $response = $this->httpClient->get($zip_url, ['timeout' => 30]);
      $content = (string) $response->getBody();

      // Save the zip file
      if ($this->fileSystem->saveData($content, $zip_destination, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE) === FALSE) {
        throw new \Exception("Failed to save zip file");
      }

      $this->output()->writeln("      ✅ Downloaded: config-sync.zip");

      // Extract to config/sync
      $zip_path = $this->fileSystem->realpath($zip_destination);
      $config_dir = DRUPAL_ROOT . '/../config/sync';

      $zip = new \ZipArchive();
      if ($zip->open($zip_path) === TRUE) {
        $zip->extractTo($config_dir);
        $zip->close();
        $this->output()->writeln("      ✅ Extracted config to: config/sync/");
        $this->output()->writeln("      📋 Remember to commit these config changes!");
      }
      else {
        throw new \Exception("Failed to open zip file");
      }
    }
    catch (\GuzzleHttp\Exception\ClientException $e) {
      // 404 is expected if there are no config changes
      if ($e->getResponse()->getStatusCode() === 404) {
        $this->output()->writeln("      ℹ️  No config changes to download (config-sync.zip not found)");
      }
      else {
        $this->output()->writeln("<comment>      ⚠️  Failed to download config zip: " . $e->getMessage() . "</comment>");
      }
    }
    catch (\Exception $e) {
      $this->output()->writeln("<comment>      ⚠️  Failed to download config zip: " . $e->getMessage() . "</comment>");
    }
  }

  /**
   * Import tag mappings from JSON file.
   */
  private function importTagMappingsFromFile($import_path, $replace) {
    $file = $import_path . '/tag-mappings.json';
    if (!file_exists($file)) {
      $this->output()->writeln("<comment>   ⚠️  File not found: tag-mappings.json</comment>");
      return;
    }

    try {
      $data = json_decode(file_get_contents($file), TRUE);
      if (!$data) {
        $this->output()->writeln("   ℹ️  No tag mappings to import");
        return;
      }

      $node_storage = $this->entityTypeManager->getStorage('node');
      $created = 0;
      $updated = 0;
      $skipped = 0;

      foreach ($data as $mapping) {
        if (empty($mapping['source_tag']) || empty($mapping['mapping_type']) || empty($mapping['mapped_value'])) {
          $skipped++;
          continue;
        }

        // Check if exists
        $existing_ids = $node_storage->getQuery()
          ->condition('type', 'ai_tag_mapping')
          ->condition('field_source_tag', $mapping['source_tag'])
          ->condition('field_mapping_type', $mapping['mapping_type'])
          ->accessCheck(FALSE)
          ->execute();

        if (!empty($existing_ids)) {
          if ($replace) {
            $node = $node_storage->load(reset($existing_ids));
            $node->set('field_mapped_value', $mapping['mapped_value']);
            $node->setTitle($mapping['title']);
            $node->save();
            $updated++;
          }
          else {
            $skipped++;
          }
        }
        else {
          $node_storage->create([
            'type' => 'ai_tag_mapping',
            'title' => $mapping['title'],
            'status' => 1,
            'field_source_tag' => $mapping['source_tag'],
            'field_mapping_type' => $mapping['mapping_type'],
            'field_mapped_value' => $mapping['mapped_value'],
          ])->save();
          $created++;
        }
      }

      // Clear tag mapping cache
      \Drupal::service('ai_dashboard.tag_mapping')->clearCache();

      $this->output()->writeln("   ✅ Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Import AI Projects from JSON file.
   */
  private function importProjectsFromFile($import_path, $replace) {
    $file = $import_path . '/projects.json';
    if (!file_exists($file)) {
      $this->output()->writeln("<comment>   ⚠️  File not found: projects.json</comment>");
      return;
    }

    try {
      $data = json_decode(file_get_contents($file), TRUE);
      if (!$data) {
        $this->output()->writeln("   ℹ️  No projects to import");
        return;
      }

      $node_storage = $this->entityTypeManager->getStorage('node');
      $created = 0;
      $updated = 0;
      $skipped = 0;

      foreach ($data as $project) {
        if (empty($project['title'])) {
          $skipped++;
          continue;
        }

        // Resolve deliverable issue number to local NID
        $deliverable_nid = NULL;
        if (!empty($project['field_project_deliverable_issue_number'])) {
          $issue_ids = $node_storage->getQuery()
            ->condition('type', 'ai_issue')
            ->condition('field_issue_number', $project['field_project_deliverable_issue_number'])
            ->accessCheck(FALSE)
            ->execute();

          if (!empty($issue_ids)) {
            $deliverable_nid = reset($issue_ids);
          }
          else {
            $this->output()->writeln("<comment>      ⚠️  Deliverable issue #{$project['field_project_deliverable_issue_number']} not found for project: {$project['title']}</comment>");
          }
        }

        // Check if project exists by title
        $existing_ids = $node_storage->getQuery()
          ->condition('type', 'ai_project')
          ->condition('title', $project['title'])
          ->accessCheck(FALSE)
          ->execute();

        if (!empty($existing_ids)) {
          if ($replace) {
            $node = $node_storage->load(reset($existing_ids));
            $node->set('body', $project['body']);
            $node->set('field_project_tags', $project['field_project_tags']);
            $node->set('field_project_deliverable', $deliverable_nid ? ['target_id' => $deliverable_nid] : NULL);
            $node->save();
            $updated++;
          }
          else {
            $skipped++;
          }
        }
        else {
          $node_storage->create([
            'type' => 'ai_project',
            'title' => $project['title'],
            'status' => 1,
            'body' => $project['body'],
            'field_project_tags' => $project['field_project_tags'],
            'field_project_deliverable' => $deliverable_nid ? ['target_id' => $deliverable_nid] : NULL,
          ])->save();
          $created++;
        }
      }

      $this->output()->writeln("   ✅ Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Import Assignment History from JSON file.
   */
  private function importAssignmentHistoryFromFile($import_path, $replace) {
    $file = $import_path . '/assignment-history.json';
    if (!file_exists($file)) {
      $this->output()->writeln("<comment>   ⚠️  File not found: assignment-history.json</comment>");
      return;
    }

    try {
      $data = json_decode(file_get_contents($file), TRUE);
      if (!$data) {
        $this->output()->writeln("   ℹ️  No assignment history to import");
        return;
      }

      $node_storage = $this->entityTypeManager->getStorage('node');
      $assignment_storage = $this->entityTypeManager->getStorage('assignment_record');
      $created = 0;
      $updated = 0;
      $skipped = 0;

      foreach ($data as $assignment) {
        // CRITICAL: Resolve portable identifiers to local NIDs

        // Resolve issue_number to local issue NID
        $issue_ids = $node_storage->getQuery()
          ->condition('type', 'ai_issue')
          ->condition('field_issue_number', $assignment['issue_number'])
          ->accessCheck(FALSE)
          ->execute();

        if (empty($issue_ids)) {
          $this->output()->writeln("<comment>      ⚠️  Issue #{$assignment['issue_number']} not found - skipping assignment</comment>");
          $skipped++;
          continue;
        }
        $issue_nid = reset($issue_ids);

        // Resolve assignee_username to local contributor NID
        $contributor_ids = $node_storage->getQuery()
          ->condition('type', 'ai_contributor')
          ->condition('field_drupal_username', $assignment['assignee_username'])
          ->accessCheck(FALSE)
          ->execute();

        if (empty($contributor_ids)) {
          $this->output()->writeln("<comment>      ⚠️  Contributor '{$assignment['assignee_username']}' not found - skipping assignment</comment>");
          $skipped++;
          continue;
        }
        $assignee_nid = reset($contributor_ids);

        // Check if assignment already exists (issue + assignee + week_id)
        $existing_assignments = $assignment_storage->loadByProperties([
          'issue_id' => $issue_nid,
          'assignee_id' => $assignee_nid,
          'week_id' => $assignment['week_id'],
        ]);

        if (!empty($existing_assignments)) {
          if ($replace) {
            $existing = reset($existing_assignments);
            $existing->set('issue_status_at_assignment', $assignment['issue_status_at_assignment']);
            $existing->set('source', $assignment['source']);
            $existing->save();
            $updated++;
          }
          else {
            $skipped++;
          }
        }
        else {
          // Create new assignment record using local NIDs
          $assignment_storage->create([
            'issue_id' => $issue_nid,
            'assignee_id' => $assignee_nid,
            'assignee_username' => $assignment['assignee_username'],
            'assignee_organization' => $assignment['assignee_organization'],
            'week_id' => $assignment['week_id'],
            'week_date' => $assignment['week_date'],
            'issue_status_at_assignment' => $assignment['issue_status_at_assignment'],
            'assigned_date' => $assignment['assigned_date'],
            'source' => $assignment['source'],
          ])->save();
          $created++;
        }
      }

      $this->output()->writeln("   ✅ Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Import Project-Issue relationships from JSON file.
   */
  private function importProjectIssuesFromFile($import_path, $replace) {
    $file = $import_path . '/project-issues.json';
    if (!file_exists($file)) {
      $this->output()->writeln("<comment>   ⚠️  File not found: project-issues.json</comment>");
      return;
    }

    try {
      $data = json_decode(file_get_contents($file), TRUE);
      if (!$data) {
        $this->output()->writeln("   ℹ️  No project-issue relationships to import");
        return;
      }

      $database = \Drupal::database();
      $node_storage = $this->entityTypeManager->getStorage('node');
      $created = 0;
      $skipped = 0;

      // Group by project for efficient replace handling
      $projects_to_clear = [];

      foreach ($data as $relationship) {
        // Resolve project_title to local NID
        $project_ids = $node_storage->getQuery()
          ->condition('type', 'ai_project')
          ->condition('title', $relationship['project_title'])
          ->accessCheck(FALSE)
          ->execute();

        if (empty($project_ids)) {
          $this->output()->writeln("<comment>      ⚠️  Project '{$relationship['project_title']}' not found - skipping relationship</comment>");
          $skipped++;
          continue;
        }
        $project_nid = reset($project_ids);

        // Resolve issue_number to local NID
        $issue_ids = $node_storage->getQuery()
          ->condition('type', 'ai_issue')
          ->condition('field_issue_number', $relationship['issue_number'])
          ->accessCheck(FALSE)
          ->execute();

        if (empty($issue_ids)) {
          $this->output()->writeln("<comment>      ⚠️  Issue #{$relationship['issue_number']} not found - skipping relationship</comment>");
          $skipped++;
          continue;
        }
        $issue_nid = reset($issue_ids);

        // Resolve parent_issue_number to local NID if present
        $parent_nid = NULL;
        if (!empty($relationship['parent_issue_number'])) {
          $parent_ids = $node_storage->getQuery()
            ->condition('type', 'ai_issue')
            ->condition('field_issue_number', $relationship['parent_issue_number'])
            ->accessCheck(FALSE)
            ->execute();

          if (!empty($parent_ids)) {
            $parent_nid = reset($parent_ids);
          }
        }

        // Clear project relationships on first encounter if replace mode
        if ($replace && !isset($projects_to_clear[$project_nid])) {
          $database->delete('ai_dashboard_project_issue')
            ->condition('project_nid', $project_nid)
            ->execute();
          $projects_to_clear[$project_nid] = TRUE;
        }

        // Insert relationship
        $database->merge('ai_dashboard_project_issue')
          ->keys([
            'project_nid' => $project_nid,
            'issue_nid' => $issue_nid,
          ])
          ->fields([
            'weight' => $relationship['weight'],
            'indent_level' => $relationship['indent_level'],
            'parent_issue_nid' => $parent_nid,
          ])
          ->execute();
        $created++;
      }

      $this->output()->writeln("   ✅ Created/Updated: {$created}, Skipped: {$skipped}");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Import Roadmap ordering from JSON file.
   */
  private function importRoadmapOrderFromFile($import_path, $replace) {
    $file = $import_path . '/roadmap-order.json';
    if (!file_exists($file)) {
      $this->output()->writeln("<comment>   ⚠️  File not found: roadmap-order.json</comment>");
      return;
    }

    try {
      $data = json_decode(file_get_contents($file), TRUE);
      if (!$data) {
        $this->output()->writeln("   ℹ️  No roadmap ordering to import");
        return;
      }

      $database = \Drupal::database();
      $node_storage = $this->entityTypeManager->getStorage('node');
      $created = 0;
      $skipped = 0;

      // Clear all if replace mode
      if ($replace) {
        $database->truncate('ai_dashboard_roadmap_order')->execute();
      }

      foreach ($data as $ordering) {
        // Resolve issue_number to local NID
        $issue_ids = $node_storage->getQuery()
          ->condition('type', 'ai_issue')
          ->condition('field_issue_number', $ordering['issue_number'])
          ->accessCheck(FALSE)
          ->execute();

        if (empty($issue_ids)) {
          $this->output()->writeln("<comment>      ⚠️  Issue #{$ordering['issue_number']} not found - skipping ordering</comment>");
          $skipped++;
          continue;
        }
        $issue_nid = reset($issue_ids);

        // Insert ordering
        $database->merge('ai_dashboard_roadmap_order')
          ->keys(['issue_nid' => $issue_nid])
          ->fields([
            'column_name' => $ordering['column_name'],
            'weight' => $ordering['weight'],
          ])
          ->execute();
        $created++;
      }

      $this->output()->writeln("   ✅ Created/Updated: {$created}, Skipped: {$skipped}");
    }
    catch (\Exception $e) {
      $this->output()->writeln("<error>   ❌ Error: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Import Drupal configuration.
   */
  private function importConfiguration() {
    try {
      // Use Drush's process manager with command array
      $process = \Drush\Drush::process(['drush', 'config:import', '-y']);
      $process->setTimeout(120);
      $process->run();

      if ($process->isSuccessful()) {
        $this->output()->writeln("   ✅ Configuration imported");
      }
      else {
        $this->output()->writeln("<comment>   ⚠️  Config import had warnings: " . $process->getErrorOutput() . "</comment>");
      }
    }
    catch (\Throwable $e) {
      $this->output()->writeln("<error>   ❌ Config import failed: " . $e->getMessage() . "</error>");
    }
  }

  /**
   * Convert date from MM/DD/YYYY format to Y-m-d format.
   *
   * @param string $date_string
   *   Date string in various formats.
   *
   * @return string
   *   Date in Y-m-d format, or original string if conversion fails.
   */
  private function convertDateFormat($date_string) {
    if (empty($date_string)) {
      return '';
    }

    // Try MM/DD/YYYY format first
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $date_string, $matches)) {
      $month = (int) $matches[1];
      $day = (int) $matches[2];
      $year = (int) $matches[3];

      if (checkdate($month, $day, $year)) {
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
      }
    }

    // Try other common formats using strtotime
    $timestamp = strtotime($date_string);
    if ($timestamp !== false) {
      return date('Y-m-d', $timestamp);
    }

    // Return original if can't convert
    return $date_string;
  }

}
