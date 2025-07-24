<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for AI Dashboard pages.
 */
class AiDashboardController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new AiDashboardController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * Main dashboard page.
   */
  public function main() {
    $build = [];

    // Dashboard wrapper.
    $build['#prefix'] = '<div class="ai-dashboard-container">';
    $build['#suffix'] = '</div>';

    // Dashboard header.
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-header']],
      'title' => ['#markup' => '<h1>AI Contribution Dashboard</h1>'],
      'subtitle' => ['#markup' => '<div class="subtitle">Companies, Contributors, and Current Issues</div>'],
      'navigation' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dashboard-navigation']],
        '#markup' => '<div class="nav-links">
          <a href="/ai-dashboard" class="nav-link active">Dashboard</a>
          <a href="/ai-dashboard/calendar" class="nav-link">Calendar View</a>
          <a href="/ai-dashboard/admin/contributors" class="nav-link">Contributors</a>
        </div>',
      ],
    ];

    // Get consolidated data.
    $companies_data = $this->getConsolidatedCompaniesData();

    // Create company sections.
    $build['companies'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['companies-overview']],
    ];

    foreach ($companies_data as $company_id => $company_data) {
      $build['companies']['company_' . $company_id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['company-section']],
      ];

      // Company header with logo.
      $build['companies']['company_' . $company_id]['header'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['company-header']],
        'logo' => $company_data['logo_markup'],
        'info' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['company-info']],
          'name' => ['#markup' => '<h2>' . $company_data['name'] . '</h2>'],
          'stats' => ['#markup' => '<div class="company-stats">' . count($company_data['contributors']) . ' contributors • ' . $company_data['total_issues'] . ' active issues</div>'],
        ],
      ];

      // Contributors and their issues.
      $build['companies']['company_' . $company_id]['contributors'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['contributors-grid']],
      ];

      foreach ($company_data['contributors'] as $contributor_id => $contributor_data) {
        $build['companies']['company_' . $company_id]['contributors']['contributor_' . $contributor_id] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['contributor-card']],
        ];

        // Check if user can edit contributors.
        $can_edit = \Drupal::currentUser()->hasPermission('edit any ai_contributor content');
        $edit_link = '';
        if ($can_edit && isset($contributor_data['nid'])) {
          $edit_link = '<div class="contributor-actions"><a href="/node/' . $contributor_data['nid'] . '/edit" class="edit-link">Edit</a></div>';
        }

        // Contributor info.
        $build['companies']['company_' . $company_id]['contributors']['contributor_' . $contributor_id]['info'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['contributor-info']],
          'name' => ['#markup' => '<h3>' . $contributor_data['name'] . '</h3>'],
          'role' => ['#markup' => '<div class="contributor-role">' . $contributor_data['role'] . '</div>'],
          'username' => ['#markup' => '<div class="contributor-username">@' . $contributor_data['username'] . '</div>'],
          'actions' => ['#markup' => $edit_link],
        ];

        // Current issues.
        $build['companies']['company_' . $company_id]['contributors']['contributor_' . $contributor_id]['issues'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['current-issues']],
          'title' => ['#markup' => '<h4>Current Issues (' . count($contributor_data['issues']) . ')</h4>'],
        ];

        if (empty($contributor_data['issues'])) {
          $build['companies']['company_' . $company_id]['contributors']['contributor_' . $contributor_id]['issues']['none'] = [
            '#markup' => '<div class="no-issues">No current issues assigned</div>',
          ];
        }
        else {
          $build['companies']['company_' . $company_id]['contributors']['contributor_' . $contributor_id]['issues']['list'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['issues-list']],
          ];

          // Check if user can edit issues.
          $can_edit_issues = \Drupal::currentUser()->hasPermission('edit any ai_issue content');

          foreach ($contributor_data['issues'] as $issue_index => $issue) {
            $status_class = 'status-' . str_replace(' ', '-', strtolower($issue['status']));
            $priority_class = 'priority-' . strtolower($issue['priority']);

            $issue_edit_link = '';
            if ($can_edit_issues && isset($issue['nid'])) {
              $issue_edit_link = ' | <a href="/node/' . $issue['nid'] . '/edit" class="edit-link">Edit</a>';
            }

            $build['companies']['company_' . $company_id]['contributors']['contributor_' . $contributor_id]['issues']['list']['issue_' . $issue_index] = [
              '#type' => 'container',
              '#attributes' => ['class' => ['issue-item', $status_class, $priority_class]],
              'number' => ['#markup' => '<span class="issue-number">#' . $issue['number'] . '</span>'],
              'title' => ['#markup' => '<span class="issue-title">' . $issue['title'] . '</span>'],
              'meta' => ['#markup' => '<div class="issue-meta"><span class="issue-status">' . $issue['status'] . '</span> • <span class="issue-priority">' . $issue['priority'] . '</span> • <span class="issue-module">' . $issue['module'] . '</span></div>'],
              'link' => $issue['url'] !== '#' ? [
                '#type' => 'link',
                '#title' => 'View',
                '#url' => Url::fromUri($issue['url']),
                '#attributes' => ['class' => ['issue-link'], 'target' => '_blank'],
              ] : [
                '#markup' => '<span class="issue-link disabled">No Link</span>',
              ],
              'edit' => ['#markup' => $issue_edit_link],
            ];
          }
        }
      }
    }

    // Attach the library.
    $build['#attached']['library'][] = 'ai_dashboard/dashboard';

    return $build;
  }

  /**
   * Contributors overview page.
   */
  public function contributors() {
    $build = [];

    // Add navigation.
    $build['navigation'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-navigation']],
      '#markup' => '<div class="nav-links">
        <a href="/ai-dashboard" class="nav-link">Dashboard</a>
        <a href="/ai-dashboard/calendar" class="nav-link">Calendar View</a>
        <a href="/ai-dashboard/contributors" class="nav-link active">Contributors</a>
        <a href="/ai-dashboard/issues" class="nav-link">Issues</a>
      </div>',
    ];

    // Check if user can edit content.
    $can_edit = \Drupal::currentUser()->hasPermission('edit any ai_contributor content');
    $can_create = \Drupal::currentUser()->hasPermission('create ai_contributor content');

    // Add create button if user has permission.
    if ($can_create) {
      $build['create_button'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['create-actions']],
        '#markup' => '<div style="margin: 20px 0; text-align: right;">
          <a href="/node/add/ai_contributor" class="btn btn-primary" style="background: #0073bb; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; font-weight: bold;">+ Add New Contributor</a>
        </div>',
      ];
    }

    // Load contributors with their companies and current allocations.
    $contributors_data = $this->getContributorsData();

    $headers = [
      'Name',
      'Drupal.org Username',
      'Company',
      'Role',
      'Current Week Allocation',
      'Monthly Issues',
    ];

    if ($can_edit) {
      $headers[] = 'Actions';
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => [],
      '#attributes' => ['class' => ['contributors-table']],
    ];

    foreach ($contributors_data as $contributor) {
      $row = [
        $contributor['name'],
        $contributor['drupal_username'],
        $contributor['company'],
        $contributor['role'],
        $contributor['current_allocation'] . ' days',
        $contributor['monthly_issues'],
      ];

      // Add action links if user has permission.
      if ($can_edit && isset($contributor['nid'])) {
        $actions = [];
        $actions[] = '<a href="/node/' . $contributor['nid'] . '/edit" style="color: #0073bb; text-decoration: none; margin-right: 10px;">Edit</a>';
        $actions[] = '<a href="/node/' . $contributor['nid'] . '/delete" style="color: #d72222; text-decoration: none;" onclick="return confirm(\'Are you sure you want to delete this contributor?\')">Delete</a>';
        $row[] = implode(' | ', $actions);
      }

      $build['table']['#rows'][] = $row;
    }

    $build['#attached']['library'][] = 'ai_dashboard/dashboard';

    return $build;
  }

  /**
   * Issues overview page.
   */
  public function issues() {
    $build = [];

    // Add navigation.
    $build['navigation'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-navigation']],
      '#markup' => '<div class="nav-links">
        <a href="/ai-dashboard" class="nav-link">Dashboard</a>
        <a href="/ai-dashboard/calendar" class="nav-link">Calendar View</a>
        <a href="/ai-dashboard/contributors" class="nav-link">Contributors</a>
        <a href="/ai-dashboard/issues" class="nav-link active">Issues</a>
      </div>',
    ];

    // Check if user can edit content.
    $can_edit = \Drupal::currentUser()->hasPermission('edit any ai_issue content');
    $can_create = \Drupal::currentUser()->hasPermission('create ai_issue content');

    // Add create button if user has permission.
    if ($can_create) {
      $build['create_button'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['create-actions']],
        '#markup' => '<div style="margin: 20px 0; text-align: right;">
          <a href="/node/add/ai_issue" class="btn btn-primary" style="background: #0073bb; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; font-weight: bold;">+ Add New Issue</a>
        </div>',
      ];
    }

    // Load issues with assignees and status.
    $issues_data = $this->getIssuesData();

    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['issues-filters']],
      'status_filter' => [
        '#type' => 'select',
        '#title' => 'Filter by Status',
        '#options' => [
          '' => '- All Statuses -',
          'active' => 'Active',
          'needs_review' => 'Needs Review',
          'needs_work' => 'Needs Work',
          'rtbc' => 'RTBC',
        ],
        '#attributes' => ['class' => ['status-filter']],
      ],
      'module_filter' => [
        '#type' => 'select',
        '#title' => 'Filter by Module',
        '#options' => $this->getModuleOptions(),
        '#attributes' => ['class' => ['module-filter']],
      ],
    ];

    $headers = [
      'Issue #',
      'Title',
      'Module',
      'Status',
      'Priority',
      'Category',
      'Deadline',
      'Assignees',
      'Link',
    ];

    if ($can_edit) {
      $headers[] = 'Actions';
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => [],
      '#attributes' => ['class' => ['issues-table']],
    ];

    foreach ($issues_data as $issue) {
      $row = [
        $issue['number'],
        $issue['title'],
        $issue['module'],
        $issue['status'],
        $issue['priority'],
        $issue['category'] ?? 'N/A',
        $issue['deadline'] ?? 'N/A',
        implode(', ', $issue['assignees']),
        [
          'data' => $issue['url'] !== '#' ? [
            '#type' => 'link',
            '#title' => 'View',
            '#url' => Url::fromUri($issue['url']),
            '#attributes' => ['target' => '_blank'],
          ] : [
            '#markup' => '<span style="color: #999;">No Link</span>',
          ],
        ],
      ];

      // Add action links if user has permission.
      if ($can_edit && isset($issue['nid'])) {
        $actions = [];
        $actions[] = '<a href="/node/' . $issue['nid'] . '/edit" style="color: #0073bb; text-decoration: none; margin-right: 10px;">Edit</a>';
        $actions[] = '<a href="/node/' . $issue['nid'] . '/delete" style="color: #d72222; text-decoration: none;" onclick="return confirm(\'Are you sure you want to delete this issue?\')">Delete</a>';
        $row[] = implode(' | ', $actions);
      }

      $build['table']['#rows'][] = $row;
    }

    $build['#attached']['library'][] = 'ai_dashboard/dashboard';

    return $build;
  }

  /**
   * Resource allocation page.
   */
  public function resources() {
    $build = [];

    // Get resource allocation data.
    $resource_data = $this->getResourceData();

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['resource-summary']],
      'title' => ['#markup' => '<h2>Resource Allocation Overview</h2>'],
    ];

    // Weekly allocation chart.
    $build['weekly_chart'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['weekly-allocation-chart']],
      'title' => ['#markup' => '<h3>Weekly Allocations</h3>'],
      'chart' => ['#markup' => '<div id="weekly-allocation-chart" style="height: 400px;"></div>'],
    ];

    // Monthly overview table.
    $build['monthly_table'] = [
      '#type' => 'table',
      '#caption' => 'Monthly Resource Overview',
      '#header' => [
        'Contributor',
        'Company',
        'Current Month Days',
        'Current Month Issues',
        'Previous Month Days',
        'Trend',
      ],
      '#rows' => [],
      '#attributes' => ['class' => ['monthly-resources-table']],
    ];

    foreach ($resource_data['monthly'] as $data) {
      $trend = $data['current_days'] > $data['previous_days'] ? '↗' :
               ($data['current_days'] < $data['previous_days'] ? '↘' : '→');

      $build['monthly_table']['#rows'][] = [
        $data['contributor'],
        $data['company'],
        $data['current_days'],
        $data['current_issues'],
        $data['previous_days'],
        $trend,
      ];
    }

    $build['#attached']['library'][] = 'ai_dashboard/dashboard';
    $build['#attached']['drupalSettings']['aiDashboard']['weeklyData'] = $resource_data['weekly'];

    return $build;
  }

  /**
   * Get dashboard statistics.
   */
  private function getDashboardStats() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    return [
      'contributors' => $node_storage->getQuery()
        ->condition('type', 'ai_contributor')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->count()
        ->execute(),
      'companies' => $node_storage->getQuery()
        ->condition('type', 'ai_company')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->count()
        ->execute(),
      'open_issues' => $node_storage->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('field_issue_status', ['active', 'needs_review', 'needs_work'], 'IN')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->count()
        ->execute(),
      'modules' => $node_storage->getQuery()
        ->condition('type', 'ai_module')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->count()
        ->execute(),
    ];
  }

  /**
   * Get recent activity data.
   */
  private function getRecentActivity() {
    // This would typically query for recent issues, allocations, etc.
    return [
      '#markup' => '<p>Recent activity will be displayed here (requires dummy data to be populated)</p>',
    ];
  }

  /**
   * Get contributors data with aggregated information.
   */
  private function getContributorsData() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    $contributor_ids = $node_storage->getQuery()
      ->condition('type', 'ai_contributor')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $contributors = $node_storage->loadMultiple($contributor_ids);
    $data = [];

    foreach ($contributors as $contributor) {
      // Get company name if referenced.
      $company_name = 'N/A';
      if ($contributor->hasField('field_contributor_company') && !$contributor->get('field_contributor_company')->isEmpty()) {
        $company = $contributor->get('field_contributor_company')->entity;
        if ($company) {
          $company_name = $company->getTitle();
        }
      }

      // Get role.
      $role = 'N/A';
      if ($contributor->hasField('field_contributor_role') && !$contributor->get('field_contributor_role')->isEmpty()) {
        $role = $contributor->get('field_contributor_role')->value;
      }

      // Get Drupal username.
      $username = 'N/A';
      if ($contributor->hasField('field_drupal_username') && !$contributor->get('field_drupal_username')->isEmpty()) {
        $username = $contributor->get('field_drupal_username')->value;
      }

      // Calculate current week allocation (simplified)
      $current_allocation = $this->getCurrentWeekAllocation($contributor->id());

      // Count monthly issues (simplified)
      $monthly_issues = $this->getMonthlyIssueCount($contributor->id());

      $data[] = [
        'nid' => $contributor->id(),
        'name' => $contributor->getTitle(),
        'drupal_username' => $username,
        'company' => $company_name,
        'role' => $role,
        'current_allocation' => number_format($current_allocation, 1),
        'monthly_issues' => $monthly_issues,
      ];
    }

    return $data;
  }

  /**
   * Get issues data.
   */
  private function getIssuesData() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    $issue_ids = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $issues = $node_storage->loadMultiple($issue_ids);
    $data = [];

    foreach ($issues as $issue) {
      // Get issue number.
      $number = 'N/A';
      if ($issue->hasField('field_issue_number') && !$issue->get('field_issue_number')->isEmpty()) {
        $number = $issue->get('field_issue_number')->value;
      }

      // Get module name.
      $module_name = 'N/A';
      if ($issue->hasField('field_issue_module') && !$issue->get('field_issue_module')->isEmpty()) {
        $module = $issue->get('field_issue_module')->entity;
        if ($module) {
          $module_name = $module->getTitle();
        }
      }

      // Get status.
      $status = 'active';
      if ($issue->hasField('field_issue_status') && !$issue->get('field_issue_status')->isEmpty()) {
        $status = $issue->get('field_issue_status')->value;
      }

      // Get priority.
      $priority = 'normal';
      if ($issue->hasField('field_issue_priority') && !$issue->get('field_issue_priority')->isEmpty()) {
        $priority = $issue->get('field_issue_priority')->value;
      }

      // Get assignees.
      $assignees = [];
      if ($issue->hasField('field_issue_assignees') && !$issue->get('field_issue_assignees')->isEmpty()) {
        foreach ($issue->get('field_issue_assignees') as $assignee_ref) {
          if ($assignee_ref->entity) {
            $assignees[] = $assignee_ref->entity->getTitle();
          }
        }
      }

      // Get tags.
      $tags = [];
      if ($issue->hasField('field_issue_tags') && !$issue->get('field_issue_tags')->isEmpty()) {
        foreach ($issue->get('field_issue_tags') as $tag_field) {
          if (!empty($tag_field->value)) {
            $tags[] = $tag_field->value;
          }
        }
      }

      // Get URL.
      $url = '#';
      if ($issue->hasField('field_issue_url') && !$issue->get('field_issue_url')->isEmpty()) {
        $url = $issue->get('field_issue_url')->uri;
      }

      // Get category.
      $category = 'N/A';
      if ($issue->hasField('field_issue_category') && !$issue->get('field_issue_category')->isEmpty()) {
        $category = $issue->get('field_issue_category')->value;
      }

      // Get deadline.
      $deadline = 'N/A';
      if ($issue->hasField('field_issue_deadline') && !$issue->get('field_issue_deadline')->isEmpty()) {
        $deadline_date = $issue->get('field_issue_deadline')->value;
        if ($deadline_date) {
          $deadline = date('M j, Y', strtotime($deadline_date));
        }
      }

      $data[] = [
        'nid' => $issue->id(),
        'number' => $number,
        'title' => $issue->getTitle(),
        'module' => $module_name,
        'status' => $status,
        'priority' => $priority,
        'category' => $category,
        'deadline' => $deadline,
        'assignees' => $assignees,
        'tags' => $tags,
        'url' => $url,
      ];
    }

    return $data;
  }

  /**
   * Get module options for filters.
   */
  private function getModuleOptions() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    $module_ids = $node_storage->getQuery()
      ->condition('type', 'ai_module')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $options = ['' => '- All Modules -'];

    if (!empty($module_ids)) {
      $modules = $node_storage->loadMultiple($module_ids);
      foreach ($modules as $module) {
        $options[$module->id()] = $module->getTitle();
      }
    }

    return $options;
  }

  /**
   * Get resource allocation data.
   */
  private function getResourceData() {
    return [
      'weekly' => [
        ['week' => '2024-01-01', 'contributor1' => 2.5, 'contributor2' => 1.0],
        ['week' => '2024-01-08', 'contributor1' => 3.0, 'contributor2' => 1.5],
        ['week' => '2024-01-15', 'contributor1' => 2.0, 'contributor2' => 2.0],
      ],
      'monthly' => [
        [
          'contributor' => 'Sample Contributor 1',
          'company' => 'Tech Company A',
          'current_days' => 10.5,
          'current_issues' => 3,
          'previous_days' => 8.0,
        ],
        [
          'contributor' => 'Sample Contributor 2',
          'company' => 'Startup B',
          'current_days' => 5.5,
          'current_issues' => 1,
          'previous_days' => 6.0,
        ],
      ],
    ];
  }

  /**
   * Get consolidated companies data with contributors and issues.
   */
  private function getConsolidatedCompaniesData() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Get all companies.
    $company_ids = $node_storage->getQuery()
      ->condition('type', 'ai_company')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $companies = $node_storage->loadMultiple($company_ids);
    $companies_data = [];

    foreach ($companies as $company) {
      $company_data = [
        'name' => $company->getTitle(),
        'logo_markup' => $this->getCompanyLogoMarkup($company),
        'contributors' => [],
        'total_issues' => 0,
      ];

      // Get contributors for this company.
      $contributor_ids = $node_storage->getQuery()
        ->condition('type', 'ai_contributor')
        ->condition('field_contributor_company', $company->id())
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($contributor_ids)) {
        $contributors = $node_storage->loadMultiple($contributor_ids);

        foreach ($contributors as $contributor) {
          // Get contributor details.
          $username = $contributor->hasField('field_drupal_username') && !$contributor->get('field_drupal_username')->isEmpty() ?
                     $contributor->get('field_drupal_username')->value : 'N/A';
          $role = $contributor->hasField('field_contributor_role') && !$contributor->get('field_contributor_role')->isEmpty() ?
                 $contributor->get('field_contributor_role')->value : 'Developer';

          // Get issues assigned to this contributor.
          $issue_ids = $node_storage->getQuery()
            ->condition('type', 'ai_issue')
            ->condition('field_issue_assignees', $contributor->id())
            ->condition('field_issue_status', ['active', 'needs_review', 'needs_work'], 'IN')
            ->condition('status', 1)
            ->accessCheck(FALSE)
            ->execute();

          $issues = [];
          if (!empty($issue_ids)) {
            $issue_nodes = $node_storage->loadMultiple($issue_ids);
            foreach ($issue_nodes as $issue) {
              $module_name = 'N/A';
              if ($issue->hasField('field_issue_module') && !$issue->get('field_issue_module')->isEmpty()) {
                $module = $issue->get('field_issue_module')->entity;
                if ($module) {
                  $module_name = $module->getTitle();
                }
              }

              $status = $issue->hasField('field_issue_status') && !$issue->get('field_issue_status')->isEmpty() ?
                       $issue->get('field_issue_status')->value : 'active';
              $priority = $issue->hasField('field_issue_priority') && !$issue->get('field_issue_priority')->isEmpty() ?
                         $issue->get('field_issue_priority')->value : 'normal';
              $number = $issue->hasField('field_issue_number') && !$issue->get('field_issue_number')->isEmpty() ?
                       $issue->get('field_issue_number')->value : 'N/A';
              $url = $issue->hasField('field_issue_url') && !$issue->get('field_issue_url')->isEmpty() ?
                    $issue->get('field_issue_url')->uri : '#';

              $issues[] = [
                'nid' => $issue->id(),
                'title' => $issue->getTitle(),
                'number' => $number,
                'status' => $status,
                'priority' => $priority,
                'module' => $module_name,
                'url' => $url,
              ];
            }
          }

          $company_data['contributors'][$contributor->id()] = [
            'nid' => $contributor->id(),
            'name' => $contributor->getTitle(),
            'username' => $username,
            'role' => $role,
            'issues' => $issues,
          ];

          $company_data['total_issues'] += count($issues);
        }
      }

      $companies_data[$company->id()] = $company_data;
    }

    return $companies_data;
  }

  /**
   * Get company logo markup.
   */
  private function getCompanyLogoMarkup($company) {
    if (!$company->hasField('field_company_logo') || $company->get('field_company_logo')->isEmpty()) {
      return ['#markup' => '<div class="company-logo-placeholder">' . substr($company->getTitle(), 0, 2) . '</div>'];
    }

    $logo_file = $company->get('field_company_logo')->entity;
    if (!$logo_file) {
      return ['#markup' => '<div class="company-logo-placeholder">' . substr($company->getTitle(), 0, 2) . '</div>'];
    }

    $logo_url = \Drupal::service('file_url_generator')->generateAbsoluteString($logo_file->getFileUri());

    return [
      '#markup' => '<div class="company-logo"><img src="' . $logo_url . '" alt="' . $company->getTitle() . ' Logo" /></div>',
    ];
  }

  /**
   * Get current week allocation for a contributor.
   */
  private function getCurrentWeekAllocation($contributor_id) {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Get start of current week (Monday)
    $start_of_week = new \DateTime();
    $start_of_week->modify('monday this week');

    $allocation_ids = $node_storage->getQuery()
      ->condition('type', 'ai_resource_allocation')
      ->condition('field_allocation_contributor', $contributor_id)
      ->condition('field_allocation_week', $start_of_week->format('Y-m-d'))
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $total_days = 0;
    if (!empty($allocation_ids)) {
      $allocations = $node_storage->loadMultiple($allocation_ids);
      foreach ($allocations as $allocation) {
        if ($allocation->hasField('field_allocation_days') && !$allocation->get('field_allocation_days')->isEmpty()) {
          $total_days += (float) $allocation->get('field_allocation_days')->value;
        }
      }
    }

    return $total_days;
  }

  /**
   * Get monthly issue count for a contributor.
   */
  private function getMonthlyIssueCount($contributor_id) {
    $node_storage = $this->entityTypeManager->getStorage('node');

    return $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_assignees', $contributor_id)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
