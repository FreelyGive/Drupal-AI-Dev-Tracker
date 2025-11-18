<?php

namespace Drupal\ai_dashboard\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Module import entity.
 *
 * @ConfigEntityType(
 *   id = "module_import",
 *   label = @Translation("Module import"),
 *   handlers = {
 *     "list_builder" = "Drupal\ai_dashboard\ModuleImportListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ai_dashboard\Form\ModuleImportForm",
 *       "edit" = "Drupal\ai_dashboard\Form\ModuleImportForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     }
 *   },
   *   config_prefix = "module_import",
 *   admin_permission = "administer ai dashboard imports",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "status" = "status"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "status",
 *     "source_type",
 *     "project_id",
 *     "project_name",
 *     "filter_tags",
 *     "filter_component",
 *     "status_filter",
 *     "max_issues",
 *     "date_filter",
 *     "active",
 *     "import_audiences"
   *   },
 *   links = {
 *     "add-form" = "/admin/config/ai-dashboard/module-import/add",
 *     "edit-form" = "/admin/config/ai-dashboard/module-import/{module_import}",
 *     "delete-form" = "/admin/config/ai-dashboard/module-import/{module_import}/delete",
 *     "collection" = "/admin/config/ai-dashboard/module-import"
 *   }
 * )
 */
class ModuleImport extends ConfigEntityBase {

  /**
   * The Module import ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The Module import label.
   *
   * @var string
   */
  protected $label;

  /**
   * The source type (drupal_org, gitlab, github).
   *
   * @var string
   */
  protected $source_type;

  /**
   * The project ID to import from.
   *
   * @var string
   */
  protected $project_id;

  /**
   * The project machine name to import from.
   *
   * @var string
   */
  protected $project_name;

  /**
   * Tags to filter by, comma separated
   *
   * @var string
   */
  protected string $filter_tags = '';

  /**
   * Component to filter by
   *
   * @var string
   */
  protected string $filter_component = '';

  /**
   * Status filter values.
   *
   * @var array
   */
  protected $status_filter = [];

  /**
   * Maximum number of issues to import.
   *
   * @var int|null
   */
  protected $max_issues;

  /**
   * Date filter for issue creation.
   *
   * @var string|null
   */
  protected $date_filter;

  /**
   * Whether this import configuration is active.
   *
   * @var bool
   */
  protected $active = TRUE;

  /**
   * Timestamp of when this configuration was last run.
   *
   * @var int|null
   */
  protected $last_run;

  

  /**
   * Selected audiences to assign to imported issues (e.g., dev, non_dev).
   *
   * @var array
   */
  protected array $import_audiences = [];

  /**
   * {@inheritdoc}
   */
  public function getSourceType() {
    return $this->source_type;
  }

  /**
   * {@inheritdoc}
   */
  public function setSourceType($source_type) {
    $this->source_type = $source_type;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectId() {
    return $this->project_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectMachineName() {
    return $this->project_name;
  }

  /**
   * {@inheritdoc}
   */
  public function setProjectId($project_id) {
    $this->project_id = $project_id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setProjectMachineName($project_name) {
    $this->project_name = $project_name;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterTags() : array {
    $tags = explode(',', $this->filter_tags);
    $tags = array_map('trim', $tags);
    $tags = array_filter($tags);
    return array_values($tags);
  }

  /**
   * {@inheritdoc}
   */
  public function setFilterTags($filter_tags) {
    $this->filter_tags = $filter_tags;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilterComponent() {
    return $this->filter_component ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setFilterComponent($filter_component) {
    $this->filter_component = $filter_component;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusFilter() {
    if (in_array('all_open', $this->status_filter)) {
      // Return statuses that match drupal.org's combined "open" filter
      // including "postponed" status.
      return ['1', '13', '8', '14', '15', '2', '4', '16'];
    }
    return $this->status_filter;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatusFilter(array $status_filter) {
    $this->status_filter = $status_filter;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxIssues() {
    return $this->max_issues;
  }

  /**
   * {@inheritdoc}
   */
  public function setMaxIssues($max_issues) {
    $this->max_issues = $max_issues;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDateFilter() {
    return $this->date_filter;
  }

  /**
   * {@inheritdoc}
   */
  public function setDateFilter($date_filter) {
    $this->date_filter = $date_filter;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isActive() {
    return $this->active;
  }

  /**
   * {@inheritdoc}
   */
  public function setActive($active) {
    $this->active = $active;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    $result = parent::save();
    
    // Clear configuration-related caches when entity is saved.
    $this->invalidateConfigurationCaches();
    
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    parent::delete();
    
    // Clear configuration-related caches when entity is deleted.
    $this->invalidateConfigurationCaches();
  }

  /**
   * Get the last run timestamp.
   *
   * @return int|null
   *   The timestamp when this configuration was last run, or NULL if never run.
   */
  public function getLastRun() {
    return $this->last_run;
  }

  /**
   * Set the last run timestamp.
   *
   * @param int|null $timestamp
   *   The timestamp when this configuration was last run.
   *
   * @return $this
   */
  public function setLastRun($timestamp) {
    $this->last_run = $timestamp;
    return $this;
  }

  

  /**
   * Get selected audiences for imported issues.
   */
  public function getImportAudiences(): array {
    return is_array($this->import_audiences) ? array_values(array_filter($this->import_audiences)) : [];
  }

  /**
   * Set selected audiences for imported issues.
   */
  public function setImportAudiences(array $values) {
    // Normalize to distinct values and known options.
    $allowed = ['dev', 'non_dev'];
    $values = array_values(array_unique(array_intersect($values, $allowed)));
    $this->import_audiences = $values;
    return $this;
  }

  /**
   * Invalidate caches related to configuration listings.
   */
  protected function invalidateConfigurationCaches() {
    $cache_tags = [
      'config:module_import_list',
      'module_import:' . $this->id(),
      'module_import_list',
    ];
    
    \Drupal::service('cache_tags.invalidator')->invalidateTags($cache_tags);
    
    // Also clear render cache for the listing page.
    \Drupal::service('cache.render')->deleteAll();
  }

}
