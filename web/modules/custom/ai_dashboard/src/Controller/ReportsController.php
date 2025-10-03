<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for AI Dashboard reports.
 */
class ReportsController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ReportsController object.
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
   * Reports overview page.
   */
  public function overview() {
    $build = [];

    $build['header'] = [
      '#markup' => '<h1>AI Dashboard Reports</h1>',
    ];

    $build['reports_list'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reports-list']],
    ];

    $build['reports_list']['import_configs'] = [
      '#markup' => '<div class="report-item">
        <h3><a href="/ai-dashboard/reports/import-configs">Import Configurations</a></h3>
        <p>View all active import configurations with their modules, components, and tag filters in CSV format.</p>
      </div>',
    ];

    $build['reports_list']['untracked_users'] = [
      '#markup' => '<div class="report-item">
        <h3><a href="/ai-dashboard/reports/untracked-users">Untracked Users</a></h3>
        <p>View drupal.org users who have been assigned to issues but don\'t have contributor profiles in our system.</p>
      </div>',
    ];

    $build['#attached']['library'][] = 'ai_dashboard/reports';

    return $build;
  }

  /**
   * Import configurations report.
   */
  public function importConfigs() {
    $build = [];

    $build['header'] = [
      '#markup' => '<h1>Import Configurations Report</h1>
        <p>Active import configurations and their filters. Copy the CSV data below for use in spreadsheets.</p>',
    ];

    // Load active import configurations
    $storage = $this->entityTypeManager->getStorage('module_import');
    $configs = $storage->loadByProperties(['active' => TRUE]);

    // Build CSV data
    $csv_data = [];
    $csv_data[] = ['Module', 'Components', 'Tags Filter', 'Tag Filter Mode', 'Status'];

    foreach ($configs as $config) {
      $module = $config->get('field_issue_module')->entity;
      $module_name = $module ? $module->getTitle() : $config->get('field_issue_module')->target_id;

      // Get components
      $components = [];
      if ($config->hasField('field_filter_components') && !$config->get('field_filter_components')->isEmpty()) {
        foreach ($config->get('field_filter_components')->getValue() as $value) {
          $components[] = $value['value'];
        }
      }
      $components_str = implode(', ', $components);

      // Get tags filter
      $tags = [];
      if ($config->hasField('field_filter_by_tags') && !$config->get('field_filter_by_tags')->isEmpty()) {
        foreach ($config->get('field_filter_by_tags')->getValue() as $value) {
          $tags[] = $value['value'];
        }
      }
      $tags_str = implode(', ', $tags);

      // Get tag filter mode
      $tag_mode = $config->hasField('field_tag_filter_mode') ? $config->get('field_tag_filter_mode')->value : 'include';

      // Get active status
      $active = $config->get('active')->value ? 'Active' : 'Inactive';

      $csv_data[] = [
        $module_name,
        $components_str,
        $tags_str,
        $tag_mode,
        $active
      ];
    }

    // Convert to CSV format
    $csv_output = '';
    foreach ($csv_data as $row) {
      $csv_output .= '"' . implode('","', array_map('addslashes', $row)) . '"' . "\n";
    }

    // Display as preformatted text for easy copying
    $build['csv_data'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['csv-data-container']],
      'data' => [
        '#markup' => '<pre class="csv-output">' . htmlspecialchars($csv_output) . '</pre>',
      ],
    ];

    // Add copy button
    $build['csv_data']['copy_button'] = [
      '#markup' => '<button id="copy-csv-btn" class="button button--primary">Copy CSV to Clipboard</button>',
    ];

    // Add a table view for better readability
    $build['table_view'] = [
      '#type' => 'table',
      '#header' => ['Module', 'Components', 'Tags Filter', 'Tag Filter Mode', 'Status'],
      '#rows' => array_slice($csv_data, 1), // Skip header row
      '#attributes' => ['class' => ['import-configs-table']],
      '#prefix' => '<h2>Table View</h2>',
    ];

    $build['#attached']['library'][] = 'ai_dashboard/reports';

    return $build;
  }

  /**
   * Untracked users report.
   */
  public function untrackedUsers() {
    $build = [];
    $request = \Drupal::request();
    $session = $request->getSession();

    // Get filter parameters
    $date_filter = $request->query->get('date_filter', $session->get('untracked_users_date_filter', 'all'));
    $start_date = $request->query->get('start_date', '');
    $end_date = $request->query->get('end_date', '');

    // Store filter in session
    $session->set('untracked_users_date_filter', $date_filter);

    // Calculate date range based on filter
    $date_conditions = [];
    $filter_description = '';

    switch ($date_filter) {
      case 'this_week':
        $start = strtotime('monday this week');
        $end = strtotime('sunday this week 23:59:59');
        $date_conditions = [
          ['assigned_date', $start, '>='],
          ['assigned_date', $end, '<='],
        ];
        $filter_description = 'This week';
        break;

      case 'last_week':
        $start = strtotime('monday last week');
        $end = strtotime('sunday last week 23:59:59');
        $date_conditions = [
          ['assigned_date', $start, '>='],
          ['assigned_date', $end, '<='],
        ];
        $filter_description = 'Last week';
        break;

      case 'this_month':
        $start = strtotime('first day of this month 00:00:00');
        $end = strtotime('last day of this month 23:59:59');
        $date_conditions = [
          ['assigned_date', $start, '>='],
          ['assigned_date', $end, '<='],
        ];
        $filter_description = 'This month';
        break;

      case 'last_month':
        $start = strtotime('first day of last month 00:00:00');
        $end = strtotime('last day of last month 23:59:59');
        $date_conditions = [
          ['assigned_date', $start, '>='],
          ['assigned_date', $end, '<='],
        ];
        $filter_description = 'Last month';
        break;

      case 'custom':
        if ($start_date && $end_date) {
          $date_conditions = [
            ['assigned_date', strtotime($start_date . ' 00:00:00'), '>='],
            ['assigned_date', strtotime($end_date . ' 23:59:59'), '<='],
          ];
          $filter_description = sprintf('From %s to %s', $start_date, $end_date);
        }
        break;

      default:
        $filter_description = 'All time';
        break;
    }

    $build['header'] = [
      '#type' => 'markup',
      '#markup' => '<h1>Untracked Users Report</h1>
        <p>Drupal.org users assigned to issues but not in our contributor database.' .
        ($filter_description ? ' <strong>(' . $filter_description . ')</strong>' : '') . '</p>',
    ];

    // Add filter form using Drupal Form API
    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['reports-filters']],
      'form' => \Drupal::formBuilder()->getForm('Drupal\ai_dashboard\Form\UntrackedUsersFilterForm', \Drupal::request()),
    ];

    // Query assignment_record table for untracked users
    $database = \Drupal::database();
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Build query for assignment records
    $query = $database->select('assignment_record', 'ar')
      ->fields('ar', ['assignee_username', 'assignee_organization', 'issue_id', 'assigned_date'])
      ->isNull('ar.assignee_id')  // Only untracked users (no contributor ID)
      ->isNotNull('ar.assignee_username')  // Must have username
      ->orderBy('ar.assignee_username');

    // Apply date filters to assigned_date
    if (!empty($date_conditions)) {
      foreach ($date_conditions as $condition) {
        // The conditions already have timestamps from the switch statement above
        $query->condition('ar.' . $condition[0], $condition[1], $condition[2]);
      }
    }

    $results = $query->execute()->fetchAll();

    // Group by username and collect issue details
    $untracked_users = [];
    $issue_cache = [];

    foreach ($results as $record) {
      $username_lower = strtolower($record->assignee_username);

      if (!isset($untracked_users[$username_lower])) {
        $untracked_users[$username_lower] = [
          'username' => $record->assignee_username,
          'organization' => $record->assignee_organization,
          'count' => 0,
          'issues' => [],
          'first_assigned' => $record->assigned_date,
          'last_assigned' => $record->assigned_date,
          'assignment_dates' => [], // Track all unique dates
        ];
      }

      $untracked_users[$username_lower]['count']++;

      // Track all assignment dates
      $date_key = date('Y-m-d', $record->assigned_date);
      $untracked_users[$username_lower]['assignment_dates'][$date_key] = $record->assigned_date;

      // Update date range
      if ($record->assigned_date < $untracked_users[$username_lower]['first_assigned']) {
        $untracked_users[$username_lower]['first_assigned'] = $record->assigned_date;
      }
      if ($record->assigned_date > $untracked_users[$username_lower]['last_assigned']) {
        $untracked_users[$username_lower]['last_assigned'] = $record->assigned_date;
      }

      // Store up to 5 example issues
      if (count($untracked_users[$username_lower]['issues']) < 5) {
        // Load issue if not cached
        if (!isset($issue_cache[$record->issue_id])) {
          $issue = $node_storage->load($record->issue_id);
          if ($issue) {
            $issue_cache[$record->issue_id] = [
              'nid' => $record->issue_id,
              'title' => $issue->getTitle(),
              'number' => $issue->hasField('field_issue_number') ? $issue->get('field_issue_number')->value : '',
            ];
          }
        }

        if (isset($issue_cache[$record->issue_id])) {
          $untracked_users[$username_lower]['issues'][] = $issue_cache[$record->issue_id];
        }
      }
    }

    // Sort by count (descending)
    uasort($untracked_users, function($a, $b) {
      return $b['count'] - $a['count'];
    });

    // Build CSV data
    $csv_data = [];
    $csv_data[] = ['Username', 'Organization', 'Issue Count', 'Assignment Period', 'Drupal.org Profile', 'Example Issues'];

    foreach ($untracked_users as $user_data) {
      $example_issues = [];
      foreach ($user_data['issues'] as $issue) {
        if (!empty($issue['number'])) {
          $example_issues[] = '#' . $issue['number'];
        }
      }

      // Drupal.org uses dashes instead of spaces in usernames
      $drupal_username = str_replace(' ', '-', $user_data['username']);

      // Format assignment period for CSV (same logic as table)
      $period_csv = 'N/A';
      if (!empty($user_data['assignment_dates'])) {
        $dates = array_keys($user_data['assignment_dates']);
        sort($dates);
        $date_count = count($dates);

        if ($date_count == 1) {
          $period_csv = date('M j Y', strtotime($dates[0]));
        } elseif ($date_count == 2) {
          $period_csv = date('M j', strtotime($dates[0])) . ' and ' . date('M j Y', strtotime($dates[1]));
        } elseif ($date_count <= 4) {
          $formatted_dates = [];
          foreach ($dates as $date) {
            $formatted_dates[] = date('M j', strtotime($date));
          }
          $period_csv = implode(' ', $formatted_dates) . ' ' . date('Y', strtotime(end($dates)));
        } else {
          $first_date = strtotime($dates[0]);
          $last_date = strtotime($dates[$date_count - 1]);
          $expected_days = (($last_date - $first_date) / 86400) + 1;

          if ($date_count >= $expected_days * 0.7) {
            $period_csv = date('M j', $first_date) . '-' . date('M j Y', $last_date);
          } else {
            $period_csv = date('M j', $first_date) . '-' . date('M j Y', $last_date) . ' (' . $date_count . ' days)';
          }
        }
      }

      $csv_data[] = [
        $user_data['username'],
        isset($user_data['organization']) ? ($user_data['organization'] ?: '-') : '-',
        $user_data['count'],
        $period_csv,
        'https://drupal.org/u/' . $drupal_username,
        implode(', ', array_slice($example_issues, 0, 3))
      ];
    }

    // Convert to CSV format
    $csv_output = '';
    foreach ($csv_data as $row) {
      $csv_output .= '"' . implode('","', array_map('addslashes', $row)) . '"' . "\n";
    }

    // Display summary
    $users_with_orgs = count(array_filter($untracked_users, fn($u) => !empty($u['organization'])));
    $build['summary'] = [
      '#markup' => '<div class="report-summary">
        <p><strong>Total untracked users:</strong> ' . count($untracked_users) . '</p>
        <p><strong>Total untracked assignments:</strong> ' . array_sum(array_column($untracked_users, 'count')) . '</p>
        <p><strong>Users with organizations:</strong> ' . $users_with_orgs . ' / ' . count($untracked_users) . '</p>
        <p class="info-note"><em>Note: Run <code>drush ai-dashboard:update-organizations</code> to fetch missing organization data from drupal.org.</em></p>
      </div>',
    ];

    // Build table rows
    $rows = [];
    foreach ($untracked_users as $user_data) {
      // Drupal.org uses dashes instead of spaces in usernames
      $drupal_username = str_replace(' ', '-', $user_data['username']);
      $profile_link = [
        '#type' => 'link',
        '#title' => $user_data['username'],
        '#url' => Url::fromUri('https://drupal.org/u/' . $drupal_username),
        '#attributes' => ['target' => '_blank'],
      ];

      $example_issues = [];
      foreach ($user_data['issues'] as $issue) {
        if (!empty($issue['number'])) {
          // Create links to AI tracker edit page and drupal.org
          $issue_links = [
            '#type' => 'inline_template',
            '#template' => '<span class="issue-link-group">
              <a href="/node/{{ issue_nid }}/edit" title="Edit in AI Tracker">#{{ issue_number }}</a>
              <a href="https://drupal.org/node/{{ issue_number }}" target="_blank" title="View on drupal.org" class="external-link">↗</a>
            </span>',
            '#context' => [
              'issue_nid' => $issue['nid'] ?? '',
              'issue_number' => $issue['number'],
            ],
          ];
          $example_issues[] = \Drupal::service('renderer')->render($issue_links);
        }
      }

      // Format assignment period - show actual dates or range
      $period = 'N/A';
      if (!empty($user_data['assignment_dates'])) {
        $dates = array_keys($user_data['assignment_dates']);
        sort($dates);
        $date_count = count($dates);

        if ($date_count == 1) {
          // Single date
          $period = date('M j, Y', strtotime($dates[0]));
        } elseif ($date_count == 2) {
          // Two dates - show both
          $period = date('M j', strtotime($dates[0])) . ', ' . date('M j, Y', strtotime($dates[1]));
        } elseif ($date_count <= 4) {
          // Few dates - list them all
          $formatted_dates = [];
          foreach ($dates as $date) {
            $formatted_dates[] = date('M j', strtotime($date));
          }
          $last = array_pop($formatted_dates);
          $period = implode(', ', $formatted_dates) . ', ' . $last . date(', Y', strtotime(end($dates)));
        } else {
          // Many dates - check if continuous or with gaps
          $first_date = strtotime($dates[0]);
          $last_date = strtotime($dates[$date_count - 1]);
          $expected_days = (($last_date - $first_date) / 86400) + 1; // Days between first and last

          if ($date_count >= $expected_days * 0.7) {
            // Mostly continuous (70% or more days filled) - show as range
            $period = date('M j', $first_date) . ' - ' . date('M j, Y', $last_date);
          } else {
            // Sparse assignments - show first, last and count
            $period = date('M j', $first_date) . ' - ' . date('M j, Y', $last_date) . ' (' . $date_count . ' days)';
          }
        }
      }

      $rows[] = [
        'username' => \Drupal::service('renderer')->render($profile_link),
        'organization' => isset($user_data['organization']) ? ($user_data['organization'] ?: '-') : '-',
        'count' => $user_data['count'],
        'period' => $period,
        'examples' => ['data' => ['#markup' => implode(' ', array_slice($example_issues, 0, 3))]],
      ];
    }

    // Add a table view for better readability
    if (!empty($rows)) {
      $build['table_view'] = [
        '#type' => 'table',
        '#header' => ['Username', 'Organization', 'Issue Count', 'Assignment Period', 'Example Issues'],
        '#rows' => $rows,
        '#attributes' => ['class' => ['untracked-users-table']],
        '#prefix' => '<h2>Table View</h2>',
      ];
    } else {
      $build['no_results'] = [
        '#markup' => '<p>No untracked users found. All assignees have contributor profiles.</p>',
      ];
    }

    // Move CSV export below the table
    if (!empty($untracked_users)) {
      $build['csv_export'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['csv-data-container']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Export Data'),
        ],
        'csv_output' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#value' => $csv_output,
          '#attributes' => ['class' => ['csv-output']],
        ],
        'copy_button' => [
          '#type' => 'button',
          '#value' => $this->t('Copy CSV to Clipboard'),
          '#attributes' => [
            'id' => 'copy-csv-btn',
            'class' => ['button', 'button--primary'],
            'type' => 'button',
          ],
        ],
      ];
    }

    $build['#attached']['library'][] = 'ai_dashboard/reports';

    return $build;
  }
}