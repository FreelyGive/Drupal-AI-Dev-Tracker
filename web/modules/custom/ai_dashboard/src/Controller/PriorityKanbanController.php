<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_dashboard\Service\TagMappingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Priority Kanban Board Controller for AI Dashboard.
 */
class PriorityKanbanController extends ControllerBase {

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * The tag mapping service.
   */
  protected $tagMappingService;

  /**
   * Constructs a PriorityKanbanController object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, TagMappingService $tag_mapping_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->tagMappingService = $tag_mapping_service;
  }

  /**
   * Collect all distinct tags from issues for filter options.
   */
  private function getAllIssueTags(): array {
    $tags = [];
    try {
      $ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('status', 1)
        ->accessCheck(TRUE)
        ->execute();
      if (!empty($ids)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
        foreach ($nodes as $n) {
          if ($n->hasField('field_issue_tags') && !$n->get('field_issue_tags')->isEmpty()) {
            foreach ($n->get('field_issue_tags')->getValue() as $item) {
              if (!empty($item['value'])) {
                $raw = $item['value'];
                // Support comma-separated tags in a single item.
                $parts = preg_split('/\s*,\s*/', $raw);
                foreach ($parts as $p) {
                  if ($p !== '') { $tags[] = $p; }
                }
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // Ignore.
    }
    $tags = array_values(array_unique($tags));
    sort($tags, SORT_NATURAL | SORT_FLAG_CASE);
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ai_dashboard.tag_mapping')
    );
  }

  /**
   * Display the priority kanban board.
   */
  public function kanbanView(Request $request) {
    try {
      // Read a single tag from query; default to 'priority'.
      $selected_tag = trim((string) $request->query->get('tag', 'priority'));
      if ($selected_tag === '') { $selected_tag = 'priority'; }
      $selected_tags = [$selected_tag];

      // Get kanban data with server-side tag filtering
      $kanban_data = $this->getKanbanData($selected_tags);

      $build = [
        '#cache' => [
          'tags' => [
            'node_list',
            'ai_dashboard:kanban',
            'node_list:ai_issue',
            'node_list:ai_contributor',
            'ai_dashboard:import',
          ],
          // Vary by the selected tag so cached pages are correct per filter.
          'contexts' => [
            'url.query_args:tag',
          ],
        ],
      ];

      // Add navigation
      $build['navigation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dashboard-navigation']],
        '#markup' => '<div class="nav-links">
          <a href="/ai-dashboard" class="nav-link">Dashboard</a>
          <a href="/ai-dashboard/calendar" class="nav-link">Calendar View</a>
          <a href="/ai-dashboard/calendar/organizational" class="nav-link">Organizational View</a>
          <a href="/ai-dashboard/priority-kanban" class="nav-link active">Kanban</a>
          <a href="/ai-dashboard/admin/contributors" class="nav-link">Contributors</a>
        </div>',
      ];

      // Check admin permissions
      $user_has_admin_permission = $this->currentUser()->hasPermission('administer ai dashboard content');

      // Build tag filter options and ensure selected tag is present as a fallback.
      $all_tags = $this->getAllIssueTags();
      if (!in_array($selected_tag, $all_tags, TRUE)) {
        array_unshift($all_tags, $selected_tag);
        $all_tags = array_values(array_unique($all_tags));
      }
      $filter_options = [
        'tags' => $all_tags,
      ];

      $build['kanban'] = [
        '#theme' => 'ai_priority_kanban',
        '#kanban_data' => $kanban_data,
        '#user_has_admin_permission' => $user_has_admin_permission,
        '#filter_options' => $filter_options,
        '#selected_tag' => $selected_tag,
        '#attached' => [
          'library' => [
            'ai_dashboard/calendar_dashboard',
            'ai_dashboard/priority-kanban',
          ],
        ],
      ];

      return $build;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Priority Kanban error: @message', ['@message' => $e->getMessage()]);
      
      return [
        '#markup' => '<div class="messages messages--error">Unable to load Priority Kanban Board. Please check the logs for more details.</div>',
      ];
    }
  }

  /**
   * Get kanban data organized by columns.
   */
  private function getKanbanData(array $selected_tags = []) {
    // Get issues across modules, filtered by selected tags if provided
    $issues = $this->getPriorityIssues(NULL, $selected_tags);
    
    $kanban_data = [
      'columns' => [
        'todos' => [
          'id' => 'todos',
          'title' => 'Todos',
          'description' => 'Issues wanting assignment',
          'collapsed' => FALSE,
          'main_column' => TRUE,
          'issues' => [],
        ],
        'needs_review' => [
          'id' => 'needs_review',
          'title' => 'Needs Review', 
          'description' => 'Issues needing reviewers',
          'collapsed' => FALSE,
          'main_column' => TRUE,
          'issues' => [],
        ],
        'past_checkin' => [
          'id' => 'past_checkin',
          'title' => 'Past Check-in Date',
          'description' => 'Issues with overdue check-ins',
          'collapsed' => FALSE,
          'main_column' => TRUE,
          'issues' => [],
        ],
        'working_on' => [
          'id' => 'working_on',
          'title' => 'Working On',
          'description' => 'Active assigned issues',
          'collapsed' => TRUE,
          'main_column' => FALSE,
          'issues' => [],
        ],
        'blocked' => [
          'id' => 'blocked',
          'title' => 'Blocked',
          'description' => 'Issues waiting on dependencies',
          'collapsed' => TRUE,
          'main_column' => FALSE,
          'issues' => [],
        ],
        'rtbc' => [
          'id' => 'rtbc',
          'title' => 'RTBC',
          'description' => 'Reviewed & tested by community',
          'collapsed' => TRUE,
          'main_column' => FALSE,
          'issues' => [],
        ],
        'fixed' => [
          'id' => 'fixed',
          'title' => 'Fixed',
          'description' => 'Completed issues',
          'collapsed' => TRUE,
          'main_column' => FALSE,
          'issues' => [],
        ],
      ],
      'summary' => [
        'total_issues' => count($issues),
        'selected_tags' => $selected_tags,
      ],
    ];

    // Group issues by kanban columns
    foreach ($issues as $issue) {
      $this->categorizeIssueToColumn($issue, $kanban_data['columns']);
    }

    return $kanban_data;
  }

  /**
   * Get priority issues from specified module.
   */
  private function getPriorityIssues($module_filter = NULL, array $selected_tags = []) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->accessCheck(TRUE);

    // Optional: Filter by module
    if ($module_filter) {
      $query->condition('field_issue_module.entity.title', $module_filter);
    }

    // Do not filter by tag at the query level; filter robustly after processing
    // to handle comma-separated values and case differences consistently.

    // For Phase 1, get all issues - priority filtering will be added in Phase 2
    $issue_ids = $query->execute();
    
    if (empty($issue_ids)) {
      return [];
    }

    $issues = $this->entityTypeManager->getStorage('node')->loadMultiple($issue_ids);
    $processed_issues = [];

    foreach ($issues as $issue) {
      $processed_issues[] = $this->processIssueForKanban($issue);
    }

    // Safety: also filter in PHP to ensure tag match regardless of storage quirks
    if (!empty($selected_tags)) {
      $needle = mb_strtolower(trim($selected_tags[0]));
      $processed_issues = array_values(array_filter($processed_issues, function($it) use ($needle) {
        if (empty($it['tags'])) return FALSE;
        // Build a single lowercase string of all tag values (including comma-separated entries)
        $all = [];
        foreach ($it['tags'] as $t) {
          $parts = preg_split('/\s*,\s*/', (string) $t);
          foreach ($parts as $p) {
            if ($p !== '') { $all[] = mb_strtolower(trim($p)); }
          }
        }
        $haystack = implode(',', $all);
        return $needle === '' ? TRUE : (mb_stripos($haystack, $needle) !== FALSE);
      }));
    }

    return $processed_issues;
  }

  /**
   * Process an issue for kanban display.
   */
  private function processIssueForKanban($issue) {
    // Get assignment records for current week
    $current_week_id = $this->getCurrentWeekId();
    $assignment_storage = $this->entityTypeManager->getStorage('assignment_record');
    
    $assignment_query = $assignment_storage->getQuery()
      ->condition('issue_id', $issue->id())
      ->condition('week_id', $current_week_id)
      ->accessCheck(TRUE);
    
    $assignment_ids = $assignment_query->execute();
    $assignee = NULL;
    
    if (!empty($assignment_ids)) {
      $assignment = $assignment_storage->load(reset($assignment_ids));
      if ($assignment && $assignment->get('assignee_id')->entity) {
        $assignee = $assignment->get('assignee_id')->entity;
      }
    }

    // Check if issue was changed in last 7 days
    $changed_date = $issue->getChangedTime();
    $seven_days_ago = strtotime('-7 days');
    $is_recently_changed = $changed_date >= $seven_days_ago;

    // Track and workstream (list_string on ai_issue)
    $track_value = '';
    if ($issue->hasField('field_track') && !$issue->get('field_track')->isEmpty()) {
      $track_value = $issue->get('field_track')->value;
    }
    elseif ($issue->hasField('field_tracks') && !$issue->get('field_tracks')->isEmpty()) {
      // Fallback if alternate field exists.
      $track_value = $issue->get('field_tracks')->value ?? '';
    }

    $workstream_value = '';
    if ($issue->hasField('field_workstream') && !$issue->get('field_workstream')->isEmpty()) {
      $workstream_value = $issue->get('field_workstream')->value;
    }

    // Tags and priority flag
    $tags = [];
    if ($issue->hasField('field_issue_tags') && !$issue->get('field_issue_tags')->isEmpty()) {
      foreach ($issue->get('field_issue_tags')->getValue() as $tag_item) {
        if (!empty($tag_item['value'])) {
          $tags[] = $tag_item['value'];
        }
      }
    }
    $has_priority_tag = FALSE;
    foreach ($tags as $t) {
      if (mb_strtolower(trim($t)) === 'priority') { $has_priority_tag = TRUE; break; }
    }
    // Include mapped tags that resolve to priority via TagMappingService.
    if (!$has_priority_tag && !empty($tags)) {
      try {
        $mapped = $this->tagMappingService->processTags($tags);
        if (!empty($mapped['priority']) && mb_strtolower(trim($mapped['priority'])) === 'priority') {
          $has_priority_tag = TRUE;
        }
      }
      catch (\Exception $e) {
        // Non-fatal: fall back to raw tag check.
      }
    }

    // Build assignee info and profile link if available.
    $assignee_name = $assignee ? $assignee->getTitle() : NULL;
    $assignee_username = NULL;
    $assignee_profile_url = NULL;
    if ($assignee && $assignee->hasField('field_drupal_username') && !$assignee->get('field_drupal_username')->isEmpty()) {
      $assignee_username = trim((string) $assignee->get('field_drupal_username')->value);
      if ($assignee_username !== '') {
        $assignee_profile_url = 'https://www.drupal.org/u/' . rawurlencode($assignee_username);
      }
    }

    $processed_issue = [
      'id' => $issue->id(),
      'nid' => $issue->id(),
      'title' => $issue->getTitle(),
      'issue_number' => $issue->get('field_issue_number')->value ?? '',
      'module' => $issue->get('field_issue_module')->entity ? $issue->get('field_issue_module')->entity->getTitle() : 'Unknown',
      'status' => $issue->get('field_issue_status')->value ?? 'active',
      'priority' => $issue->get('field_issue_priority')->value ?? 'normal',
      'url' => $issue->get('field_issue_url')->uri ?? '#',
      'assignee' => $assignee_name,
      'assignee_id' => $assignee ? $assignee->id() : NULL,
      'assignee_username' => $assignee_username,
      'assignee_profile_url' => $assignee_profile_url,
      'due_date' => $issue->get('field_due_date')->value,
      'checkin_date' => $issue->get('field_checkin_date')->value,
      'update_summary' => $issue->get('field_update_summary')->value ?? '',
      'update_summary_full' => $issue->get('field_update_summary')->value ?? '',
      'track' => $track_value,
      'track_label' => $track_value,
      'workstream' => $workstream_value,
      'workstream_label' => $workstream_value,
      'is_meta_issue' => FALSE, // TODO: implement meta issue detection
      'is_blocked' => FALSE, // This will be set below
      'blocked_issues' => [],
      'has_conflict' => FALSE,
      'is_recently_changed' => $is_recently_changed,
      'assignment_status' => 'current',
      'tags' => $tags,
      'has_priority' => $has_priority_tag,
    ];

    // Process blocked by field.
    if ($issue->hasField('field_issue_blocked_by') && !$issue->get('field_issue_blocked_by')->isEmpty()) {
      $processed_issue['is_blocked'] = TRUE;
      $blocked_by_values = $issue->get('field_issue_blocked_by')->getValue();
      $blocked_issues_data = [];
      foreach ($blocked_by_values as $blocked_value) {
        $text = $blocked_value['value'];
        // Extract issue numbers from text
        if (preg_match_all('/#?(\d+)/', $text, $matches)) {
          foreach ($matches[1] as $issue_number) {
            $blocked_issue_node = $this->entityTypeManager->getStorage('node')->loadByProperties([
              'type' => 'ai_issue',
              'field_issue_number' => $issue_number,
            ]);
            $blocked_issue_node = reset($blocked_issue_node);

            $blocked_issues_data[] = [
              'issue_number' => $issue_number,
              'title' => $blocked_issue_node ? $blocked_issue_node->getTitle() : 'Issue title not available',
              'assignee' => ($blocked_issue_node && $blocked_issue_node->hasField('field_issue_do_assignee') && !$blocked_issue_node->get('field_issue_do_assignee')->isEmpty()) ? $blocked_issue_node->get('field_issue_do_assignee')->value : 'Assignee not available',
            ];
          }
        }
      }
      $processed_issue['blocked_issues'] = $blocked_issues_data;
    }

    return $processed_issue;
  }

  /**
   * Determine current week id using AssignmentRecord helper.
   */
  private function getCurrentWeekId(): int {
    return \Drupal\ai_dashboard\Entity\AssignmentRecord::getCurrentWeekId();
  }

  /**
   * Place an issue into the appropriate Kanban column.
   *
   * @param array $issue
   *   Processed issue array.
   * @param array $columns
   *   Reference to columns array to mutate.
   */
  private function categorizeIssueToColumn(array $issue, array &$columns): void {
    $status = strtolower((string) ($issue['status'] ?? ''));
    $assignee = $issue['assignee'] ?? NULL;
    $has_checkin = !empty($issue['checkin_date']);

    // Normalize common variants.
    $status = str_replace([' ', '-'], '_', $status);

    // Completed states.
    if (in_array($status, ['fixed', 'closed_fixed', 'rtbc'], TRUE)) {
      $target = $status === 'rtbc' ? 'rtbc' : 'fixed';
      $columns[$target]['issues'][] = $issue;
      return;
    }

    // Needs review.
    if (in_array($status, ['needs_review', 'review'], TRUE)) {
      $columns['needs_review']['issues'][] = $issue;
      return;
    }

    // Blocked issues.
    if (!empty($issue['is_blocked'])) {
      $columns['blocked']['issues'][] = $issue;
      return;
    }

    // Past check-in date.
    if ($has_checkin) {
      $checkin = strtotime($issue['checkin_date']);
      if ($checkin && $checkin < strtotime('today')) {
        $columns['past_checkin']['issues'][] = $issue;
        return;
      }
    }

    // Active working items if assigned or status marked.
    if (!empty($assignee) || in_array($status, ['active', 'needs_work', 'working_on'], TRUE)) {
      $columns['working_on']['issues'][] = $issue;
      return;
    }

    // Default to Todos.
    $columns['todos']['issues'][] = $issue;
  }
}
