<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Controller for burndown chart data.
 */
class BurndownController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database')
    );
  }

  /**
   * Get burndown chart data for a deliverable.
   */
  public function getBurndownData(NodeInterface $node) {
    // Ensure this is an AI Issue with deliverable tag
    if ($node->bundle() !== 'ai_issue') {
      return new JsonResponse(['error' => 'Not a valid deliverable'], 404);
    }

    // Get all child issues for this deliverable
    $child_issues = $this->getChildIssues($node->id());

    if (empty($child_issues)) {
      // No children, use the deliverable itself
      $child_issues = [$node->id()];
    }

    // Load all child nodes
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($child_issues);

    // Calculate burndown data
    $burndown_data = $this->calculateBurndown($nodes, $node);

    return new JsonResponse($burndown_data);
  }

  /**
   * Get all descendant issues for a deliverable (recursive).
   */
  private function getChildIssues($parent_nid, &$processed = []) {
    // Prevent infinite loops
    if (in_array($parent_nid, $processed)) {
      return [];
    }
    $processed[] = $parent_nid;

    // Get direct children
    $query = $this->database->select('ai_dashboard_project_issue', 'api')
      ->fields('api', ['issue_nid'])
      ->condition('parent_issue_nid', $parent_nid);

    $direct_children = $query->execute()->fetchCol();

    if (empty($direct_children)) {
      return [];
    }

    $all_descendants = $direct_children;

    // Recursively get descendants of each child
    foreach ($direct_children as $child_nid) {
      $grandchildren = $this->getChildIssues($child_nid, $processed);
      $all_descendants = array_merge($all_descendants, $grandchildren);
    }

    return array_unique($all_descendants);
  }

  /**
   * Calculate burndown chart data.
   */
  private function calculateBurndown($nodes, $deliverable) {
    // Filter out meta issues - they shouldn't count toward burndown
    $nodes = array_filter($nodes, function($node) {
      if ($node->hasField('field_is_meta_issue') && !$node->get('field_is_meta_issue')->isEmpty()) {
        return !$node->get('field_is_meta_issue')->value;
      }
      return TRUE;
    });
    // Re-index array after filtering
    $nodes = array_values($nodes);

    $total_issues = count($nodes);

    // Get due date from deliverable
    $due_date = NULL;
    if ($deliverable->hasField('field_due_date') && !$deliverable->get('field_due_date')->isEmpty()) {
      $due_date = strtotime($deliverable->get('field_due_date')->value);
    }

    // Get checkin date as potential start date
    $checkin_date = NULL;
    if ($deliverable->hasField('field_checkin_date') && !$deliverable->get('field_checkin_date')->isEmpty()) {
      $checkin_date = strtotime($deliverable->get('field_checkin_date')->value);
    }

    // Determine start date - use earliest of: checkin date, earliest issue creation, or 30 days ago
    $earliest_created = time();
    foreach ($nodes as $node) {
      $created = $node->getCreatedTime();
      if ($created < $earliest_created) {
        $earliest_created = $created;
      }
    }

    $thirty_days_ago = time() - (30 * 24 * 60 * 60);
    $start_date = $earliest_created;

    // Use checkin date if it's set and reasonable
    if ($checkin_date && $checkin_date > $thirty_days_ago && $checkin_date < time()) {
      $start_date = $checkin_date;
    } elseif ($earliest_created < $thirty_days_ago) {
      // If issues are very old, just show last 30 days
      $start_date = $thirty_days_ago;
    }

    // End date is either due date or 14 days from now
    $end_date = $due_date ?: time() + (14 * 24 * 60 * 60);

    // Collect completion events and status history
    $completion_events = [];
    $status_history = [];

    foreach ($nodes as $node) {
      $nid = $node->id();
      $created_time = $node->getCreatedTime();
      $changed_time = $node->getChangedTime();

      // Track when issue was created (starts as active)
      $status_history[] = [
        'time' => $created_time,
        'type' => 'created',
        'nid' => $nid,
      ];

      if ($node->hasField('field_issue_status') && !$node->get('field_issue_status')->isEmpty()) {
        $status = $node->get('field_issue_status')->value;

        if (in_array($status, ['fixed', 'closed', 'rtbc'])) {
          // Issue is completed - use changed time as completion time
          $completion_events[] = $changed_time;
          $status_history[] = [
            'time' => $changed_time,
            'type' => 'completed',
            'nid' => $nid,
            'status' => $status,
          ];
        }
      }
    }

    sort($completion_events);
    usort($status_history, function($a, $b) {
      return $a['time'] <=> $b['time'];
    });

    // Generate daily data points using status history
    $data_points = [];
    $current_date = $start_date;
    $day_increment = 24 * 60 * 60;

    // Track issues created and completed over time
    while ($current_date <= min($end_date, time())) {
      $created_count = 0;
      $completed_count = 0;

      // Count issues based on their status at this point in time
      foreach ($status_history as $event) {
        if ($event['time'] <= $current_date) {
          if ($event['type'] === 'created') {
            $created_count++;
          } elseif ($event['type'] === 'completed') {
            $completed_count++;
          }
        }
      }

      // Only include dates where we have created issues
      if ($created_count > 0) {
        $remaining = $created_count - $completed_count;
        $data_points[] = [
          'date' => date('Y-m-d', $current_date),
          'remaining' => $remaining,
          'completed' => $completed_count,
          'total' => $created_count,
        ];
      }

      $current_date += $day_increment;
    }

    // Add projection points if we have a future due date
    if ($due_date && $due_date > time()) {
      $current_date = time() + $day_increment;
      $last_remaining = end($data_points)['remaining'] ?? $total_issues;

      while ($current_date <= $due_date) {
        $data_points[] = [
          'date' => date('Y-m-d', $current_date),
          'remaining' => $last_remaining,
          'completed' => $total_issues - $last_remaining,
          'total' => $total_issues,
          'projected' => true,
        ];
        $current_date += $day_increment;
      }
    }

    // Calculate ideal burndown line
    $ideal_line = [];
    $total_days = max(1, ($end_date - $start_date) / $day_increment);
    $daily_burn = $total_issues / $total_days;

    $current_date = $start_date;
    $ideal_remaining = $total_issues;

    while ($current_date <= $end_date) {
      $ideal_line[] = [
        'date' => date('Y-m-d', $current_date),
        'remaining' => max(0, round($ideal_remaining, 1)),
      ];
      $ideal_remaining -= $daily_burn;
      $current_date += $day_increment;
    }

    // Calculate velocity and projected completion
    $recent_completions = 0;
    $one_week_ago = time() - (7 * 24 * 60 * 60);
    foreach ($completion_events as $event_time) {
      if ($event_time >= $one_week_ago) {
        $recent_completions++;
      }
    }

    $weekly_velocity = $recent_completions;
    $remaining_now = $total_issues - count($completion_events);
    $weeks_to_complete = $weekly_velocity > 0 ? $remaining_now / $weekly_velocity : NULL;
    $projected_completion = $weeks_to_complete ? date('Y-m-d', time() + ($weeks_to_complete * 7 * 24 * 60 * 60)) : NULL;

    return [
      'total' => $total_issues,
      'completed' => count($completion_events),
      'remaining' => $remaining_now,
      'actual' => $data_points,
      'ideal' => $ideal_line,
      'velocity' => [
        'weekly' => $weekly_velocity,
        'projected_completion' => $projected_completion,
      ],
      'dates' => [
        'start' => date('Y-m-d', $start_date),
        'due' => $due_date ? date('Y-m-d', $due_date) : NULL,
        'end' => date('Y-m-d', $end_date),
      ],
    ];
  }
}