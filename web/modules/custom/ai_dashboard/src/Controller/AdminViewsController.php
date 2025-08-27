<?php

namespace Drupal\ai_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for admin views with navigation.
 */
class AdminViewsController extends ControllerBase {

  /**
   * Display contributors admin page with navigation.
   */
  public function contributorsAdmin() {
    $build = [];

    // Add admin navigation.
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('contributors');

    // Load and render the view.
    try {
      $view = Views::getView('ai_contributors_admin');
      if ($view && $view->access('page_1')) {
        $view->setDisplay('page_1');
        $view->preExecute();
        $view->execute();
        $build['view'] = $view->buildRenderable('page_1');
      }
      else {
        $build['error'] = [
          '#markup' => '<div class="messages messages--error">Contributors view not found or access denied.</div>',
        ];
      }
    }
    catch (\Exception $e) {
      $build['error'] = [
        '#markup' => '<div class="messages messages--error">Error loading contributors view: ' . $e->getMessage() . '</div>',
      ];
    }

    return $build;
  }

  /**
   * Display issues admin page with navigation.
   */
  public function issuesAdmin() {
    $build = [];

    // Add admin navigation.
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('issues');

    // Load and render the view.
    try {
      $view = Views::getView('ai_issues_admin');
      if ($view && $view->access('page_1')) {
        $view->setDisplay('page_1');
        $view->preExecute();
        $view->execute();
        $build['view'] = $view->buildRenderable('page_1');
      }
      else {
        $build['error'] = [
          '#markup' => '<div class="messages messages--error">Issues view not found or access denied.</div>',
        ];
      }
    }
    catch (\Exception $e) {
      $build['error'] = [
        '#markup' => '<div class="messages messages--error">Error loading issues view: ' . $e->getMessage() . '</div>',
      ];
    }

    return $build;
  }

  /**
   * Display tag mappings admin page with navigation.
   */
  public function tagMappingsAdmin() {
    $build = [];

    // Add admin navigation.
    $admin_tools_controller = \Drupal::service('class_resolver')->getInstanceFromDefinition(AdminToolsController::class);
    $build['navigation'] = $admin_tools_controller->buildAdminNavigation('tag_mappings');

    // Build the main tag mapping interface
    $build['tag_mapping_interface'] = $this->buildTagMappingInterface();

    // Add cache tags so this page can be invalidated when mappings change
    $build['#cache']['tags'][] = 'ai_dashboard:tag_mappings';

    return $build;
  }

  /**
   * Build the main tag mapping interface.
   */
  protected function buildTagMappingInterface() {
    // Get all tags from issues
    $all_tags = $this->getAllIssueTags();
    
    if (empty($all_tags)) {
      return [
        '#markup' => '<div class="messages messages--warning">
          <h3>No Tags Found</h3>
          <p>No tags found in your AI Issues. Please import some issues first to see available tags for mapping.</p>
        </div>',
      ];
    }

    // Get existing mappings
    $existing_mappings = $this->getExistingMappings();
    
    // Prepare tag data
    $tag_data = [];
    foreach ($all_tags as $tag) {
      $normalized_tag = strtolower(trim($tag));
      $usage_count = $this->getTagUsageCount($tag);
      
      // Check existing mappings for this tag
      $track_mapping = null;
      $workstream_mapping = null;
      
      foreach ($existing_mappings as $source_tag => $mapping) {
        if (strtolower(trim($source_tag)) === $normalized_tag) {
          $type = $mapping->get('field_mapping_type')->value;
          if ($type === 'track') {
            $track_mapping = $mapping->get('field_mapped_value')->value;
          } elseif ($type === 'workstream') {
            $workstream_mapping = $mapping->get('field_mapped_value')->value;
          }
        }
      }
      
      $tag_data[] = [
        'tag' => $tag,
        'normalized' => $normalized_tag,
        'usage_count' => $usage_count,
        'track_mapping' => $track_mapping,
        'workstream_mapping' => $workstream_mapping,
      ];
    }
    
    // Sort by usage count (most used first)
    usort($tag_data, function($a, $b) {
      return $b['usage_count'] - $a['usage_count'];
    });

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tag-mapping-interface']],
    ];

    $build['header'] = [
      '#markup' => '<div class="interface-header">
        <h2>Tag Mappings</h2>
        <p>Map your issue tags to <strong>Tracks</strong> (project areas) or <strong>Workstreams</strong> (workflow phases). 
        Enter the tag name itself as the mapping value, or customize as needed.</p>
      </div>',
    ];

    // Add bulk update button
    $build['bulk_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bulk-actions']],
    ];

    $build['bulk_actions']['update_all'] = [
      '#type' => 'button',
      '#value' => 'Apply Current Mappings to All Existing Issues',
      '#attributes' => [
        'class' => ['button', 'button--primary', 'update-all-mappings-btn'],
        'id' => 'update-all-mappings',
      ],
    ];

    $build['bulk_actions']['description'] = [
      '#markup' => '<p class="bulk-action-desc">Use this to update all existing AI Issues with your current tag mappings. Useful after creating new mappings or when syncing with drupal.org.</p>',
    ];

    $config = \Drupal::config('ai_dashboard.ignored_tags');
    $ignored_tags = $config->get('tags') ?: [];
    
    $track_count = count(array_filter($tag_data, function($item) { return !empty($item['track_mapping']); }));
    $workstream_count = count(array_filter($tag_data, function($item) { return !empty($item['workstream_mapping']); }));
    $unmapped_count = count(array_filter($tag_data, function($item) { return empty($item['track_mapping']) && empty($item['workstream_mapping']); }));
    
    $build['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mapping-stats']],
    ];
    
    $build['stats']['all'] = [
      '#type' => 'button',
      '#value' => count($tag_data) . ' Total Tags',
      '#attributes' => [
        'class' => ['stat', 'total', 'filter-btn', 'active'],
        'data-filter' => 'all',
      ],
    ];
    
    $build['stats']['track'] = [
      '#type' => 'button',
      '#value' => $track_count . ' Track Mappings',
      '#attributes' => [
        'class' => ['stat', 'track', 'filter-btn'],
        'data-filter' => 'track',
      ],
    ];
    
    $build['stats']['workstream'] = [
      '#type' => 'button',
      '#value' => $workstream_count . ' Workstream Mappings',
      '#attributes' => [
        'class' => ['stat', 'workstream', 'filter-btn'],
        'data-filter' => 'workstream',
      ],
    ];
    
    $build['stats']['unmapped'] = [
      '#type' => 'button',
      '#value' => $unmapped_count . ' Unmapped Tags',
      '#attributes' => [
        'class' => ['stat', 'unmapped', 'filter-btn'],
        'data-filter' => 'unmapped',
      ],
    ];
    
    $build['stats']['ignored'] = [
      '#type' => 'button',
      '#value' => count($ignored_tags) . ' Ignored Tags',
      '#attributes' => [
        'class' => ['stat', 'ignored', 'filter-btn'],
        'data-filter' => 'ignored',
      ],
    ];

    // Add ignored tags management if there are ignored tags
    if (!empty($ignored_tags)) {
      $build['ignored_management'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ignored-tags-management']],
      ];
      
      $build['ignored_management']['toggle'] = [
        '#type' => 'button',
        '#value' => 'Show Ignored Tags (' . count($ignored_tags) . ')',
        '#attributes' => [
          'class' => ['toggle-ignored-btn'],
          'id' => 'toggle-ignored-tags',
        ],
      ];
      
      $build['ignored_management']['list'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ignored-tags-list'],
          'id' => 'ignored-tags-list',
          'style' => 'display: none;',
        ],
      ];
      
      $build['ignored_management']['list']['header'] = [
        '#markup' => '<h3>Ignored Tags</h3>',
      ];
      
      foreach ($ignored_tags as $index => $ignored_tag) {
        $build['ignored_management']['list']['item_' . $index] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ignored-tag-item']],
        ];
        
        $build['ignored_management']['list']['item_' . $index]['name'] = [
          '#markup' => '<span class="ignored-tag-name">' . htmlspecialchars($ignored_tag) . '</span>',
        ];
        
        $build['ignored_management']['list']['item_' . $index]['restore'] = [
          '#type' => 'button',
          '#value' => 'Restore',
          '#attributes' => [
            'class' => ['restore-tag-btn'],
            'data-tag' => strtolower(trim($ignored_tag)),
          ],
        ];
      }
    }

    // Build the tag list
    $tag_items = [];
    foreach ($tag_data as $item) {
      $tag_items[] = $this->buildTagMappingRow($item);
    }

    $build['tag_list'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tag-mapping-list']],
      'items' => $tag_items,
    ];

    $build['#attached']['library'][] = 'ai_dashboard/tag-mapping';
    $build['#cache']['tags'][] = 'ai_dashboard:tag_mappings';
    $build['#cache']['contexts'][] = 'url';
    
    return $build;
  }

  /**
   * Build a single tag mapping row.
   */
  protected function buildTagMappingRow($item) {
    $row = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['tag-mapping-row'],
        'data-tag' => $item['normalized'],
      ],
    ];

    $row['tag_info'] = [
      '#markup' => sprintf(
        '<div class="tag-info">
          <span class="tag-name">%s</span>
          <span class="usage-count">%d issue%s</span>
        </div>',
        htmlspecialchars($item['tag']),
        $item['usage_count'],
        $item['usage_count'] === 1 ? '' : 's'
      ),
    ];

    $row['mappings'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tag-mappings']],
    ];

    // Track mapping
    if ($item['track_mapping']) {
      $row['mappings']['track'] = [
        '#markup' => sprintf(
          '<div class="mapping-display track">
            <label>Track:</label>
            <span class="mapped-value">%s</span>
          </div>',
          htmlspecialchars($item['track_mapping'])
        ),
      ];
      
      $row['mappings']['track_actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mapping-actions']],
      ];
      
      $row['mappings']['track_actions']['edit'] = [
        '#type' => 'button',
        '#value' => 'Edit',
        '#attributes' => [
          'class' => ['edit-mapping-btn', 'track-edit-btn'],
          'data-type' => 'track',
          'data-tag' => $item['normalized'],
          'data-current' => $item['track_mapping'],
        ],
      ];
      
      $row['mappings']['track_actions']['remove'] = [
        '#type' => 'button',
        '#value' => 'Remove',
        '#attributes' => [
          'class' => ['remove-mapping-btn', 'track-remove-btn'],
          'data-type' => 'track',
          'data-tag' => $item['normalized'],
        ],
      ];
    } else {
      $row['mappings']['track'] = [
        '#type' => 'button',
        '#value' => 'Map "' . $item['tag'] . '" to Track',
        '#attributes' => [
          'class' => ['quick-map-btn', 'track-btn'],
          'data-type' => 'track',
          'data-tag' => $item['normalized'],
          'data-value' => $item['tag'],
        ],
      ];
    }

    // Workstream mapping
    if ($item['workstream_mapping']) {
      $row['mappings']['workstream'] = [
        '#markup' => sprintf(
          '<div class="mapping-display workstream">
            <label>Workstream:</label>
            <span class="mapped-value">%s</span>
          </div>',
          htmlspecialchars($item['workstream_mapping'])
        ),
      ];
      
      $row['mappings']['workstream_actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mapping-actions']],
      ];
      
      $row['mappings']['workstream_actions']['edit'] = [
        '#type' => 'button',
        '#value' => 'Edit',
        '#attributes' => [
          'class' => ['edit-mapping-btn', 'workstream-edit-btn'],
          'data-type' => 'workstream',
          'data-tag' => $item['normalized'],
          'data-current' => $item['workstream_mapping'],
        ],
      ];
      
      $row['mappings']['workstream_actions']['remove'] = [
        '#type' => 'button',
        '#value' => 'Remove',
        '#attributes' => [
          'class' => ['remove-mapping-btn', 'workstream-remove-btn'],
          'data-type' => 'workstream',
          'data-tag' => $item['normalized'],
        ],
      ];
    } else {
      $row['mappings']['workstream'] = [
        '#type' => 'button',
        '#value' => 'Map "' . $item['tag'] . '" to Workstream',
        '#attributes' => [
          'class' => ['quick-map-btn', 'workstream-btn'],
          'data-type' => 'workstream',
          'data-tag' => $item['normalized'],
          'data-value' => $item['tag'],
        ],
      ];
    }

    // Add ignore button
    $row['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tag-actions']],
    ];
    
    $row['actions']['ignore'] = [
      '#type' => 'button',
      '#value' => 'Ignore',
      '#attributes' => [
        'class' => ['ignore-tag-btn'],
        'data-tag' => $item['normalized'],
      ],
    ];

    return $row;
  }

  /**
   * Get usage count for a specific tag.
   */
  protected function getTagUsageCount($tag) {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_tags', '%' . $tag . '%', 'LIKE')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    
    return $query->count()->execute();
  }

  /**
   * Build tag analysis summary for the tag mappings page.
   */
  protected function buildTagAnalysisSummary() {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tag-analysis-summary']],
    ];

    // Get all unique tags from issues
    $all_tags = $this->getAllIssueTags();
    
    if (empty($all_tags)) {
      $build['no_tags'] = [
        '#markup' => '<div class="messages messages--warning">No tags found in AI Issues. Import some issues first to see tag analysis.</div>',
      ];
      return $build;
    }

    // Get existing mappings
    $existing_mappings = $this->getExistingMappings();
    $mapped_tags = array_keys($existing_mappings);
    $unmapped_tags = array_diff($all_tags, $mapped_tags);

    // Build summary
    $build['header'] = [
      '#markup' => '<h3>Tag Usage Analysis</h3>',
    ];

    $build['stats'] = [
      '#markup' => sprintf(
        '<div class="tag-stats"><span class="stat total">%d Total Tags</span><span class="stat mapped">%d Mapped</span><span class="stat unmapped">%d Unmapped</span></div>',
        count($all_tags),
        count($mapped_tags), 
        count($unmapped_tags)
      ),
    ];

    if (!empty($unmapped_tags)) {
      $most_used_unmapped = $this->getMostUsedTags($unmapped_tags, 10);
      
      $build['unmapped_section'] = [
        '#markup' => '<h4>Most Used Unmapped Tags</h4><p><em>These tags appear in your issues but don\'t have mappings yet. Create mappings below to categorize them into Tracks and Workstreams.</em></p>',
      ];

      $items = [];
      foreach ($most_used_unmapped as $tag => $count) {
        $items[] = [
          '#markup' => sprintf(
            '<strong>%s</strong> <span class="usage-count">(%d issue%s)</span> <a href="/node/add/ai_tag_mapping?field_source_tag=%s" class="quick-map-link">+ Create Mapping</a>',
            htmlspecialchars($tag),
            $count,
            $count === 1 ? '' : 's',
            urlencode(strtolower($tag))
          ),
        ];
      }

      $build['unmapped_list'] = [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['unmapped-tags-list']],
      ];
    }

    $build['#attached']['library'][] = 'ai_dashboard/tag-analysis';

    return $build;
  }

  /**
   * Get all unique tags from AI issues.
   */
  protected function getAllIssueTags() {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    
    $issue_ids = $query->execute();
    
    if (empty($issue_ids)) {
      return [];
    }
    
    $issues = $node_storage->loadMultiple($issue_ids);
    $all_tags = [];
    
    foreach ($issues as $issue) {
      if ($issue->hasField('field_issue_tags') && !$issue->get('field_issue_tags')->isEmpty()) {
        // Handle multi-value field - get all field values
        $tag_field_values = $issue->get('field_issue_tags')->getValue();
        foreach ($tag_field_values as $tag_value) {
          if (!empty($tag_value['value'])) {
            $tag = trim($tag_value['value']);
            // Also handle comma-separated values within individual field values (legacy support)
            if (strpos($tag, ',') !== false) {
              $split_tags = array_map('trim', explode(',', $tag));
              $all_tags = array_merge($all_tags, $split_tags);
            } else {
              $all_tags[] = $tag;
            }
          }
        }
      }
    }
    
    $unique_tags = array_unique(array_filter($all_tags));
    
    // Filter out ignored tags
    $config = \Drupal::config('ai_dashboard.ignored_tags');
    $ignored_tags = $config->get('tags') ?: [];
    
    if (!empty($ignored_tags)) {
      $unique_tags = array_filter($unique_tags, function($tag) use ($ignored_tags) {
        return !in_array(strtolower(trim($tag)), array_map('strtolower', $ignored_tags));
      });
    }
    
    return $unique_tags;
  }

  /**
   * Get existing tag mappings.
   */
  protected function getExistingMappings() {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    
    $query = $node_storage->getQuery()
      ->condition('type', 'ai_tag_mapping')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    
    $mapping_ids = $query->execute();
    
    if (empty($mapping_ids)) {
      return [];
    }
    
    $mappings = $node_storage->loadMultiple($mapping_ids);
    $mapping_data = [];
    
    foreach ($mappings as $mapping) {
      if ($mapping->hasField('field_source_tag') && !$mapping->get('field_source_tag')->isEmpty()) {
        $source_tag = trim($mapping->get('field_source_tag')->value);
        $mapping_data[$source_tag] = $mapping;
      }
    }
    
    return $mapping_data;
  }

  /**
   * Get most used tags from a list.
   */
  protected function getMostUsedTags(array $tags, $limit = 10) {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $tag_counts = [];
    
    foreach ($tags as $tag) {
      $query = $node_storage->getQuery()
        ->condition('type', 'ai_issue')
        ->condition('field_issue_tags', '%' . $tag . '%', 'LIKE')
        ->condition('status', 1)
        ->accessCheck(FALSE);
      
      $count = $query->count()->execute();
      $tag_counts[$tag] = $count;
    }
    
    // Sort by usage count (highest first)
    arsort($tag_counts);
    
    return array_slice($tag_counts, 0, $limit, TRUE);
  }

  /**
   * AJAX endpoint for saving tag mappings.
   */
  public function saveTagMapping(Request $request) {
    // Get JSON data from request
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    
    if (!$data || !isset($data['source_tag']) || !isset($data['mapping_type']) || !isset($data['mapped_value'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request data',
      ], 400);
    }

    $source_tag = trim($data['source_tag']);
    $mapping_type = trim($data['mapping_type']);
    $mapped_value = trim($data['mapped_value']);

    // Validate mapping type
    if (!in_array($mapping_type, ['track', 'workstream'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid mapping type',
      ], 400);
    }

    try {
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      
      // Check if mapping already exists
      $query = $node_storage->getQuery()
        ->condition('type', 'ai_tag_mapping')
        ->condition('field_source_tag', $source_tag)
        ->condition('field_mapping_type', $mapping_type)
        ->accessCheck(FALSE);
      
      $existing_ids = $query->execute();
      
      if (!empty($existing_ids)) {
        // Update existing mapping
        $existing_id = reset($existing_ids);
        $mapping_node = $node_storage->load($existing_id);
        $mapping_node->set('field_mapped_value', $mapped_value);
        $mapping_node->save();
      } else {
        // Create new mapping
        $mapping_node = $node_storage->create([
          'type' => 'ai_tag_mapping',
          'title' => $source_tag . ' → ' . $mapping_type,
          'field_source_tag' => $source_tag,
          'field_mapping_type' => $mapping_type,
          'field_mapped_value' => $mapped_value,
          'status' => 1,
        ]);
        $mapping_node->save();
      }
      
      // Invalidate cache to ensure the interface updates
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['ai_dashboard:tag_mappings']);
      
      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Mapping saved successfully',
        'mapping' => [
          'source_tag' => $source_tag,
          'mapping_type' => $mapping_type,
          'mapped_value' => $mapped_value,
        ],
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Error saving tag mapping: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to save mapping: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * AJAX endpoint for ignoring tags.
   */
  public function ignoreTag(Request $request) {
    // Get JSON data from request
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    
    if (!$data || !isset($data['tag'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request data',
      ], 400);
    }

    $tag = trim($data['tag']);
    
    try {
      // Get current ignored tags from configuration
      $config = \Drupal::configFactory()->getEditable('ai_dashboard.ignored_tags');
      $ignored_tags = $config->get('tags') ?: [];
      
      // Add the tag to ignored list if not already there
      if (!in_array($tag, $ignored_tags)) {
        $ignored_tags[] = $tag;
        $config->set('tags', $ignored_tags)->save();
      }
      
      // Invalidate cache to ensure the interface updates
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['ai_dashboard:tag_mappings']);
      
      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Tag ignored successfully',
        'tag' => $tag,
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Error ignoring tag: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to ignore tag: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * AJAX endpoint for restoring ignored tags.
   */
  public function restoreTag(Request $request) {
    // Get JSON data from request
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    
    if (!$data || !isset($data['tag'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request data',
      ], 400);
    }

    $tag = trim($data['tag']);
    
    try {
      // Get current ignored tags from configuration
      $config = \Drupal::configFactory()->getEditable('ai_dashboard.ignored_tags');
      $ignored_tags = $config->get('tags') ?: [];
      
      // Remove the tag from ignored list
      $updated_ignored_tags = array_filter($ignored_tags, function($ignored_tag) use ($tag) {
        return strtolower(trim($ignored_tag)) !== strtolower($tag);
      });
      
      // Reset array keys and save
      $updated_ignored_tags = array_values($updated_ignored_tags);
      $config->set('tags', $updated_ignored_tags)->save();
      
      // Invalidate cache to ensure the interface updates
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['ai_dashboard:tag_mappings']);
      
      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Tag restored successfully',
        'tag' => $tag,
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Error restoring tag: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to restore tag: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * AJAX endpoint for removing tag mappings.
   */
  public function removeTagMapping(Request $request) {
    // Get JSON data from request
    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    
    if (!$data || !isset($data['source_tag']) || !isset($data['mapping_type'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request data',
      ], 400);
    }

    $source_tag = trim($data['source_tag']);
    $mapping_type = trim($data['mapping_type']);

    // Validate mapping type
    if (!in_array($mapping_type, ['track', 'workstream'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid mapping type',
      ], 400);
    }

    try {
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      
      // Find and delete the mapping
      $query = $node_storage->getQuery()
        ->condition('type', 'ai_tag_mapping')
        ->condition('field_source_tag', $source_tag)
        ->condition('field_mapping_type', $mapping_type)
        ->accessCheck(FALSE);
      
      $mapping_ids = $query->execute();
      
      if (empty($mapping_ids)) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Mapping not found',
        ], 404);
      }
      
      // Delete all matching mappings
      $mappings = $node_storage->loadMultiple($mapping_ids);
      foreach ($mappings as $mapping) {
        $mapping->delete();
      }
      
      // Invalidate cache to ensure the interface updates
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['ai_dashboard:tag_mappings']);
      
      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Mapping removed successfully',
        'removed' => [
          'source_tag' => $source_tag,
          'mapping_type' => $mapping_type,
        ],
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Error removing tag mapping: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to remove mapping: ' . $e->getMessage(),
      ], 500);
    }
  }

}
