<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class ProjectIssuesController extends ControllerBase {

  /**
   * Display project issues management page by project name.
   */
  public function manageByName($project_name, Request $request) {
    $node = $this->loadProjectBySlug($project_name);
    if (!$node) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    return $this->manage($node, $request);
  }

  /**
   * Save order by project name.
   */
  public function saveOrderByName($project_name, Request $request) {
    $node = $this->loadProjectBySlug($project_name);
    if (!$node) {
      return new JsonResponse(['error' => 'Project not found'], 404);
    }
    return $this->saveOrder($node, $request);
  }

  /**
   * Title callback for project issues page by name.
   */
  public function titleByName($project_name) {
    $node = $this->loadProjectBySlug($project_name);
    if (!$node) {
      return $this->t('Project Not Found');
    }
    return $this->title($node);
  }

  /**
   * Load project by slug.
   */
  private function loadProjectBySlug($slug) {
    $storage = $this->entityTypeManager()->getStorage('node');
    $nodes = $storage->loadByProperties([
      'type' => 'ai_project',
      'status' => 1,
    ]);
    
    foreach ($nodes as $node) {
      if ($this->generateSlug($node->label()) === $slug) {
        return $node;
      }
    }
    
    return NULL;
  }

  /**
   * Generate URL-friendly slug from title.
   */
  private function generateSlug($title) {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
  }

  /**
   * Display project issues management page.
   */
  public function manage(NodeInterface $node, Request $request) {
    // Check if this is an AI Project node
    if ($node->bundle() !== 'ai_project') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Get filters from request
    $filters = [
      'tag' => $request->query->get('tag', ''),
      'priority' => $request->query->get('priority', ''),
      'status' => $request->query->get('status', ''),
      'track' => $request->query->get('track', ''),
      'workstream' => $request->query->get('workstream', ''),
      'search' => $request->query->get('search', ''),
    ];

    // Get project tags for filtering
    $project_tags = [];
    if ($node->hasField('field_project_tags') && !$node->get('field_project_tags')->isEmpty()) {
      foreach ($node->get('field_project_tags')->getValue() as $item) {
        $val = trim($item['value']);
        if ($val !== '') {
          $parts = preg_split('/\s*,\s*/', $val);
          foreach ($parts as $p) {
            if ($p !== '') {
              $project_tags[] = $p;
            }
          }
        }
      }
    }

    // Load issues filtered by project tags
    $issues = $this->loadProjectIssues($node->id(), $project_tags, $filters);

    // Get filter options
    $filter_options = $this->getFilterOptions($issues);

    // Load primary deliverable if set
    $primary_deliverable = NULL;
    $primary_deliverable_id = NULL;
    if ($node->hasField('field_project_deliverable') && !$node->get('field_project_deliverable')->isEmpty()) {
      $primary_deliverable = $node->get('field_project_deliverable')->entity;
      $primary_deliverable_id = $primary_deliverable ? $primary_deliverable->id() : NULL;
    }

    // Load all deliverables for this project (issues with AI Deliverable tag and matching project tags)
    // Pass primary deliverable ID to exclude it and project ID for ordering
    $deliverables = $this->loadProjectDeliverables($project_tags, $primary_deliverable_id, $node->id());

    $build = [
      '#theme' => 'ai_project_issues',
      '#project' => $node,
      '#primary_deliverable' => $primary_deliverable,
      '#deliverables' => $deliverables,
      '#issues' => $issues,
      '#filters' => $filters,
      '#filter_options' => $filter_options,
      '#user_has_admin' => $this->currentUser()->hasPermission('administer ai dashboard content'),
      '#cache' => [
        'contexts' => [
          'url.query_args',
          'user.permissions',
          'user', // Cache per user to handle ordering
        ],
        'tags' => [
          'node:' . $node->id(),
          'node_list:ai_issue',
          'ai_dashboard:project:' . $node->id(),
          'ai_dashboard:project_issues',
        ],
      ],
      '#attached' => [
        'library' => [
          'ai_dashboard/project_issues',
          'ai_dashboard/sortable',
        ],
        'drupalSettings' => [
          'aiDashboard' => [
            'projectId' => $node->id(),
            'saveOrderUrl' => "/ai-dashboard/project/" . $this->generateSlug($node->label()) . "/issues/save-order",
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Save the order and hierarchy of issues.
   */
  public function saveOrder(NodeInterface $node, Request $request) {
    if (!$this->currentUser()->hasPermission('administer ai dashboard content')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    
    if (!isset($data['items']) || !is_array($data['items'])) {
      return new JsonResponse(['error' => 'Invalid data'], 400);
    }

    $connection = Database::getConnection();
    $transaction = $connection->startTransaction();
    
    try {
      foreach ($data['items'] as $item) {
        // Merge needs all fields including keys
        $fields = [
          'project_nid' => $node->id(),
          'issue_nid' => $item['nid'],
          'weight' => $item['weight'],
          'indent_level' => $item['indent'],
          'parent_issue_nid' => $item['parent'] ?? NULL,
        ];
        
        $connection->merge('ai_dashboard_project_issue')
          ->keys(['project_nid' => $node->id(), 'issue_nid' => $item['nid']])
          ->fields($fields)
          ->execute();
      }
      
      // Clear relevant caches
      \Drupal::service('cache_tags.invalidator')->invalidateTags([
        'node:' . $node->id(),
        'node_list:ai_issue',
        'ai_dashboard:project:' . $node->id(),
        'ai_dashboard:project_issues',
      ]);
      
      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      \Drupal::logger('ai_dashboard')->error('Save order failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Save failed: ' . $e->getMessage()], 500);
    }
  }

  /**
   * Load deliverables for a project.
   */
  private function loadProjectDeliverables(array $project_tags, $exclude_nid = NULL, $project_id = NULL) {
    if (empty($project_tags)) {
      return [];
    }

    $storage = $this->entityTypeManager()->getStorage('node');

    // Get all AI issues with "AI Deliverable" tag
    $query = $storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_tags', '%AI Deliverable%', 'LIKE')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    // Exclude the primary deliverable if specified
    if ($exclude_nid) {
      $query->condition('nid', $exclude_nid, '!=');
    }

    $nids = $query->execute();
    if (empty($nids)) {
      return [];
    }

    // Get ordering from project hierarchy if project_id is provided
    $ordering = [];
    if ($project_id) {
      $connection = Database::getConnection();
      $order_query = $connection->select('ai_dashboard_project_issue', 'pi')
        ->fields('pi', ['issue_nid', 'weight'])
        ->condition('project_nid', $project_id)
        ->condition('issue_nid', $nids, 'IN')
        ->orderBy('weight', 'ASC');

      foreach ($order_query->execute() as $row) {
        $ordering[(int) $row->issue_nid] = (int) $row->weight;
      }
    }

    $nodes = $storage->loadMultiple($nids);
    $deliverables = [];

    foreach ($nodes as $node) {
      // Check if this deliverable matches project tags
      $issue_tags = [];
      if ($node->hasField('field_issue_tags') && !$node->get('field_issue_tags')->isEmpty()) {
        foreach ($node->get('field_issue_tags')->getValue() as $v) {
          $raw = $v['value'] ?? '';
          foreach (preg_split('/\s*,\s*/', $raw) as $p) {
            if ($p !== '') {
              $issue_tags[] = mb_strtolower(trim($p));
            }
          }
        }
      }

      // Check for tag match
      $match = FALSE;
      foreach ($project_tags as $tag) {
        if (in_array(mb_strtolower($tag), $issue_tags, TRUE)) {
          $match = TRUE;
          break;
        }
      }

      if ($match) {
        // Calculate progress
        $progress = $this->calculateDeliverableProgress($node);

        $deliverables[] = [
          'node' => $node,
          'progress' => $progress,
          'weight' => $ordering[$node->id()] ?? 9999,
        ];
      }
    }

    // Sort deliverables by weight
    usort($deliverables, function($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });

    return $deliverables;
  }

  /**
   * Calculate progress for a deliverable based on child issues.
   */
  private function calculateDeliverableProgress($deliverable) {
    $deliverable_nid = $deliverable->id();

    // Get ALL descendants recursively (children, grandchildren, etc.)
    $child_nids = $this->getAllDescendantIssues($deliverable_nid);

    if (empty($child_nids)) {
      // No child issues, check the deliverable's own status
      $status = $deliverable->hasField('field_issue_status') && !$deliverable->get('field_issue_status')->isEmpty()
        ? $deliverable->get('field_issue_status')->value
        : 'active';

      $completed = $this->isStatusCompleted($status) ? 1 : 0;
      return [
        'total' => 1,
        'completed' => $completed,
        'percentage' => $completed * 100,
      ];
    }

    // Load child issues
    $storage = $this->entityTypeManager()->getStorage('node');
    $child_nodes = $storage->loadMultiple($child_nids);

    $total = 0;
    $completed = 0;

    foreach ($child_nodes as $child) {
      // Skip meta issues in counts
      if ($child->hasField('field_is_meta_issue') && !$child->get('field_is_meta_issue')->isEmpty() && $child->get('field_is_meta_issue')->value) {
        continue;
      }

      $total++; // Count this non-meta issue

      if ($child->hasField('field_issue_status') && !$child->get('field_issue_status')->isEmpty()) {
        $status = $child->get('field_issue_status')->value;
        if ($this->isStatusCompleted($status)) {
          $completed++;
        }
      }
    }

    return [
      'total' => $total,
      'completed' => $completed,
      'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
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
   * Load issues for a project with hierarchy.
   */
  private function loadProjectIssues($project_id, array $project_tags, array $filters) {
    $storage = $this->entityTypeManager()->getStorage('node');
    
    // Get all AI issues
    $query = $storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->accessCheck(TRUE);

    // Apply status filter
    if (!empty($filters['status'])) {
      $query->condition('field_issue_status', $filters['status']);
    }

    // Apply priority filter
    if (!empty($filters['priority'])) {
      $query->condition('field_issue_priority', $filters['priority']);
    }

    $nids = $query->execute();
    if (empty($nids)) {
      return [];
    }

    $nodes = $storage->loadMultiple($nids);
    
    // Load project metadata
    $project_meta = $this->loadProjectMeta($project_id);
    
    $issues = [];
    foreach ($nodes as $node) {
      // Filter by project tags if any
      if (!empty($project_tags)) {
        $issue_tags = [];
        if ($node->hasField('field_issue_tags') && !$node->get('field_issue_tags')->isEmpty()) {
          foreach ($node->get('field_issue_tags')->getValue() as $v) {
            $raw = $v['value'] ?? '';
            foreach (preg_split('/\s*,\s*/', $raw) as $p) {
              if ($p !== '') {
                $issue_tags[] = mb_strtolower(trim($p));
              }
            }
          }
        }
        
        $match = FALSE;
        foreach ($project_tags as $tag) {
          if (in_array(mb_strtolower($tag), $issue_tags, TRUE)) {
            $match = TRUE;
            break;
          }
        }
        if (!$match) {
          continue;
        }
      }

      // Apply additional filters
      if (!empty($filters['tag'])) {
        $has_tag = FALSE;
        if ($node->hasField('field_issue_tags') && !$node->get('field_issue_tags')->isEmpty()) {
          foreach ($node->get('field_issue_tags')->getValue() as $v) {
            if (stripos($v['value'], $filters['tag']) !== FALSE) {
              $has_tag = TRUE;
              break;
            }
          }
        }
        if (!$has_tag) {
          continue;
        }
      }

      // Build issue data
      $nid = $node->id();
      $meta = $project_meta[$nid] ?? ['weight' => 9999, 'indent' => 0, 'parent' => NULL];

      // Get short title with fallback to full title
      $short_title = '';
      if ($node->hasField('field_short_title') && !$node->get('field_short_title')->isEmpty()) {
        $short_title = $node->get('field_short_title')->value;
      }
      $display_title = $short_title ?: $node->label();

      $issue = [
        'nid' => $nid,
        'id' => $nid,
        'title' => $display_title,
        'full_title' => $node->label(),
        'status' => $node->get('field_issue_status')->value ?? 'active',
        'priority' => $node->get('field_issue_priority')->value ?? 'normal',
        'weight' => $meta['weight'],
        'indent' => $meta['indent'],
        'parent' => $meta['parent'],
        'url' => $node->hasField('field_issue_url') && !$node->get('field_issue_url')->isEmpty() 
          ? $node->get('field_issue_url')->uri : '#',
        'issue_number' => $node->hasField('field_issue_number') && !$node->get('field_issue_number')->isEmpty()
          ? $node->get('field_issue_number')->value : '',
        'module' => $node->hasField('field_dashboard_module') && !$node->get('field_dashboard_module')->isEmpty()
          ? $node->get('field_dashboard_module')->value : '',
        'assignee' => $node->hasField('field_issue_do_assignee') && !$node->get('field_issue_do_assignee')->isEmpty()
          ? $node->get('field_issue_do_assignee')->value : '',
        'track' => $node->hasField('field_issue_track') && !$node->get('field_issue_track')->isEmpty()
          ? $node->get('field_issue_track')->value : '',
        'workstream' => $node->hasField('field_issue_workstream') && !$node->get('field_issue_workstream')->isEmpty()
          ? $node->get('field_issue_workstream')->value : '',
        'tags' => [],
      ];

      // Get tags
      if ($node->hasField('field_issue_tags') && !$node->get('field_issue_tags')->isEmpty()) {
        foreach ($node->get('field_issue_tags')->getValue() as $v) {
          if (!empty($v['value'])) {
            $issue['tags'][] = $v['value'];
          }
        }
      }

      // Check for blocked status
      if ($node->hasField('field_issue_blocked_by') && !$node->get('field_issue_blocked_by')->isEmpty()) {
        $issue['is_blocked'] = TRUE;
        $issue['blocked_by'] = [];
        foreach ($node->get('field_issue_blocked_by')->getValue() as $blocker) {
          if (!empty($blocker['value'])) {
            $issue['blocked_by'][] = $blocker['value'];
          }
        }
      }

      // Get dates
      if ($node->hasField('field_checkin_date') && !$node->get('field_checkin_date')->isEmpty()) {
        $issue['checkin_date'] = $node->get('field_checkin_date')->value;
      }
      if ($node->hasField('field_due_date') && !$node->get('field_due_date')->isEmpty()) {
        $issue['due_date'] = $node->get('field_due_date')->value;
      }
      
      // Get update summary and strip HTML tags
      if ($node->hasField('field_update_summary') && !$node->get('field_update_summary')->isEmpty()) {
        $raw_summary = $node->get('field_update_summary')->value;
        // Strip HTML tags and decode entities
        $issue['update_summary'] = html_entity_decode(strip_tags($raw_summary), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $issue['update_summary'] = trim(preg_replace('/\s+/', ' ', $issue['update_summary']));
      }

      // Check if meta issue
      $issue['is_meta'] = FALSE;
      if ($node->hasField('field_is_meta_issue') && !$node->get('field_is_meta_issue')->isEmpty()) {
        $issue['is_meta'] = (bool) $node->get('field_is_meta_issue')->value;
      }

      $issues[] = $issue;
    }

    // Sort by weight
    usort($issues, function($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });

    return $issues;
  }

  /**
   * Load project metadata for issues.
   */
  private function loadProjectMeta($project_id) {
    $connection = Database::getConnection();
    $query = $connection->select('ai_dashboard_project_issue', 'p')
      ->fields('p', ['issue_nid', 'weight', 'indent_level', 'parent_issue_nid'])
      ->condition('project_nid', $project_id);
    
    $meta = [];
    foreach ($query->execute() as $row) {
      $meta[(int) $row->issue_nid] = [
        'weight' => (int) $row->weight,
        'indent' => isset($row->indent_level) ? (int) $row->indent_level : 0,
        'parent' => isset($row->parent_issue_nid) ? (int) $row->parent_issue_nid : NULL,
      ];
    }
    
    return $meta;
  }

  /**
   * Get filter options from issues.
   */
  private function getFilterOptions($issues) {
    $options = [
      'tags' => [],
      'priorities' => [],
      'statuses' => [],
      'tracks' => [],
      'workstreams' => [],
      'modules' => [],
    ];

    foreach ($issues as $issue) {
      // Collect unique tags
      foreach ($issue['tags'] as $tag) {
        $options['tags'][$tag] = $tag;
      }

      // Collect other options
      if (!empty($issue['priority'])) {
        $options['priorities'][$issue['priority']] = ucfirst($issue['priority']);
      }
      if (!empty($issue['status'])) {
        $options['statuses'][$issue['status']] = ucwords(str_replace('_', ' ', $issue['status']));
      }
      if (!empty($issue['track'])) {
        $options['tracks'][$issue['track']] = $issue['track'];
      }
      if (!empty($issue['workstream'])) {
        $options['workstreams'][$issue['workstream']] = $issue['workstream'];
      }
      if (!empty($issue['module'])) {
        $options['modules'][$issue['module']] = $issue['module'];
      }
    }

    // Sort options
    foreach ($options as &$option_list) {
      asort($option_list);
    }

    return $options;
  }

  /**
   * Recursively get all descendant issue NIDs across all projects.
   */
  private function getAllDescendantIssues($parent_nid, &$processed = []) {
    // Prevent infinite loops
    if (in_array($parent_nid, $processed)) {
      return [];
    }
    $processed[] = $parent_nid;

    // Get direct children from any project
    $connection = Database::getConnection();
    $query = $connection->select('ai_dashboard_project_issue', 'api')
      ->fields('api', ['issue_nid'])
      ->condition('parent_issue_nid', $parent_nid);

    $direct_children = $query->execute()->fetchCol();

    if (empty($direct_children)) {
      return [];
    }

    $all_descendants = $direct_children;

    // Recursively get descendants of each child
    foreach ($direct_children as $child_nid) {
      $grandchildren = $this->getAllDescendantIssues($child_nid, $processed);
      $all_descendants = array_merge($all_descendants, $grandchildren);
    }

    return array_unique($all_descendants);
  }

  /**
   * Title callback for the project issues page.
   */
  public function title(NodeInterface $node) {
    return $this->t('Manage Issues: @project', ['@project' => $node->label()]);
  }
}