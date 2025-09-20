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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->queueFactory = $container->get('queue');
    $instance->workerManager = $container->get('plugin.manager.queue_worker');
    $instance->importService = $container->get('ai_dashboard.issue_import');
    $instance->state = $container->get('state');
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
    $this->syncAllAssignments();
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
   *   Re-process AI Tracker metadata for all existing AI issues (debugging)
   */
  public function processMetadata() {
    $this->output()->writeln("Re-processing AI Tracker metadata for all existing AI issues...");
    
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
        
        // Get issue summary
        if ($issue->hasField('field_issue_summary') && !$issue->get('field_issue_summary')->isEmpty()) {
          $summary = $issue->get('field_issue_summary')->value;
          
          // Parse metadata
          $parsed_metadata = $metadata_parser->parseMetadata($summary);
          
          if (!empty($parsed_metadata)) {
            $needs_save = false;
            
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
              }
            }
            
            if ($needs_save) {
              $issue->save();
              $updated_count++;
              $this->output()->writeln("✅ Updated with metadata: " . implode(', ', array_keys($parsed_metadata)));
            } else {
              $this->output()->writeln("✓ Metadata already current");
            }
          } else {
            $this->output()->writeln("- No AI Tracker metadata found");
          }
        } else {
          $this->output()->writeln("- No summary content");
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
