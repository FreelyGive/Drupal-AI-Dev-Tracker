<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Calendar-based AI Dashboard Controller.
 */
class CalendarController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a CalendarController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Display the calendar view.
   */
  public function calendarView(Request $request) {
    try {
      // Get current week or requested week.
      $week_offset = (int) $request->query->get('week', 0);
      $current_week = new \DateTime();
      if ($week_offset !== 0) {
        $current_week->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }

      // Set to Monday of the week.
      $current_week->modify('Monday this week');
      $week_start = clone $current_week;
      $week_end = (clone $current_week)->modify('+6 days');

      // Get consolidated data.
      $calendar_data = $this->getCalendarData($week_start, $week_end);

      $build = [
        '#cache' => [
          'tags' => [
            'ai_dashboard:calendar',
            'node_list:ai_issue',
            'node_list:ai_contributor',
            'ai_dashboard:import',
          ]
        ]
      ];

      // Add navigation.
      $build['navigation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['dashboard-navigation']],
        '#markup' => '<div class="nav-links">
          <a href="/ai-dashboard" class="nav-link">Dashboard</a>
          <a href="/ai-dashboard/calendar" class="nav-link active">Calendar View</a>
          <a href="/ai-dashboard/admin/contributors" class="nav-link">Contributors</a>
        </div>',
      ];

      // Get backlog data.
      $backlog_data = $this->getBacklogData();

      $build['calendar'] = [
        '#theme' => 'ai_calendar_dashboard',
        '#calendar_data' => $calendar_data,
        '#backlog_data' => $backlog_data,
        '#week_start' => $week_start,
        '#week_end' => $week_end,
        '#week_offset' => $week_offset,
        '#user_has_admin_permission' => \Drupal::currentUser()->id() == 1 || \Drupal::currentUser()->hasPermission('administer ai dashboard'),
        '#attached' => [
          'library' => [
            'ai_dashboard/calendar_dashboard',
          ],
          'drupalSettings' => [
            'aiDashboard' => [
              'weekOffset' => $week_offset,
              'weekStart' => $week_start->format('Y-m-d'),
              'weekEnd' => $week_end->format('Y-m-d'),
              'csrfToken' => \Drupal::csrfToken()->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
            ],
          ],
        ],
      ];

      return $build;
    }
    catch (\Exception $e) {
      // Log the error for debugging.
      \Drupal::logger('ai_dashboard')->error('Calendar view error: @message @trace', [
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);

      // Return a generic error message (don't expose system details)
      return [
        '#markup' => '<div style="padding: 20px; background: #fee; border: 1px solid #f00; color: #900;">
          <h2>Calendar Error</h2>
          <p>Unable to load calendar view. Please try again or contact an administrator if the problem persists.</p>
          <a href="/ai-dashboard">← Back to Dashboard</a>
        </div>',
      ];
    }
  }

  /**
   * Get calendar data organized by companies and developers.
   */
  private function getCalendarData(\DateTime $week_start, \DateTime $week_end) {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Load all companies.
    $companies = $node_storage->loadByProperties(['type' => 'ai_company']);

    // Load all contributors.
    $contributors = $node_storage->loadByProperties(['type' => 'ai_contributor']);

    // Load all issues.
    $issues = $node_storage->loadByProperties(['type' => 'ai_issue']);

    // Organize data by company.
    $calendar_data = [
      'companies' => [],
      'week_summary' => [
        'active' => 0,
        'needs_review' => 0,
        'needs_work' => 0,
        'fixed' => 0,
        'total_commitment' => 0,
        'total_capacity' => 0,
      ],
    ];

    // Process companies.
    foreach ($companies as $company) {
      $company_data = [
        'id' => $company->id(),
        'name' => $company->getTitle(),
        'color' => $company->hasField('field_company_color') ? $company->get('field_company_color')->value : '#0073bb',
        'logo_url' => NULL,
        'is_ai_maker' => $company->hasField('field_company_ai_maker') ? (bool) $company->get('field_company_ai_maker')->value : FALSE,
        'drupal_profile' => $company->hasField('field_company_drupal_profile') ? $company->get('field_company_drupal_profile')->value : '',
        'developers' => [],
        'total_issues' => 0,
      ];

      // Get company logo URL.
      if ($company->hasField('field_company_logo') && !$company->get('field_company_logo')->isEmpty()) {
        $logo_file = File::load($company->get('field_company_logo')->target_id);
        if ($logo_file) {
          $company_data['logo_url'] = \Drupal::service('file_url_generator')->generateAbsoluteString($logo_file->getFileUri());
        }
      }

      // Find contributors for this company.
      foreach ($contributors as $contributor) {
        if ($contributor->hasField('field_contributor_company') &&
            !$contributor->get('field_contributor_company')->isEmpty() &&
            $contributor->get('field_contributor_company')->target_id == $company->id()) {

          $developer_data = [
            'id' => $contributor->id(),
            'nid' => $contributor->id(),
            'name' => $contributor->getTitle(),
            'username' => $contributor->hasField('field_drupal_username') ? $contributor->get('field_drupal_username')->value : '',
            'role' => $contributor->hasField('field_contributor_role') ? $contributor->get('field_contributor_role')->value : '',
            'weekly_commitment' => $contributor->hasField('field_weekly_commitment') ? (float) $contributor->get('field_weekly_commitment')->value : 0,
            'avatar_url' => NULL,
            'issues' => [],
          ];

          // Get avatar URL.
          if ($contributor->hasField('field_contributor_avatar') && !$contributor->get('field_contributor_avatar')->isEmpty()) {
            $avatar_file = File::load($contributor->get('field_contributor_avatar')->target_id);
            if ($avatar_file) {
              $developer_data['avatar_url'] = \Drupal::service('file_url_generator')->generateAbsoluteString($avatar_file->getFileUri());
            }
          }

          // Find issues for this contributor in the current week.
          foreach ($issues as $issue) {
            if ($this->isIssueAssignedToContributor($issue, $contributor->id()) &&
                $this->isIssueInCurrentWeek($issue, $week_start, $week_end)) {

              $issue_data = [
                'id' => $issue->id(),
                'nid' => $issue->id(),
                'title' => $issue->getTitle(),
                'status' => $issue->hasField('field_issue_status') ? $issue->get('field_issue_status')->value : 'active',
                'priority' => $issue->hasField('field_issue_priority') ? $issue->get('field_issue_priority')->value : 'normal',
                'category' => $issue->hasField('field_issue_category') ? $issue->get('field_issue_category')->value : 'ai_integration',
                'deadline' => NULL,
                'url' => '#',
                'issue_number' => $issue->hasField('field_issue_number') ? $issue->get('field_issue_number')->value : '',
                'updated' => $issue->getChangedTime(),
                'do_assignee' => $issue->hasField('field_issue_do_assignee') ? $issue->get('field_issue_do_assignee')->value : '',
                'has_conflict' => FALSE,
              ];

              // Get deadline.
              if ($issue->hasField('field_issue_deadline') && !$issue->get('field_issue_deadline')->isEmpty()) {
                $issue_data['deadline'] = $issue->get('field_issue_deadline')->value;
              }

              // Get issue URL.
              if ($issue->hasField('field_issue_url') && !$issue->get('field_issue_url')->isEmpty()) {
                $issue_data['url'] = $issue->get('field_issue_url')->uri;
              }

              // Check for d.o assignment conflict.
              if (!empty($issue_data['do_assignee']) && !empty($developer_data['username'])) {
                $issue_data['has_conflict'] = (strtolower($issue_data['do_assignee']) !== strtolower($developer_data['username']));
              }

              $developer_data['issues'][] = $issue_data;

              // Update week summary.
              switch ($issue_data['status']) {
                case 'active':
                  $calendar_data['week_summary']['active']++;
                  break;

                case 'needs_review':
                  $calendar_data['week_summary']['needs_review']++;
                  break;

                case 'needs_work':
                  $calendar_data['week_summary']['needs_work']++;
                  break;

                case 'fixed':
                case 'rtbc':
                  $calendar_data['week_summary']['fixed']++;
                  break;
              }
            }
          }

          $company_data['developers'][] = $developer_data;
          $company_data['total_issues'] += count($developer_data['issues']);
          $calendar_data['week_summary']['total_commitment'] += $developer_data['weekly_commitment'];
        }
      }

      // Only add companies that have developers.
      if (!empty($company_data['developers'])) {
        $calendar_data['companies'][] = $company_data;
      }
    }

    // Sort companies and developers.
    $this->sortCalendarData($calendar_data);

    // Calculate total capacity (developers * 5 days)
    $total_developers = 0;
    foreach ($calendar_data['companies'] as $company) {
      $total_developers += count($company['developers']);
    }
    $calendar_data['week_summary']['total_capacity'] = $total_developers * 5;

    return $calendar_data;
  }

  /**
   * Sort calendar data by AI Maker status, company name, and developer name.
   */
  private function sortCalendarData(&$calendar_data) {
    // Sort companies: AI Makers first, then alphabetical by name.
    usort($calendar_data['companies'], function ($a, $b) {
      // First sort by AI Maker status (true first, false second)
      if ($a['is_ai_maker'] !== $b['is_ai_maker']) {
        // True (1) comes before false (0)
        return $b['is_ai_maker'] <=> $a['is_ai_maker'];
      }

      // Then sort alphabetically by company name.
      return strcasecmp($a['name'], $b['name']);
    });

    // Sort developers within each company alphabetically by name.
    foreach ($calendar_data['companies'] as &$company) {
      usort($company['developers'], function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
      });
    }
  }

  /**
   * Check if an issue is assigned to a specific contributor.
   */
  private function isIssueAssignedToContributor($issue, $contributor_id) {
    if (!$issue->hasField('field_issue_assignees') || $issue->get('field_issue_assignees')->isEmpty()) {
      return FALSE;
    }

    foreach ($issue->get('field_issue_assignees') as $assignee) {
      if ($assignee->target_id == $contributor_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Check if an issue is relevant to the current week.
   */
  private function isIssueInCurrentWeek($issue, \DateTime $week_start, \DateTime $week_end) {
    // If issue has assignment dates, check if any fall within this week.
    if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
      foreach ($issue->get('field_issue_assignment_date') as $date_item) {
        $assignment_date = new \DateTime($date_item->value);
        // Check if any assignment date falls within this week.
        if ($assignment_date >= $week_start && $assignment_date <= $week_end) {
          return TRUE;
        }
      }
      // If there are assignment dates but none match this week, return false.
      return FALSE;
    }

    // STRICT: Only show issues if they have explicit assignment dates for this week.
    // No fallback logic - issues must be specifically assigned to show in calendar.
    return FALSE;
  }

  /**
   * Get backlog data for unassigned issues.
   */
  private function getBacklogData() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Use direct database query to get all AI issues (entity query seems
    // to have caching issues).
    // @todo handle cacheability metadata.
    $all_issue_ids = \Drupal::database()
      ->select('node_field_data', 'nfd')
      ->fields('nfd', ['nid'])
      ->condition('nfd.type', 'ai_issue')
      ->condition('nfd.status', 1)
      ->execute()
      ->fetchCol();

    if (empty($all_issue_ids)) {
      return [
        'issues' => [],
        'modules' => [],
        'tags' => [],
      ];
    }

    // Get issues that have assignees (to exclude them).
    $assigned_issue_ids = \Drupal::database()
      ->select('node__field_issue_assignees', 'fia')
      ->fields('fia', ['entity_id'])
      ->condition('fia.bundle', 'ai_issue')
      ->condition('fia.entity_id', $all_issue_ids, 'IN')
      ->execute()
      ->fetchCol();

    // Get unassigned issue IDs by excluding assigned ones.
    $unassigned_issue_ids = array_diff($all_issue_ids, $assigned_issue_ids);

    if (empty($unassigned_issue_ids)) {
      return [
        'issues' => [],
        'modules' => [],
        'tags' => [],
      ];
    }

    $issues = $node_storage->loadMultiple($unassigned_issue_ids);

    $backlog_issues = [];
    $all_modules = [];
    $all_tags = [];

    foreach ($issues as $issue) {
      // Get module info.
      $module_name = 'N/A';
      $module_id = NULL;
      if ($issue->hasField('field_issue_module') && !$issue->get('field_issue_module')->isEmpty()) {
        $module = $issue->get('field_issue_module')->entity;
        if ($module) {
          $module_name = $module->getTitle();
          $module_id = $module->id();
          $all_modules[$module_id] = $module_name;
        }
      }

      // Get tags.
      $tags = [];
      if ($issue->hasField('field_issue_tags') && !$issue->get('field_issue_tags')->isEmpty()) {
        foreach ($issue->get('field_issue_tags') as $tag_field) {
          if (!empty($tag_field->value)) {
            $tags[] = $tag_field->value;
            $all_tags[$tag_field->value] = $tag_field->value;
          }
        }
      }

      // Get priority and status.
      $priority = $issue->hasField('field_issue_priority') && !$issue->get('field_issue_priority')->isEmpty() ?
                 $issue->get('field_issue_priority')->value : 'normal';
      $status = $issue->hasField('field_issue_status') && !$issue->get('field_issue_status')->isEmpty() ?
               $issue->get('field_issue_status')->value : 'active';
      $category = $issue->hasField('field_issue_category') && !$issue->get('field_issue_category')->isEmpty() ?
                 $issue->get('field_issue_category')->value : 'general';
      $issue_number = $issue->hasField('field_issue_number') && !$issue->get('field_issue_number')->isEmpty() ?
                     $issue->get('field_issue_number')->value : 'N/A';
      $issue_url = $issue->hasField('field_issue_url') && !$issue->get('field_issue_url')->isEmpty() ?
                  $issue->get('field_issue_url')->uri : '#';

      $backlog_issues[] = [
        'id' => $issue->id(),
        'title' => $issue->getTitle(),
        'number' => $issue_number,
        'status' => $status,
        'priority' => $priority,
        'category' => $category,
        'module' => $module_name,
        'module_id' => $module_id,
        'tags' => $tags,
        'url' => $issue_url,
        'created' => $issue->getCreatedTime(),
        'changed' => $issue->getChangedTime(),
        'do_assignee' => $issue->hasField('field_issue_do_assignee') ? $issue->get('field_issue_do_assignee')->value : '',
      ];
    }

    // Sort issues by priority and creation date.
    usort($backlog_issues, function ($a, $b) {
      $priority_order = ['critical' => 0, 'major' => 1, 'normal' => 2, 'minor' => 3, 'trivial' => 4];
      $a_priority = $priority_order[$a['priority']] ?? 2;
      $b_priority = $priority_order[$b['priority']] ?? 2;

      if ($a_priority !== $b_priority) {
        return $a_priority - $b_priority;
      }

      // Newer first.
      return $b['created'] - $a['created'];
    });

    return [
      'issues' => $backlog_issues,
      'modules' => $all_modules,
      'tags' => array_keys($all_tags),
    ];
  }

  /**
   * API endpoint to assign an issue to a developer.
   */
  public function assignIssue(Request $request) {
    try {
      $issue_id = $request->request->get('issue_id');
      $developer_id = $request->request->get('developer_id');
      $week_offset = (int) $request->request->get('week_offset', 0);

      if (!$issue_id || !$developer_id) {
        return new JsonResponse(['success' => FALSE, 'message' => 'Missing required parameters']);
      }

      $node_storage = $this->entityTypeManager->getStorage('node');

      // Load the issue.
      $issue = $node_storage->load($issue_id);
      if (!$issue || $issue->bundle() !== 'ai_issue') {
        return new JsonResponse(['success' => FALSE, 'message' => 'Issue not found']);
      }

      // Load the developer.
      $developer = $node_storage->load($developer_id);
      if (!$developer || $developer->bundle() !== 'ai_contributor') {
        return new JsonResponse(['success' => FALSE, 'message' => 'Developer not found']);
      }

      // Assign the issue to the developer.
      $issue->set('field_issue_assignees', [['target_id' => $developer_id]]);

      // Set assignment date based on week offset.
      $assignment_date = new \DateTime();
      if ($week_offset !== 0) {
        $assignment_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }
      $assignment_date->modify('Monday this week');
      $assignment_date_string = $assignment_date->format('Y-m-d');

      if ($issue->hasField('field_issue_assignment_date')) {
        // Get existing assignment dates.
        $existing_dates = [];
        if (!$issue->get('field_issue_assignment_date')->isEmpty()) {
          foreach ($issue->get('field_issue_assignment_date') as $date_item) {
            $existing_dates[] = $date_item->value;
          }
        }

        // Add new date if not already present.
        if (!in_array($assignment_date_string, $existing_dates)) {
          $existing_dates[] = $assignment_date_string;
          $date_values = array_map(function ($date) {
            return ['value' => $date];
          }, $existing_dates);
          $issue->set('field_issue_assignment_date', $date_values);
        }
      }

      $issue->save();

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      // Create response with no-cache headers for CloudFlare.
      $response = new JsonResponse([
        'success' => TRUE,
        'message' => 'Issue assigned successfully',
        'issue_id' => $issue_id,
        'developer_id' => $developer_id,
      ]);

      // Add headers to prevent caching.
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');

      return $response;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Assignment error: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Assignment failed']);
    }
  }

  /**
   * API endpoint to copy assignments from one week to another.
   */
  public function copyWeekAssignments(Request $request) {
    try {
      $from_week = (int) $request->request->get('from_week', 0);
      $to_week = (int) $request->request->get('to_week', 0);

      // Calculate date ranges.
      $from_date = new \DateTime();
      if ($from_week !== 0) {
        $from_date->modify($from_week > 0 ? "+{$from_week} weeks" : $from_week . " weeks");
      }
      $from_date->modify('Monday this week');
      $from_start = clone $from_date;
      $from_end = (clone $from_date)->modify('+6 days');

      $to_date = new \DateTime();
      if ($to_week !== 0) {
        $to_date->modify($to_week > 0 ? "+{$to_week} weeks" : $to_week . " weeks");
      }
      $to_date->modify('Monday this week');

      $node_storage = $this->entityTypeManager->getStorage('node');

      // Get all issues from the source week.
      $issues = $node_storage->loadByProperties(['type' => 'ai_issue']);
      $copied_count = 0;

      foreach ($issues as $issue) {
        // Check if issue was assigned in the source week.
        if ($this->isIssueInCurrentWeek($issue, $from_start, $from_end) &&
            $this->hasAssignees($issue)) {

          $to_date_string = $to_date->format('Y-m-d');

          // Check if this issue is already assigned to the target week.
          $already_assigned_to_target_week = FALSE;
          if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
            foreach ($issue->get('field_issue_assignment_date') as $date_item) {
              if ($date_item->value === $to_date_string) {
                $already_assigned_to_target_week = TRUE;
                break;
              }
            }
          }

          // Only add the target week if not already assigned.
          if (!$already_assigned_to_target_week) {
            // Get existing assignment dates.
            $existing_dates = [];
            if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
              foreach ($issue->get('field_issue_assignment_date') as $date_item) {
                $existing_dates[] = $date_item->value;
              }
            }

            // Add the new week to existing assignments.
            $existing_dates[] = $to_date_string;
            $date_values = array_map(function ($date) {
              return ['value' => $date];
            }, $existing_dates);

            $issue->set('field_issue_assignment_date', $date_values);
            $issue->save();
            $copied_count++;
          }
        }
      }

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      // Create response with no-cache headers for CloudFlare.
      $response = new JsonResponse([
        'success' => TRUE,
        'message' => "Added {$copied_count} issues from week {$from_week} to week {$to_week}",
        'copied_count' => $copied_count,
      ]);

      // Add headers to prevent caching.
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');

      return $response;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Copy week error: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Copy operation failed']);
    }
  }

  /**
   * Check if an issue has assignees.
   */
  private function hasAssignees($issue) {
    return $issue->hasField('field_issue_assignees') && !$issue->get('field_issue_assignees')->isEmpty();
  }

  /**
   * API endpoint to unassign an issue (move back to backlog).
   */
  public function unassignIssue(Request $request) {
    try {
      $issue_id = $request->request->get('issue_id');
      $week_offset = (int) $request->request->get('week_offset', 0);

      if (!$issue_id) {
        return new JsonResponse(['success' => FALSE, 'message' => 'Missing issue ID']);
      }

      $node_storage = $this->entityTypeManager->getStorage('node');

      // Load the issue.
      $issue = $node_storage->load($issue_id);
      if (!$issue || $issue->bundle() !== 'ai_issue') {
        return new JsonResponse(['success' => FALSE, 'message' => 'Issue not found']);
      }

      // Calculate the current week's Monday date.
      $current_week_date = new \DateTime();
      if ($week_offset !== 0) {
        $current_week_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }
      $current_week_date->modify('Monday this week');
      $current_week_string = $current_week_date->format('Y-m-d');

      // Remove only the current week's assignment date.
      if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
        $remaining_dates = [];
        foreach ($issue->get('field_issue_assignment_date') as $date_item) {
          if ($date_item->value !== $current_week_string) {
            $remaining_dates[] = ['value' => $date_item->value];
          }
        }
        $issue->set('field_issue_assignment_date', $remaining_dates);

        // Only clear assignees if no more assignment dates remain.
        if (empty($remaining_dates)) {
          $issue->set('field_issue_assignees', []);
        }
      }
      else {
        // Fallback: clear assignees if no assignment dates exist.
        $issue->set('field_issue_assignees', []);
      }

      $issue->save();

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      // Create response with no-cache headers for CloudFlare.
      $response = new JsonResponse([
        'success' => TRUE,
        'message' => 'Issue unassigned successfully',
        'issue_id' => $issue_id,
      ]);

      // Add headers to prevent caching.
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');

      return $response;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Unassignment error: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Unassignment failed']);
    }
  }

  /**
   * Sync drupal.org assigned issues to current week for a developer.
   */
  public function syncDrupalAssignments(Request $request) {
    try {
      $developer_id = $request->request->get('developer_id');
      $username = $request->request->get('username');
      $week_offset = (int) $request->request->get('week_offset', 0);

      if (!$developer_id || !$username) {
        return new JsonResponse(['success' => FALSE, 'message' => 'Missing required parameters']);
      }

      $node_storage = $this->entityTypeManager->getStorage('node');

      // Load the developer.
      $developer = $node_storage->load($developer_id);
      if (!$developer || $developer->bundle() !== 'ai_contributor') {
        return new JsonResponse(['success' => FALSE, 'message' => 'Developer not found']);
      }

      // Calculate the current week date.
      $assignment_date = new \DateTime();
      if ($week_offset !== 0) {
        $assignment_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }
      $assignment_date->modify('Monday this week');
      $assignment_date_string = $assignment_date->format('Y-m-d');

      // Find all AI issues that have the drupal.org assignee matching this
      // developer's username.
      // but are not yet assigned to this developer in our system for this week.
      $query = $node_storage->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('field_issue_do_assignee', $username)
        ->accessCheck(FALSE);

      $issue_ids = $query->execute();
      $synced_count = 0;

      if (!empty($issue_ids)) {
        $issues = $node_storage->loadMultiple($issue_ids);

        foreach ($issues as $issue) {
          // Check if issue is already assigned to this developer for this week.
          $already_assigned = FALSE;

          // Check current assignees.
          if ($issue->hasField('field_issue_assignees') && !$issue->get('field_issue_assignees')->isEmpty()) {
            foreach ($issue->get('field_issue_assignees') as $assignee) {
              if ($assignee->target_id == $developer_id) {
                // Check if already assigned for this week.
                if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
                  foreach ($issue->get('field_issue_assignment_date') as $date_item) {
                    if ($date_item->value === $assignment_date_string) {
                      $already_assigned = TRUE;
                      break 2;
                    }
                  }
                }
                break;
              }
            }
          }

          if (!$already_assigned) {
            // Assign the issue to this developer.
            $issue->set('field_issue_assignees', [['target_id' => $developer_id]]);

            // Add assignment date.
            $existing_dates = [];
            if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
              foreach ($issue->get('field_issue_assignment_date') as $date_item) {
                $existing_dates[] = $date_item->value;
              }
            }

            if (!in_array($assignment_date_string, $existing_dates)) {
              $existing_dates[] = $assignment_date_string;
              $date_values = array_map(function ($date) {
                return ['value' => $date];
              }, $existing_dates);
              $issue->set('field_issue_assignment_date', $date_values);
            }

            $issue->save();
            $synced_count++;
          }
        }
      }

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      // Create response with no-cache headers for CloudFlare.
      $response = new JsonResponse([
        'success' => TRUE,
        'message' => "Synced {$synced_count} issue" . ($synced_count != 1 ? 's' : '') . " from drupal.org for {$username}",
        'synced_count' => $synced_count,
        'developer_id' => $developer_id,
        'username' => $username,
      ]);

      // Add headers to prevent caching.
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');

      return $response;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Sync drupal assignments error: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Sync failed']);
    }
  }

  /**
   * Sync all assigned issues from drupal.org.
   */
  public function syncAllDrupalAssignments(Request $request) {
    try {
      $week_offset = (int) $request->request->get('week_offset', 0);

      $node_storage = $this->entityTypeManager->getStorage('node');

      // Calculate the current week date.
      $assignment_date = new \DateTime();
      if ($week_offset !== 0) {
        $assignment_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }
      $assignment_date->modify('Monday this week');
      $assignment_date_string = $assignment_date->format('Y-m-d');

      // Get all contributors with drupal.org usernames.
      $contributor_query = $node_storage->getQuery()
        ->condition('type', 'ai_contributor')
        ->condition('field_drupal_username', '', '!=')
        ->accessCheck(FALSE);

      $contributor_ids = $contributor_query->execute();

      if (empty($contributor_ids)) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'No contributors found with drupal.org usernames',
        ]);
      }

      $contributors = $node_storage->loadMultiple($contributor_ids);
      $total_synced = 0;
      $developers_synced = 0;

      // For each contributor, find their assigned issues on drupal.org.
      foreach ($contributors as $contributor) {
        $username = $contributor->get('field_drupal_username')->value;
        if (empty($username)) {
          continue;
        }

        // Find all AI issues assigned to this contributor on drupal.org.
        $issue_query = $node_storage->getQuery()
          ->condition('type', 'ai_issue')
          ->condition('field_issue_do_assignee', $username)
        // Only open issues.
          ->condition('field_issue_status', ['active', 'needs_review', 'needs_work', 'rtbc'], 'IN')
          ->accessCheck(FALSE);

        $issue_ids = $issue_query->execute();

        if (empty($issue_ids)) {
          continue;
        }

        $issues = $node_storage->loadMultiple($issue_ids);
        $contributor_synced = 0;

        foreach ($issues as $issue) {
          // Check if the issue is already assigned to this contributor
          // for this week.
          $already_assigned = FALSE;

          if ($issue->hasField('field_issue_assignees') && !$issue->get('field_issue_assignees')->isEmpty()) {
            foreach ($issue->get('field_issue_assignees') as $assignee) {
              if ($assignee->target_id == $contributor->id()) {
                // Check if already assigned for this week.
                if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
                  foreach ($issue->get('field_issue_assignment_date') as $date_item) {
                    if ($date_item->value === $assignment_date_string) {
                      $already_assigned = TRUE;
                      break 2;
                    }
                  }
                }
                break;
              }
            }
          }

          if (!$already_assigned) {
            // Assign the issue to this contributor.
            $issue->set('field_issue_assignees', [['target_id' => $contributor->id()]]);

            // Add assignment date.
            $existing_dates = [];
            if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
              foreach ($issue->get('field_issue_assignment_date') as $date_item) {
                $existing_dates[] = $date_item->value;
              }
            }

            if (!in_array($assignment_date_string, $existing_dates)) {
              $existing_dates[] = $assignment_date_string;
              $date_values = array_map(function ($date) {
                return ['value' => $date];
              }, $existing_dates);
              $issue->set('field_issue_assignment_date', $date_values);
            }

            $issue->save();
            $contributor_synced++;
            $total_synced++;
          }
        }

        if ($contributor_synced > 0) {
          $developers_synced++;
        }
      }

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      // Create response with no-cache headers.
      $response = new JsonResponse([
        'success' => TRUE,
        'message' => "Synced {$total_synced} issue" . ($total_synced != 1 ? 's' : '') . " from drupal.org for {$developers_synced} developer" . ($developers_synced != 1 ? 's' : ''),
        'total_synced' => $total_synced,
        'developers_synced' => $developers_synced,
      ]);

      // Add headers to prevent caching.
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');

      return $response;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Sync all drupal assignments error: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Sync all failed']);
    }
  }

  /**
   * Remove all issues from the current week (unassign all).
   */
  public function removeAllWeekIssues(Request $request) {
    try {
      $week_offset = (int) $request->request->get('week_offset', 0);

      // Calculate the target week date.
      $target_date = new \DateTime();
      if ($week_offset !== 0) {
        $target_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }
      $target_date->modify('Monday this week');
      $target_date_string = $target_date->format('Y-m-d');

      $node_storage = $this->entityTypeManager->getStorage('node');

      // Find all AI issues that have assignments for this specific week.
      $query = $node_storage->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('field_issue_assignment_date', $target_date_string)
        ->accessCheck(FALSE);

      $issue_ids = $query->execute();
      $removed_count = 0;

      if (!empty($issue_ids)) {
        $issues = $node_storage->loadMultiple($issue_ids);

        foreach ($issues as $issue) {
          $updated = FALSE;

          // Remove the specific assignment date.
          if ($issue->hasField('field_issue_assignment_date') && !$issue->get('field_issue_assignment_date')->isEmpty()) {
            $remaining_dates = [];
            foreach ($issue->get('field_issue_assignment_date') as $date_item) {
              if ($date_item->value !== $target_date_string) {
                $remaining_dates[] = ['value' => $date_item->value];
              }
            }

            $issue->set('field_issue_assignment_date', $remaining_dates);
            $updated = TRUE;
          }

          // If no assignment dates remain, also clear assignees.
          if (empty($remaining_dates)) {
            $issue->set('field_issue_assignees', []);
            $updated = TRUE;
          }

          if ($updated) {
            $issue->save();
            $removed_count++;
          }
        }
      }

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      // Create response with no-cache headers.
      $response = new JsonResponse([
        'success' => TRUE,
        'message' => "Removed {$removed_count} issue" . ($removed_count != 1 ? 's' : '') . " from this week",
        'removed_count' => $removed_count,
      ]);

      // Add headers to prevent caching.
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');

      return $response;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Remove all week issues error: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Remove all failed']);
    }
  }

  /**
   * Invalidate caches related to calendar and dashboard.
   */
  private function invalidateCalendarCaches() {
    // Invalidate page cache.
    \Drupal::service('page_cache_kill_switch')->trigger();

    // Invalidate dynamic page cache.
    \Drupal::service('cache.render')->invalidateAll();
    \Drupal::service('cache.dynamic_page_cache')->invalidateAll();

    // Invalidate specific cache tags.
    $cache_tags = [
      'ai_dashboard:calendar',
      'node_list:ai_issue',
      'node_list:ai_contributor',
    ];
    \Drupal::service('cache_tags.invalidator')->invalidateTags($cache_tags);

    // Clear all caches if needed (aggressive approach)
    \Drupal::service('cache.bootstrap')->deleteAll();
    \Drupal::service('cache.config')->deleteAll();
  }

}
