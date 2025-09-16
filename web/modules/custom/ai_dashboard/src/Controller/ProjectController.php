<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

class ProjectController extends ControllerBase {

  /**
   * Lists all projects.
   */
  public function list() {
    $storage = $this->entityTypeManager()->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'ai_project')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();
    
    $nodes = !empty($nids) ? $storage->loadMultiple($nids) : [];
    
    $projects = [];
    foreach ($nodes as $node) {
      // Generate URL-friendly name from title
      $project_slug = $this->generateSlug($node->label());
      
      // Get tags
      $tags = [];
      if ($node->hasField('field_project_tags') && !$node->get('field_project_tags')->isEmpty()) {
        foreach ($node->get('field_project_tags')->getValue() as $item) {
          if (!empty($item['value'])) {
            $tags[] = $item['value'];
          }
        }
      }
      
      // Check if this is the default kanban project
      $is_default = FALSE;
      if ($node->hasField('field_is_default_kanban_project') && !$node->get('field_is_default_kanban_project')->isEmpty()) {
        $is_default = (bool) $node->get('field_is_default_kanban_project')->value;
      }
      
      $projects[] = [
        'nid' => $node->id(),
        'title' => $node->label(),
        'slug' => $project_slug,
        'tags' => implode(', ', $tags),
        'is_default' => $is_default,
      ];
    }
    
    $build = [
      '#theme' => 'ai_projects_list',
      '#projects' => $projects,
      '#user_has_admin' => $this->currentUser()->hasPermission('administer ai dashboard content'),
      '#cache' => [
        'tags' => [
          'node_list:ai_project',
        ],
        'contexts' => [
          'user.permissions',
        ],
      ],
      '#attached' => [
        'library' => [
          'ai_dashboard/project_issues',
        ],
      ],
    ];
    
    return $build;
  }

  /**
   * Generate URL-friendly slug from title.
   */
  public function generateSlug($title) {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
  }

  /**
   * Load project node by slug.
   */
  public function loadProjectBySlug($slug) {
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
}