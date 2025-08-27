<?php

namespace Drupal\ai_dashboard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for mapping drupal.org tags to structured dashboard categories.
 */
class TagMappingService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Cached tag mappings.
   *
   * @var array
   */
  protected $mappings = [];

  /**
   * Whether mappings have been loaded.
   *
   * @var bool
   */
  protected $mappingsLoaded = FALSE;

  /**
   * Constructs a TagMappingService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, CacheBackendInterface $cache) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
  }

  /**
   * Maps a drupal.org tag to a structured value based on type.
   *
   * @param string $tag
   *   The original tag from drupal.org.
   * @param string $type
   *   The type of mapping (category, month, priority, etc.).
   *
   * @return string|null
   *   The mapped value or NULL if no mapping exists.
   */
  public function mapTag($tag, $type) {
    $this->loadMappings();

    $key = strtolower(trim($tag));
    if (isset($this->mappings[$type][$key])) {
      return $this->mappings[$type][$key];
    }

    return NULL;
  }

  /**
   * Maps an array of tags to their structured values for a specific type.
   *
   * @param array $tags
   *   Array of tags from drupal.org.
   * @param string $type
   *   The type of mapping (category, month, priority, etc.).
   *
   * @return array
   *   Array of mapped values.
   */
  public function mapTags(array $tags, $type) {
    $mapped = [];
    foreach ($tags as $tag) {
      $mapped_value = $this->mapTag($tag, $type);
      if ($mapped_value !== NULL) {
        $mapped[] = $mapped_value;
      }
    }
    return $mapped;
  }

  /**
   * Processes an array of tags and returns categorized mappings.
   *
   * @param array $tags
   *   Array of tags from drupal.org.
   *
   * @return array
   *   Array with keys for each mapping type containing mapped values.
   */
  public function processTags(array $tags) {
    $this->loadMappings();

    $result = [
      'category' => NULL,
      'month' => NULL,
      'priority' => NULL,
      'status' => NULL,
      'module' => NULL,
      'track' => NULL,
      'workstream' => NULL,
      'custom' => [],
    ];

    foreach ($tags as $tag) {
      $key = strtolower(trim($tag));

      // Check each mapping type.
      foreach ($this->mappings as $type => $type_mappings) {
        if (isset($type_mappings[$key])) {
          $mapped_value = $type_mappings[$key];

          if ($type === 'custom') {
            $result['custom'][] = $mapped_value;
          }
          else {
            // For single-value fields, take the first mapping found.
            if ($result[$type] === NULL) {
              $result[$type] = $mapped_value;
            }
          }
        }
      }
    }

    return $result;
  }

  /**
   * Gets all mappings for a specific type.
   *
   * @param string $type
   *   The mapping type.
   *
   * @return array
   *   Array of source_tag => mapped_value pairs.
   */
  public function getMappingsForType($type) {
    $this->loadMappings();
    return $this->mappings[$type] ?? [];
  }

  /**
   * Gets all available mapping types.
   *
   * @return array
   *   Array of mapping types.
   */
  public function getAvailableTypes() {
    return ['category', 'month', 'priority', 'status', 'module', 'track', 'workstream', 'custom'];
  }

  /**
   * Clears the mapping cache.
   */
  public function clearCache() {
    $this->cache->delete('ai_dashboard.tag_mappings');
    $this->mappings = [];
    $this->mappingsLoaded = FALSE;
  }

  /**
   * Loads tag mappings from the database.
   */
  protected function loadMappings() {
    if ($this->mappingsLoaded) {
      return;
    }

    // Try to load from cache first.
    $cache = $this->cache->get('ai_dashboard.tag_mappings');
    if ($cache && $cache->data) {
      $this->mappings = $cache->data;
      $this->mappingsLoaded = TRUE;
      return;
    }

    // Load from database.
    $this->mappings = [
      'category' => [],
      'month' => [],
      'priority' => [],
      'status' => [],
      'module' => [],
      'track' => [],
      'workstream' => [],
      'custom' => [],
    ];

    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      $mapping_ids = $node_storage->getQuery()
        ->condition('type', 'ai_tag_mapping')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($mapping_ids)) {
        $mappings = $node_storage->loadMultiple($mapping_ids);

        foreach ($mappings as $mapping) {
          if ($mapping->hasField('field_source_tag') && !$mapping->get('field_source_tag')->isEmpty() &&
              $mapping->hasField('field_mapping_type') && !$mapping->get('field_mapping_type')->isEmpty() &&
              $mapping->hasField('field_mapped_value') && !$mapping->get('field_mapped_value')->isEmpty()) {

            $source_tag = strtolower(trim($mapping->get('field_source_tag')->value));
            $mapping_type = $mapping->get('field_mapping_type')->value;
            $mapped_value = $mapping->get('field_mapped_value')->value;

            if (isset($this->mappings[$mapping_type])) {
              $this->mappings[$mapping_type][$source_tag] = $mapped_value;
            }
          }
        }
      }

      // Cache the mappings for 1 hour.
      $this->cache->set('ai_dashboard.tag_mappings', $this->mappings, time() + 3600);
      $this->mappingsLoaded = TRUE;

    }
    catch (\Exception $e) {
      \Drupal::logger('ai_dashboard')->error('Error loading tag mappings: @message', ['@message' => $e->getMessage()]);
      $this->mappingsLoaded = TRUE;
    }
  }

}
