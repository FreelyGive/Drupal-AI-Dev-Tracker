<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_dashboard\Service\TagMappingService;
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
   * The tag mapping service.
   *
   * @var \Drupal\ai_dashboard\Service\TagMappingService
   */
  protected $tagMappingService;

  /**
   * Constructs a CalendarController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ai_dashboard\Service\TagMappingService $tag_mapping_service
   *   The tag mapping service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, TagMappingService $tag_mapping_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->tagMappingService = $tag_mapping_service;
  }


  /**
   * Display the Organizational calendar view.
   */
  public function calendarViewNonDev(Request $request) {
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

      // Get consolidated data for organizational audience only.
      $calendar_data = $this->getCalendarData($week_start, $week_end, TRUE);

      $build = [
        '#cache' => [
          'tags' => [
            // Use core-provided node_list tag so edits invalidate this page.
            'node_list',
            'ai_dashboard:calendar',
            'node_list:ai_issue',
            'node_list:ai_contributor',
            'ai_dashboard:import',
          ]
        ]
      ];

      // Navigation is handled in the template

      // Get backlog data filtered to organizational issues.
      $backlog_data = $this->getBacklogData(TRUE);
      
      // Get dynamic filter options
      $filter_options = $this->getFilterOptions();

      $build['calendar'] = [
        '#theme' => 'ai_calendar_dashboard',
        '#calendar_data' => $calendar_data,
        '#backlog_data' => $backlog_data,
        '#week_start' => $week_start,
        '#week_end' => $week_end,
        '#week_offset' => $week_offset,
        '#filter_options' => $filter_options,
        '#user_has_admin_permission' => \Drupal::currentUser()->id() == 1 || \Drupal::currentUser()->hasPermission('administer ai dashboard'),
        '#is_organizational_view' => TRUE,
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
      // Log the error.
      \Drupal::logger('ai_dashboard')->error('Calendar view (organizational) error: @message @trace', [
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);

      return [
        '#markup' => '<div style="padding: 20px; background: #fee; border: 1px solid #f00; color: #900;">
          <h2>Calendar Error</h2>
          <p>Unable to load organizational calendar view. Please try again or contact an administrator if the problem persists.</p>
          <a href="/ai-dashboard">← Back to Dashboard</a>
        </div>',
      ];
    }
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
      $calendar_data = $this->getCalendarData($week_start, $week_end, FALSE);

      $build = [
        '#cache' => [
          'tags' => [
            // Use core-provided node_list tag so edits invalidate this page.
            'node_list',
            'ai_dashboard:calendar',
            'node_list:ai_issue',
            'node_list:ai_contributor',
            'ai_dashboard:import',
          ]
        ]
      ];

      // Navigation is handled in the template

      // Get backlog data.
      $backlog_data = $this->getBacklogData(FALSE);
      
      // Get dynamic filter options
      $filter_options = $this->getFilterOptions();

      $build['calendar'] = [
        '#theme' => 'ai_calendar_dashboard',
        '#calendar_data' => $calendar_data,
        '#backlog_data' => $backlog_data,
        '#week_start' => $week_start,
        '#week_end' => $week_end,
        '#week_offset' => $week_offset,
        '#filter_options' => $filter_options,
        '#user_has_admin_permission' => \Drupal::currentUser()->id() == 1 || \Drupal::currentUser()->hasPermission('administer ai dashboard'),
        '#is_organizational_view' => FALSE,
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
      // Log the error.
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
  private function getCalendarData(\DateTime $week_start, \DateTime $week_end, bool $non_developer_only = FALSE) {
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
        'name' => $this->sanitizeText($company->getTitle()),
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

          // If filtering to non-developers, include only contributors whose
          // contributor type equals 'non_dev'. This matches the configured
          // In non-developer-only view, include contributors with any
          // 'non_dev' value in their (possibly multi-valued) type field.
          if ($non_developer_only) {
            if (!$contributor->hasField('field_contributor_type') || $contributor->get('field_contributor_type')->isEmpty()) {
              continue;
            }
            $is_non_dev_contributor = FALSE;
            foreach ($contributor->get('field_contributor_type') as $type_item) {
              if ($type_item->value === 'non_dev') {
                $is_non_dev_contributor = TRUE;
                break;
              }
            }
            if (!$is_non_dev_contributor) {
              continue;
            }
          }
          else {
            // Developer view: include only contributors that have 'dev'.
            if ($contributor->hasField('field_contributor_type') && !$contributor->get('field_contributor_type')->isEmpty()) {
              $is_dev_contributor = FALSE;
              foreach ($contributor->get('field_contributor_type') as $type_item) {
                if ($type_item->value === 'dev') {
                  $is_dev_contributor = TRUE;
                  break;
                }
              }
              if (!$is_dev_contributor) {
                continue;
              }
            }
          }

          $developer_data = [
            'id' => $contributor->id(),
            'nid' => $contributor->id(),
            'name' => $this->sanitizeText($contributor->getTitle()),
            'username' => $contributor->hasField('field_drupal_username') ? $contributor->get('field_drupal_username')->value : '',
            'role' => $contributor->hasField('field_contributor_role') ? $contributor->get('field_contributor_role')->value : '',
            'weekly_commitment' => $contributor->hasField('field_weekly_commitment') ? (float) $contributor->get('field_weekly_commitment')->value : 0,
            'current_focus' => $contributor->hasField('field_current_focus') && !$contributor->get('field_current_focus')->isEmpty() ? 
                               $this->sanitizeText($contributor->get('field_current_focus')->value) : '',
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

          // Find assignments for this contributor in the current week using AssignmentRecord.
          $week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($week_start);
          $assignments = \Drupal\ai_dashboard\Entity\AssignmentRecord::loadByProperties([
            'assignee_id' => $contributor->id(),
            'week_id' => $week_id,
          ]);

          foreach ($assignments as $assignment) {
            $issue_id = $assignment->get('issue_id')->target_id;
            if (empty($issue_id)) {
              // Skip assignments with NULL issue_id
              continue;
            }
            
            $issue = \Drupal\node\Entity\Node::load($issue_id);
            if (!$issue || $issue->bundle() !== 'ai_issue') {
              continue;
            }

            // Determine assignment status (current vs historical)
            $assignment_status = $this->isCurrentlyAssigned($issue, $contributor->id()) ? 'current' : 'historical';

            // For non-developer view, include only issues marked as non_dev.
            // For developer view, exclude any issue marked as non_dev.
            if ($issue->hasField('field_issue_dashboard_category') && !$issue->get('field_issue_dashboard_category')->isEmpty()) {
              $has_non_dev = FALSE;
              foreach ($issue->get('field_issue_dashboard_category') as $item) {
                if ($item->value === 'non_dev') {
                  $has_non_dev = TRUE;
                  break;
                }
              }
              if ($non_developer_only && !$has_non_dev) {
                continue;
              }
              if (!$non_developer_only && $has_non_dev) {
                continue;
              }
            }
            elseif ($non_developer_only) {
              // No category set: exclude from non-developer view.
              continue;
            }

            // Get module info.
            $module_name = 'N/A';
            if ($issue->hasField('field_issue_module') && !$issue->get('field_issue_module')->isEmpty()) {
              $module = $issue->get('field_issue_module')->entity;
              if ($module) {
                $module_name = $this->sanitizeText($module->getTitle());
              }
            }

            $issue_data = [
              'id' => $issue->id(),
              'nid' => $issue->id(),
              'title' => $this->sanitizeText($issue->getTitle()),
              'status' => $issue->hasField('field_issue_status') ? $issue->get('field_issue_status')->value : 'active',
              'priority' => $issue->hasField('field_issue_priority') ? $issue->get('field_issue_priority')->value : 'normal',
              'category' => $issue->hasField('field_issue_category') ? $issue->get('field_issue_category')->value : 'ai_integration',
              'module' => $module_name,
              'track' => $issue->hasField('field_track') ? $issue->get('field_track')->value : '',
              'track_label' => '',
              'workstream' => $issue->hasField('field_workstream') ? $issue->get('field_workstream')->value : '',
              'workstream_label' => '',
              'deadline' => NULL,
              'url' => '#',
              'issue_number' => $issue->hasField('field_issue_number') ? $issue->get('field_issue_number')->value : '',
              'updated' => $issue->getChangedTime(),
              'do_assignee' => $issue->hasField('field_issue_do_assignee') ? $issue->get('field_issue_do_assignee')->value : '',
              'assignment_status' => $assignment_status, // 'current', 'historical', or 'none'
              'has_conflict' => FALSE,
              // New planning fields - truncate update summary for display
              'update_summary' => $issue->hasField('field_update_summary') && !$issue->get('field_update_summary')->isEmpty() ? 
                                  $this->sanitizeText($issue->get('field_update_summary')->value) : '',
              'update_summary_full' => $issue->hasField('field_update_summary') && !$issue->get('field_update_summary')->isEmpty() ? 
                                       $this->sanitizeText($issue->get('field_update_summary')->value) : '',
              'checkin_date' => NULL,
              'is_meta_issue' => $issue->hasField('field_is_meta_issue') ? (bool) $issue->get('field_is_meta_issue')->value : FALSE,
              'blocked_by' => [],
              'blocked_by_raw' => '',
            ];

            // Set track label (use stored value as both filter value and label)
            if (!empty($issue_data['track'])) {
              $issue_data['track_label'] = $issue_data['track'];
            }

            // Set workstream label (use stored value as both filter value and label)
            if (!empty($issue_data['workstream'])) {
              $issue_data['workstream_label'] = $issue_data['workstream'];
            }

            // Get deadline.
            if ($issue->hasField('field_issue_deadline') && !$issue->get('field_issue_deadline')->isEmpty()) {
              $issue_data['deadline'] = $issue->get('field_issue_deadline')->value;
            }

            // Get planning dates.
            if ($issue->hasField('field_checkin_date') && !$issue->get('field_checkin_date')->isEmpty()) {
              $issue_data['checkin_date'] = $issue->get('field_checkin_date')->value;
            }
            if ($issue->hasField('field_due_date') && !$issue->get('field_due_date')->isEmpty()) {
              $issue_data['due_date'] = $issue->get('field_due_date')->value;
            }

            // Get issue URL.
            if ($issue->hasField('field_issue_url') && !$issue->get('field_issue_url')->isEmpty()) {
              $issue_data['url'] = $issue->get('field_issue_url')->uri;
            }

            // Process blocked by field (simple check for now).
            if ($issue->hasField('field_issue_blocked_by') && !$issue->get('field_issue_blocked_by')->isEmpty()) {
              $blocked_by_values = $issue->get('field_issue_blocked_by')->getValue();
              $issue_data['blocked_by_raw'] = implode(', ', array_column($blocked_by_values, 'value'));
              $issue_data['is_blocked'] = TRUE;
              
              // Parse blocked by entries for tooltip display
              $blocked_issues = [];
              foreach ($blocked_by_values as $blocked_value) {
                $text = $blocked_value['value'];
                // Extract issue numbers from text (simple extraction for manual entry)
                if (preg_match_all('/#?(\d+)/', $text, $matches)) {
                  foreach ($matches[1] as $issue_number) {
                    $blocked_issues[] = [
                      'issue_number' => $issue_number,
                      'title' => 'Issue title not available', // Placeholder for now
                      'assignee' => 'Assignee not available', // Placeholder for now
                    ];
                  }
                }
              }
              $issue_data['blocked_issues'] = $blocked_issues;
            } else {
              $issue_data['is_blocked'] = FALSE;
              $issue_data['blocked_issues'] = [];
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
   * Clean display text by removing HTML tags and literal newline artifacts.
   */
  private function sanitizeText($text) {
    if (!is_string($text)) {
      return $text;
    }
    // Strip HTML tags first.
    $text = strip_tags($text);
    // Replace literal sequences and actual line breaks with a single space.
    $text = str_replace(["\\n", "\\r", "/n", "\r\n", "\n", "\r"], ' ', $text);
    // Collapse excessive whitespace.
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
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
    // Calculate week ID for the target week.
    $week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($week_start);
    
    // Check if there are any assignments for this issue in the target week.
    $assignments = \Drupal\ai_dashboard\Entity\AssignmentRecord::loadByProperties([
      'issue_id' => $issue->id(),
      'week_id' => $week_id,
    ]);

    return !empty($assignments);
  }

  /**
   * Get dynamic filter options for tracks, workstreams, tags, priorities, and statuses.
   *
   * @return array
   *   An array containing unique values for filtering.
   */
  private function getFilterOptions() {
    $filter_options = [
      'tracks' => [],
      'workstreams' => [],
      'tags' => [],
      'priorities' => [
        ['value' => 'critical', 'label' => 'Critical'],
        ['value' => 'major', 'label' => 'Major'],
        ['value' => 'normal', 'label' => 'Normal'],
        ['value' => 'minor', 'label' => 'Minor'],
      ],
      'statuses' => [
        ['value' => 'active', 'label' => 'Active'],
        ['value' => 'needs_review', 'label' => 'Needs Review'],
        ['value' => 'needs_work', 'label' => 'Needs Work'],
        ['value' => 'rtbc', 'label' => 'RTBC'],
        ['value' => 'fixed', 'label' => 'Fixed'],
        ['value' => 'postponed', 'label' => 'Postponed'],
        ['value' => 'closed', 'label' => 'Closed'],
      ],
    ];

    // Get track field definition for allowed values
    $track_field_storage = $this->entityTypeManager->getStorage('field_storage_config')->load('node.field_track');
    $track_allowed_values = [];
    if ($track_field_storage) {
      $track_allowed_values = $track_field_storage->getSetting('allowed_values') ?: [];
    }

    // Query for unique track values
    $track_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->exists('field_track')
      ->accessCheck(FALSE);
    
    $track_nids = $track_query->execute();
    
    if (!empty($track_nids)) {
      $track_issues = $this->entityTypeManager->getStorage('node')->loadMultiple($track_nids);
      $track_values = [];
      
      foreach ($track_issues as $issue) {
        if ($issue->hasField('field_track') && !$issue->get('field_track')->isEmpty()) {
          $track_value = $issue->get('field_track')->value;
          if (!empty($track_value) && !in_array($track_value, $track_values)) {
            $track_values[] = $track_value;
          }
        }
      }
      
      // Convert values to labels and sort  
      $track_options = [];
      foreach ($track_values as $value) {
        // Since the database stores the actual display values, use them directly
        $track_options[] = ['value' => $value, 'label' => $value];
      }
      
      // Sort by label
      usort($track_options, function($a, $b) {
        return strcasecmp($a['label'], $b['label']);
      });
      
      $filter_options['tracks'] = $track_options;
    }

    // Get workstream field definition for allowed values
    $workstream_field_storage = $this->entityTypeManager->getStorage('field_storage_config')->load('node.field_workstream');
    $workstream_allowed_values = [];
    if ($workstream_field_storage) {
      $workstream_allowed_values = $workstream_field_storage->getSetting('allowed_values') ?: [];
    }

    // Query for unique workstream values
    $workstream_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->exists('field_workstream')
      ->accessCheck(FALSE);
    
    $workstream_nids = $workstream_query->execute();
    
    if (!empty($workstream_nids)) {
      $workstream_issues = $this->entityTypeManager->getStorage('node')->loadMultiple($workstream_nids);
      $workstream_values = [];
      
      foreach ($workstream_issues as $issue) {
        if ($issue->hasField('field_workstream') && !$issue->get('field_workstream')->isEmpty()) {
          $workstream_value = $issue->get('field_workstream')->value;
          if (!empty($workstream_value) && !in_array($workstream_value, $workstream_values)) {
            $workstream_values[] = $workstream_value;
          }
        }
      }
      
      // Convert values to labels and sort
      $workstream_options = [];
      foreach ($workstream_values as $value) {
        // Since the database stores the actual display values, use them directly
        $workstream_options[] = ['value' => $value, 'label' => $value];
      }
      
      // Sort by label
      usort($workstream_options, function($a, $b) {
        return strcasecmp($a['label'], $b['label']);
      });
      
      $filter_options['workstreams'] = $workstream_options;
    }

    // Get all issue tags
    $tags = [];
    try {
      $tag_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('status', 1)
        ->exists('field_issue_tags')
        ->accessCheck(FALSE);

      $tag_nids = $tag_query->execute();

      if (!empty($tag_nids)) {
        $tag_issues = $this->entityTypeManager->getStorage('node')->loadMultiple($tag_nids);

        foreach ($tag_issues as $issue) {
          if ($issue->hasField('field_issue_tags') && !$issue->get('field_issue_tags')->isEmpty()) {
            foreach ($issue->get('field_issue_tags')->getValue() as $item) {
              if (!empty($item['value'])) {
                $raw = $item['value'];
                // Support comma-separated tags in a single item.
                $parts = preg_split('/\s*,\s*/', $raw);
                foreach ($parts as $p) {
                  if ($p !== '' && !in_array($p, $tags)) {
                    $tags[] = $p;
                  }
                }
              }
            }
          }
        }
      }

      // Sort tags and format for options
      $tags = array_values(array_unique($tags));
      sort($tags, SORT_NATURAL | SORT_FLAG_CASE);

      $tag_options = [];
      foreach ($tags as $tag) {
        $tag_options[] = ['value' => $tag, 'label' => $tag];
      }

      $filter_options['tags'] = $tag_options;
    }
    catch (\Exception $e) {
      // Ignore errors, leave tags empty.
    }

    return $filter_options;
  }

  /**
   * Get the assignment status for an issue and contributor.
   *
   * @param $issue
   *   The issue node.
   * @param $contributor_id
   *   The contributor node ID.
   *
   * @return string
   *   'current' if currently assigned, 'historical' if historically assigned but not current, 'none' if never assigned.
   */
  private function getIssueAssignmentStatus($issue, $contributor_id) {
    $is_current_assignee = FALSE;
    $is_historical_assignee = FALSE;

    // Check current assignees.
    if ($issue->hasField('field_issue_assignees') && !$issue->get('field_issue_assignees')->isEmpty()) {
      foreach ($issue->get('field_issue_assignees') as $assignee) {
        if ($assignee->target_id == $contributor_id) {
          $is_current_assignee = TRUE;
          break;
        }
      }
    }

    // If currently assigned, return 'current' regardless of historical status.
    if ($is_current_assignee) {
      return 'current';
    }

    // Check if this contributor has any historical assignments for this issue.
    $historical_assignments = \Drupal\ai_dashboard\Entity\AssignmentRecord::loadByProperties([
      'issue_id' => $issue->id(),
      'assignee_id' => $contributor_id,
    ]);

    // Return 'historical' if there are past assignments but not currently assigned, otherwise 'none'.
    return !empty($historical_assignments) ? 'historical' : 'none';
  }

  /**
   * Check if an issue is currently assigned to a contributor.
   *
   * @param $issue
   *   The issue node.
   * @param $contributor_id
   *   The contributor node ID.
   *
   * @return bool
   *   TRUE if currently assigned, FALSE otherwise.
   */
  private function isCurrentlyAssigned($issue, $contributor_id) {
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
   * Get backlog data for unassigned issues.
   */
  private function getBacklogData(bool $non_developer_only = FALSE) {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Use direct database query to get all AI issues for performance.
    // Cacheability is handled at the render array level with appropriate cache tags.
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
      // For the non-developer view, only include issues marked as non-dev.
      // For the developer view, exclude issues marked as non-dev from backlog.
      if ($issue->hasField('field_issue_dashboard_category') && !$issue->get('field_issue_dashboard_category')->isEmpty()) {
        $has_non_dev = FALSE;
        foreach ($issue->get('field_issue_dashboard_category') as $item) {
          if ($item->value === 'non_dev') {
            $has_non_dev = TRUE;
            break;
          }
        }
        if ($non_developer_only && !$has_non_dev) {
          continue;
        }
        if (!$non_developer_only && $has_non_dev) {
          // Developer backlog should not show non-developer issues.
          continue;
        }
      } elseif ($non_developer_only) {
        // If no category set, exclude from non-developer view.
        continue;
      }
      // Get module info.
      $module_name = 'N/A';
      $module_id = NULL;
      if ($issue->hasField('field_issue_module') && !$issue->get('field_issue_module')->isEmpty()) {
        $module = $issue->get('field_issue_module')->entity;
        if ($module) {
          $module_name = $this->sanitizeText($module->getTitle());
          $module_id = $module->id();
          $all_modules[$module_id] = $module_name;
        }
      }

      // Get tags.
      $tags = [];
      if ($issue->hasField('field_issue_tags') && !$issue->get('field_issue_tags')->isEmpty()) {
        foreach ($issue->get('field_issue_tags') as $tag_field) {
          if (!empty($tag_field->value)) {
            $clean = $this->sanitizeText($tag_field->value);
            $tags[] = $clean;
            $all_tags[$clean] = $clean;
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
        'title' => $this->sanitizeText($issue->getTitle()),
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

      // Calculate target week.
      $assignment_date = new \DateTime();
      if ($week_offset !== 0) {
        $assignment_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }
      $assignment_date->modify('Monday this week');
      $week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($assignment_date);

      // Get current issue status.
      $issue_status = $issue->hasField('field_issue_status') ? $issue->get('field_issue_status')->value : 'active';

      // Create assignment record using AssignmentRecord system.
      $assignment_record = \Drupal\ai_dashboard\Entity\AssignmentRecord::createAssignment(
        $issue_id,
        $developer_id,
        $week_id,
        'drag_drop',
        $issue_status
      );

      if ($assignment_record) {
        // Update current assignees for compatibility.
        $issue->set('field_issue_assignees', [['target_id' => $developer_id]]);
        $issue->save();
      }

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

      // Calculate week IDs.
      $from_date = new \DateTime();
      if ($from_week !== 0) {
        $from_date->modify($from_week > 0 ? "+{$from_week} weeks" : $from_week . " weeks");
      }
      $from_date->modify('Monday this week');
      $from_week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($from_date);

      $to_date = new \DateTime();
      if ($to_week !== 0) {
        $to_date->modify($to_week > 0 ? "+{$to_week} weeks" : $to_week . " weeks");
      }
      $to_date->modify('Monday this week');
      $to_week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($to_date);

      // Get all assignments from the source week.
      $from_assignments = \Drupal\ai_dashboard\Entity\AssignmentRecord::getAssignmentsForWeek($from_week_id);
      $copied_count = 0;

      foreach ($from_assignments as $from_assignment) {
        $issue_id = $from_assignment->get('issue_id')->target_id;
        $assignee_id = $from_assignment->get('assignee_id')->target_id;
        
        // Check if assignment already exists for target week.
        $already_exists = \Drupal\ai_dashboard\Entity\AssignmentRecord::assignmentExists(
          $issue_id,
          $assignee_id,
          $to_week_id
        );

        if (!$already_exists) {
          // Load the issue to get current status.
          $issue = \Drupal\node\Entity\Node::load($issue_id);
          $issue_status = $issue && $issue->hasField('field_issue_status') ? $issue->get('field_issue_status')->value : 'active';

          // Create new assignment record for target week.
          $new_assignment = \Drupal\ai_dashboard\Entity\AssignmentRecord::createAssignment(
            $issue_id,
            $assignee_id,
            $to_week_id,
            'copy_week',
            $issue_status
          );

          if ($new_assignment) {
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
      $developer_id = $request->request->get('developer_id'); // Add this parameter
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

      // Calculate the current week.
      $current_week_date = new \DateTime();
      if ($week_offset !== 0) {
        $current_week_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }
      $current_week_date->modify('Monday this week');
      $week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($current_week_date);

      // Remove assignment records for this week.
      $assignments_to_remove = [];
      if ($developer_id) {
        // Remove specific developer's assignment for this week.
        $assignments_to_remove = \Drupal\ai_dashboard\Entity\AssignmentRecord::loadByProperties([
          'issue_id' => $issue_id,
          'assignee_id' => $developer_id,
          'week_id' => $week_id,
        ]);
      } else {
        // Remove all assignments for this issue in this week.
        $assignments_to_remove = \Drupal\ai_dashboard\Entity\AssignmentRecord::loadByProperties([
          'issue_id' => $issue_id,
          'week_id' => $week_id,
        ]);
      }

      // Delete the assignment records.
      if (!empty($assignments_to_remove)) {
        $assignment_storage = \Drupal::entityTypeManager()->getStorage('assignment_record');
        $assignment_storage->delete($assignments_to_remove);
      }

      // Update current assignees field if needed.
      // Check if this issue has any current assignments.
      $current_week_assignments = \Drupal\ai_dashboard\Entity\AssignmentRecord::loadByProperties([
        'issue_id' => $issue_id,
        'week_id' => $week_id,
      ]);

      if (empty($current_week_assignments)) {
        // No more assignments for current week - clear assignees.
        $issue->set('field_issue_assignees', []);
      } else {
        // Update assignees to reflect remaining assignments.
        $assignee_ids = [];
        foreach ($current_week_assignments as $assignment) {
          $assignee_ids[] = ['target_id' => $assignment->get('assignee_id')->target_id];
        }
        $issue->set('field_issue_assignees', $assignee_ids);
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

      // Calculate week ID for assignment checking.
      $week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($assignment_date);

      if (!empty($issue_ids)) {
        $issues = $node_storage->loadMultiple($issue_ids);

        foreach ($issues as $issue) {
          // Check if assignment already exists for this issue/developer/week.
          $already_assigned = \Drupal\ai_dashboard\Entity\AssignmentRecord::assignmentExists(
            $issue->id(),
            $developer_id,
            $week_id
          );

          if (!$already_assigned) {
            // Get current issue status.
            $issue_status = $issue->hasField('field_issue_status') ? $issue->get('field_issue_status')->value : 'active';
            
            // Create assignment record.
            $assignment_record = \Drupal\ai_dashboard\Entity\AssignmentRecord::createAssignment(
              $issue->id(),
              $developer_id,
              $week_id,
              'drupal_org_sync',
              $issue_status
            );

            if ($assignment_record) {
              // Update current assignees for compatibility.
              $issue->set('field_issue_assignees', [['target_id' => $developer_id]]);
              $issue->save();
              $synced_count++;
            }
          }
        }
        
        // Update tag mappings for all synced issues.
        $mappings_updated = $this->updateIssueMappings($issues);
      } else {
        $mappings_updated = 0;
      }

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      // Create response with no-cache headers for CloudFlare.
      $message = "Synced {$synced_count} issue" . ($synced_count != 1 ? 's' : '') . " from drupal.org for {$username}";
      if ($mappings_updated > 0) {
        $message .= ". Updated {$mappings_updated} issue" . ($mappings_updated != 1 ? 's' : '') . " with tag mappings";
      }
      
      $response = new JsonResponse([
        'success' => TRUE,
        'message' => $message,
        'synced_count' => $synced_count,
        'mappings_updated' => $mappings_updated,
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
      $all_synced_issues = [];

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

        // Add all issues to our collection for later tag mapping updates.
        $all_synced_issues = array_merge($all_synced_issues, $issues);

        foreach ($issues as $issue) {
          // Calculate week_id for the assignment date.
          $week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($assignment_date);
          
          // Check if assignment record already exists for this issue/contributor/week.
          $already_assigned = \Drupal\ai_dashboard\Entity\AssignmentRecord::assignmentExists(
            $issue->id(),
            $contributor->id(),
            $week_id
          );

          if (!$already_assigned) {
            // Get current issue status for the snapshot.
            $issue_status = $issue->hasField('field_issue_status') ? $issue->get('field_issue_status')->value : 'active';
            
            // Create assignment record using the new system.
            $assignment_record = \Drupal\ai_dashboard\Entity\AssignmentRecord::createAssignment(
              $issue->id(),
              $contributor->id(),
              $week_id,
              'drupal_org_sync',
              $issue_status
            );
            
            if ($assignment_record) {
              // Update current assignees field for backward compatibility.
              $issue->set('field_issue_assignees', [['target_id' => $contributor->id()]]);
              $issue->save();
              
              $contributor_synced++;
              $total_synced++;
            }
          }
        }

        if ($contributor_synced > 0) {
          $developers_synced++;
        }
      }

      // Now handle untracked users - find issues with do_assignee but no local contributor
      $untracked_query = $node_storage->getQuery()
        ->condition('type', 'ai_issue')
        ->exists('field_issue_do_assignee')
        ->condition('field_issue_status', ['active', 'needs_review', 'needs_work', 'rtbc'], 'IN')
        ->accessCheck(FALSE);

      $all_assigned_issues = $untracked_query->execute();
      $untracked_synced = 0;

      if (!empty($all_assigned_issues)) {
        $assigned_issues = $node_storage->loadMultiple($all_assigned_issues);
        $database = \Drupal::database();

        foreach ($assigned_issues as $issue) {
          $do_assignee = $issue->get('field_issue_do_assignee')->value;
          if (empty($do_assignee)) {
            continue;
          }

          // Check if we have a contributor for this username
          $contributor_query = $node_storage->getQuery()
            ->condition('type', 'ai_contributor')
            ->condition('field_drupal_username', $do_assignee)
            ->accessCheck(FALSE)
            ->range(0, 1);

          $contributor_ids = $contributor_query->execute();

          // If no contributor found, create assignment record with username only
          if (empty($contributor_ids)) {
            $week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($assignment_date);

            // Check if we already have an assignment record for this issue/username/week
            $existing = $database->select('assignment_record', 'ar')
              ->fields('ar', ['id'])
              ->condition('issue_id', $issue->id())
              ->condition('assignee_username', $do_assignee)
              ->condition('week_id', $week_id)
              ->execute()
              ->fetchField();

            if (!$existing) {
              // Create assignment record without assignee_id
              $issue_status = $issue->hasField('field_issue_status') ? $issue->get('field_issue_status')->value : 'active';

              // Try to get organization from drupal.org API (cached)
              $organization = $this->getUserOrganization($do_assignee);

              $database->insert('assignment_record')
                ->fields([
                  'issue_id' => $issue->id(),
                  'assignee_id' => NULL,
                  'assignee_username' => $do_assignee,
                  'assignee_organization' => $organization,
                  'week_id' => $week_id,
                  'week_date' => $assignment_date_string,
                  'issue_status_at_assignment' => $issue_status,
                  'assigned_date' => time(),
                  'source' => 'drupal_org_sync',
                ])
                ->execute();

              $untracked_synced++;
              $total_synced++;
            }
          }
        }
      }

      // Update tag mappings for all synced issues (remove duplicates).
      $unique_issues = [];
      foreach ($all_synced_issues as $issue) {
        $unique_issues[$issue->id()] = $issue;
      }
      $mappings_updated = $this->updateIssueMappings($unique_issues);

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      // Create response with no-cache headers.
      $message = "Synced {$total_synced} issue" . ($total_synced != 1 ? 's' : '') . " from drupal.org for {$developers_synced} developer" . ($developers_synced != 1 ? 's' : '');
      if ($untracked_synced > 0) {
        $message .= " and {$untracked_synced} untracked user" . ($untracked_synced != 1 ? 's' : '');
      }
      if ($mappings_updated > 0) {
        $message .= ". Updated {$mappings_updated} issue" . ($mappings_updated != 1 ? 's' : '') . " with tag mappings";
      }
      
      $response = new JsonResponse([
        'success' => TRUE,
        'message' => $message,
        'total_synced' => $total_synced,
        'mappings_updated' => $mappings_updated,
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

      // Calculate the target week date and week_id.
      $target_date = new \DateTime();
      if ($week_offset !== 0) {
        $target_date->modify($week_offset > 0 ? "+{$week_offset} weeks" : $week_offset . " weeks");
      }
      $target_date->modify('Monday this week');
      $week_id = \Drupal\ai_dashboard\Entity\AssignmentRecord::dateToWeekId($target_date);

      // Find all assignment records for this specific week.
      $assignments = \Drupal\ai_dashboard\Entity\AssignmentRecord::getAssignmentsForWeek($week_id);
      $removed_count = 0;
      $affected_issues = [];

      // Delete all assignment records for this week.
      foreach ($assignments as $assignment) {
        $issue_id = $assignment->get('issue_id')->target_id;
        $affected_issues[$issue_id] = $issue_id;
        $assignment->delete();
        $removed_count++;
      }

      // Update field_issue_assignees for affected issues by checking remaining assignments.
      if (!empty($affected_issues)) {
        $node_storage = $this->entityTypeManager->getStorage('node');
        $issues = $node_storage->loadMultiple($affected_issues);

        foreach ($issues as $issue) {
          // Check if this issue has any remaining assignments.
          $remaining_assignments = \Drupal\ai_dashboard\Entity\AssignmentRecord::getAssignmentsForIssue($issue->id());
          
          if (empty($remaining_assignments)) {
            // No more assignments, clear current assignees.
            $issue->set('field_issue_assignees', []);
            $issue->save();
          } else {
            // Update current assignees based on most recent assignment.
            $latest_assignment = end($remaining_assignments);
            $assignee_id = $latest_assignment->get('assignee_id')->target_id;
            $issue->set('field_issue_assignees', [['target_id' => $assignee_id]]);
            $issue->save();
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
   * Update all existing issues with current tag mappings.
   */
  public function updateAllTagMappings(Request $request) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      // Get all AI issues.
      $query = $node_storage->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('status', 1)
        ->accessCheck(FALSE);

      $issue_ids = $query->execute();

      if (empty($issue_ids)) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'No AI issues found to update',
          'updated_count' => 0,
        ]);
      }

      $issues = $node_storage->loadMultiple($issue_ids);
      $updated_count = $this->updateIssueMappings($issues);

      // Invalidate relevant caches.
      $this->invalidateCalendarCaches();

      return new JsonResponse([
        'success' => TRUE,
        'message' => "Updated {$updated_count} issue" . ($updated_count != 1 ? 's' : '') . " with current tag mappings",
        'updated_count' => $updated_count,
        'total_issues' => count($issues),
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Error updating all tag mappings: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to update tag mappings: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Get user organization from drupal.org API with caching.
   */
  protected function getUserOrganization(string $username): ?string {
    // Cache organizations to avoid repeated API calls
    static $org_cache = [];

    if (isset($org_cache[$username])) {
      return $org_cache[$username];
    }

    // Use state API for persistent caching (1 week)
    $cache_key = 'ai_dashboard.user_org.' . $username;
    $cached = \Drupal::state()->get($cache_key);

    if ($cached && isset($cached['time']) && (time() - $cached['time']) < 604800) {
      $org_cache[$username] = $cached['org'];
      return $cached['org'];
    }

    // Fetch from API - we need to find user by username first
    try {
      // Search for user by name
      $client = \Drupal::httpClient();
      $response = $client->get('https://www.drupal.org/api-d7/user.json?name=' . urlencode($username), [
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 5,
      ]);

      $data = json_decode($response->getBody(), TRUE);

      if (!empty($data['list']) && is_array($data['list'])) {
        $user = reset($data['list']);
        $organization = NULL;

        // Check field_organizations - contains field_collection_item references
        if (!empty($user['field_organizations']) && is_array($user['field_organizations'])) {
          $org_names = [];
          foreach ($user['field_organizations'] as $item) {
            if (!empty($item['id'])) {
              // Fetch the field collection item
              try {
                $item_response = $client->get('https://www.drupal.org/api-d7/field_collection_item/' . $item['id'] . '.json', [
                  'headers' => ['Accept' => 'application/json'],
                  'timeout' => 5,
                ]);
                $item_data = json_decode($item_response->getBody(), TRUE);

                // Get organization name from field_organization_name
                // Also check field_current to get current organization
                if (!empty($item_data['field_organization_name'])) {
                  $is_current = !empty($item_data['field_current']);
                  $org_name = $item_data['field_organization_name'];

                  // Prioritize current organizations
                  if ($is_current) {
                    array_unshift($org_names, $org_name);
                  } else {
                    $org_names[] = $org_name;
                  }
                }
              }
              catch (\Exception $e) {
                // Skip if we can't fetch this item
                continue;
              }
            }
          }

          if (!empty($org_names)) {
            // Return only the first (current) organization
            $organization = reset($org_names);
          }
        }

        // Also check field_current_company as fallback
        if (empty($organization) && !empty($user['field_current_company']['und'][0]['value'])) {
          $organization = $user['field_current_company']['und'][0]['value'];
        }

        // Cache the result
        \Drupal::state()->set($cache_key, ['org' => $organization, 'time' => time()]);
        $org_cache[$username] = $organization;

        return $organization;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->warning('Failed to fetch organization for @user: @error', [
        '@user' => $username,
        '@error' => $e->getMessage(),
      ]);
    }

    // Cache negative result too
    \Drupal::state()->set($cache_key, ['org' => NULL, 'time' => time()]);
    $org_cache[$username] = NULL;

    return NULL;
  }

  /**
   * Update tag mappings for given issues.
   *
   * @param array $issues
   *   Array of AI Issue nodes to update.
   *
   * @return int
   *   Number of issues updated.
   */
  private function updateIssueMappings(array $issues) {
    $updated_count = 0;
    
    foreach ($issues as $issue) {
      // Get current issue tags - handle multi-value field properly.
      $tags = [];
      if ($issue->hasField('field_issue_tags') && !$issue->get('field_issue_tags')->isEmpty()) {
        // Handle multi-value field - get all field values
        $tag_field_values = $issue->get('field_issue_tags')->getValue();
        foreach ($tag_field_values as $tag_value) {
          if (!empty($tag_value['value'])) {
            $tag = trim($tag_value['value']);
            // Also handle comma-separated values within individual field values (legacy support)
            if (strpos($tag, ',') !== false) {
              $split_tags = array_map('trim', explode(',', $tag));
              $tags = array_merge($tags, $split_tags);
            } else {
              $tags[] = $tag;
            }
          }
        }
      }
      
      if (empty($tags)) {
        continue;
      }
      
      // Process tags through mapping service.
      $processed_tags = $this->tagMappingService->processTags($tags);
      
      $needs_save = FALSE;
      
      // Update track mapping - set if available, clear if not.
      $current_track = $issue->hasField('field_track') ? $issue->get('field_track')->value : '';
      $new_track = !empty($processed_tags['track']) ? $processed_tags['track'] : '';
      if ($current_track !== $new_track) {
        $issue->set('field_track', $new_track);
        $needs_save = TRUE;
      }
      
      // Update workstream mapping - set if available, clear if not.
      $current_workstream = $issue->hasField('field_workstream') ? $issue->get('field_workstream')->value : '';
      $new_workstream = !empty($processed_tags['workstream']) ? $processed_tags['workstream'] : '';
      if ($current_workstream !== $new_workstream) {
        $issue->set('field_workstream', $new_workstream);
        $needs_save = TRUE;
      }
      
      if ($needs_save) {
        $issue->save();
        $updated_count++;
      }
    }
    
    return $updated_count;
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
      'ai_dashboard:tag_mappings',
    ];
    \Drupal::service('cache_tags.invalidator')->invalidateTags($cache_tags);

    // Clear all caches if needed (aggressive approach)
    \Drupal::service('cache.bootstrap')->deleteAll();
    \Drupal::service('cache.config')->deleteAll();
  }

}
