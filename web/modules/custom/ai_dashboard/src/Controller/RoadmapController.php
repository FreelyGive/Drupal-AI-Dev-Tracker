<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\Cache;

/**
 * Controller for the AI Deliverables Roadmap.
 */
class RoadmapController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a RoadmapController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Display the roadmap view.
   */
  public function view() {
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    // 1. Get all issues with "AI Deliverable" tag
    $query = $nodeStorage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_tags', '%AI Deliverable%', 'LIKE')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    $deliverable_nids = $query->execute();

    // 2. Load ordering data
    $ordering = [];
    $order_query = $this->database->select('ai_dashboard_roadmap_order', 'ro')
      ->fields('ro', ['issue_nid', 'column_name', 'weight']);

    foreach ($order_query->execute() as $row) {
      $ordering[$row->issue_nid] = [
        'column' => $row->column_name,
        'weight' => $row->weight,
      ];
    }

    // 3. Group by derived status
    $columns = [
      'complete' => [],
      'now' => [],
      'next' => [],
      'later' => [],
    ];

    foreach ($deliverable_nids as $nid) {
      $issue = Node::load($nid);
      if (!$issue) {
        continue;
      }

      // Find if any project links to this deliverable
      $linked_project = $this->findLinkedProject($nid);

      // Use saved column if exists, otherwise derive status
      if (isset($ordering[$nid]['column'])) {
        $status = $ordering[$nid]['column'];
      } else {
        // Derive status based on assignee and project
        $status = $this->getDeliverableStatus($issue, $linked_project);
      }

      // Calculate progress if project exists
      $progress = null;
      if ($linked_project) {
        $progress = $this->calculateProgressFromProject($nid, $linked_project->id());
      }

      $columns[$status][] = [
        'issue' => $issue,
        'progress' => $progress,
        'project' => $linked_project,
        'weight' => $ordering[$nid]['weight'] ?? 999,
      ];
    }

    // 4. Sort within columns by weight, then by changed date
    foreach ($columns as &$column) {
      usort($column, function($a, $b) {
        // First sort by manual weight
        if ($a['weight'] != $b['weight']) {
          return $a['weight'] <=> $b['weight'];
        }
        // Then by changed date
        return $b['issue']->changed->value <=> $a['issue']->changed->value;
      });
    }

    // Count totals for summary
    $total_count = count($deliverable_nids);
    $complete_count = count($columns['complete']);
    $now_count = count($columns['now']);
    $next_count = count($columns['next']);
    $later_count = count($columns['later']);

    $build = [
      '#theme' => 'ai_roadmap',
      '#columns' => $columns,
      '#summary' => [
        'total' => $total_count,
        'complete' => $complete_count,
        'now' => $now_count,
        'next' => $next_count,
        'later' => $later_count,
      ],
      '#user_has_admin' => $this->currentUser()->hasPermission('administer ai dashboard content'),
      '#cache' => [
        'tags' => [
          'node_list:ai_issue',
          'node_list:ai_project',
          'ai_dashboard:roadmap',
        ],
        'contexts' => [
          'user.permissions',
        ],
      ],
      '#attached' => [
        'library' => [
          'ai_dashboard/roadmap',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Save the order of deliverables via AJAX.
   */
  public function saveOrder(Request $request) {
    // Check admin permission
    if (!$this->currentUser()->hasPermission('administer ai dashboard content')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!isset($data['columns']) || !is_array($data['columns'])) {
      return new JsonResponse(['error' => 'Invalid data'], 400);
    }

    // Save the new order for each column
    foreach ($data['columns'] as $columnName => $items) {
      foreach ($items as $weight => $item) {
        $this->database->merge('ai_dashboard_roadmap_order')
          ->keys(['issue_nid' => $item['nid']])
          ->fields([
            'column_name' => $columnName,
            'weight' => $weight,
          ])
          ->execute();
      }
    }

    // Clear caches properly
    Cache::invalidateTags([
      'ai_dashboard:roadmap',
      'node_list:ai_issue',
    ]);

    // Clear render cache
    \Drupal::cache('render')->deleteAll();

    return new JsonResponse(['success' => TRUE]);
  }

  /**
   * Get deliverable status based on issue state.
   */
  protected function getDeliverableStatus($issue, $linked_project = NULL) {
    $status = $issue->get('field_issue_status')->value;

    // Complete column
    if (in_array($status, ['fixed', 'closed_fixed', 'closed_duplicate', 'closed_works'])) {
      return 'complete';
    }

    // Check assignee
    $has_assignee = FALSE;
    if ($issue->hasField('field_issue_assignees') && !$issue->get('field_issue_assignees')->isEmpty()) {
      $has_assignee = TRUE;
    }

    if (!$has_assignee) {
      return 'later'; // No assignee = Later
    }

    // Has assignee - check if in a tracker project
    if ($linked_project !== NULL) {
      return 'now'; // Has assignee AND in a project = Now (priority)
    }

    return 'next'; // Has assignee but NOT in project = Next (being worked on but not priority)
  }

  /**
   * Find project that links to this deliverable.
   */
  protected function findLinkedProject($deliverable_nid) {
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    // Find project that references this deliverable as primary
    $projects = $nodeStorage->getQuery()
      ->condition('type', 'ai_project')
      ->condition('field_project_deliverable', $deliverable_nid)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();

    if (!empty($projects)) {
      return Node::load(reset($projects));
    }

    // Check if this deliverable is in any project by either method:
    // 1. Check the project_issue table (for explicitly ordered issues)
    $in_project = $this->database->select('ai_dashboard_project_issue', 'pi')
      ->fields('pi', ['project_nid'])
      ->condition('issue_nid', $deliverable_nid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($in_project) {
      return Node::load($in_project);
    }

    // 2. Check by tag matching (same logic as project view)
    $issue = Node::load($deliverable_nid);
    if ($issue && $issue->hasField('field_issue_tags')) {
      // Get issue's tags
      $issue_tags = [];
      if (!$issue->get('field_issue_tags')->isEmpty()) {
        foreach ($issue->get('field_issue_tags')->getValue() as $v) {
          $raw = $v['value'] ?? '';
          foreach (preg_split('/\s*,\s*/', $raw) as $p) {
            if ($p !== '') {
              $issue_tags[] = mb_strtolower(trim($p));
            }
          }
        }
      }

      if (!empty($issue_tags)) {
        // Find projects that have matching tags
        $project_query = $nodeStorage->getQuery()
          ->condition('type', 'ai_project')
          ->condition('status', 1)
          ->accessCheck(FALSE);

        $project_nids = $project_query->execute();

        foreach ($project_nids as $project_nid) {
          $project = Node::load($project_nid);
          if ($project && $project->hasField('field_project_tags') && !$project->get('field_project_tags')->isEmpty()) {
            // Get project tags
            foreach ($project->get('field_project_tags')->getValue() as $item) {
              $val = trim($item['value']);
              if ($val !== '') {
                $parts = preg_split('/\s*,\s*/', $val);
                foreach ($parts as $project_tag) {
                  if ($project_tag !== '' && in_array(mb_strtolower($project_tag), $issue_tags, TRUE)) {
                    // Found a matching tag!
                    return $project;
                  }
                }
              }
            }
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Calculate progress from project hierarchy.
   */
  protected function calculateProgressFromProject($deliverable_nid, $project_nid) {
    // Get ALL descendants recursively (children, grandchildren, etc.)
    $sub_issue_nids = $this->getAllDescendants($deliverable_nid, $project_nid);

    if (empty($sub_issue_nids)) {
      return NULL; // No sub-issues, no progress to show
    }

    $completed = 0;
    $total = 0; // Count non-meta issues only
    $nodeStorage = $this->entityTypeManager()->getStorage('node');

    foreach ($sub_issue_nids as $issue_nid) {
      $issue = $nodeStorage->load($issue_nid);
      if ($issue) {
        // Skip meta issues in counts
        if ($issue->hasField('field_is_meta_issue') && !$issue->get('field_is_meta_issue')->isEmpty() && $issue->get('field_is_meta_issue')->value) {
          continue;
        }

        $total++; // Count this non-meta issue

        if ($issue->hasField('field_issue_status') && !$issue->get('field_issue_status')->isEmpty()) {
          $status = $issue->get('field_issue_status')->value;
          if ($this->isStatusCompleted($status)) {
            $completed++;
          }
        }
      }
    }
    $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;

    return [
      'percentage' => $percentage,
      'completed' => $completed,
      'total' => $total,
    ];
  }

  /**
   * Check if an issue status is considered "completed".
   */
  private function isStatusCompleted($status) {
    $completed_statuses = ['fixed', 'closed', 'rtbc'];
    return in_array($status, $completed_statuses);
  }

  /**
   * Recursively get all descendant issue NIDs.
   */
  private function getAllDescendants($parent_nid, $project_nid, &$processed = []) {
    // Prevent infinite loops
    if (in_array($parent_nid, $processed)) {
      return [];
    }
    $processed[] = $parent_nid;

    // Get direct children
    $query = $this->database->select('ai_dashboard_project_issue', 'pi')
      ->fields('pi', ['issue_nid'])
      ->condition('project_nid', $project_nid)
      ->condition('parent_issue_nid', $parent_nid);

    $direct_children = $query->execute()->fetchCol();

    if (empty($direct_children)) {
      return [];
    }

    $all_descendants = $direct_children;

    // Recursively get descendants of each child
    foreach ($direct_children as $child_nid) {
      $grandchildren = $this->getAllDescendants($child_nid, $project_nid, $processed);
      $all_descendants = array_merge($all_descendants, $grandchildren);
    }

    return array_unique($all_descendants);
  }
}