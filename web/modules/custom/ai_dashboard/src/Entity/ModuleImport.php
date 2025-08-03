<?php

namespace Drupal\ai_dashboard\Entity;

use Drupal\Core\Config\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

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
 *     "status_filter",
 *     "max_issues",
 *     "date_filter",
 *     "active"
 *   },
 *   links = {
 *     "add-form" = "/admin/config/ai-dashboard/module-import/add",
 *     "edit-form" = "/admin/config/ai-dashboard/module-import/{module_import}",
 *     "delete-form" = "/admin/config/ai-dashboard/module-import/{module_import}/delete",
 *     "collection" = "/admin/config/ai-dashboard/module-import"
 *   }
 * )
 */
#[ConfigEntityType(
  id: "module_import",
  label: new TranslatableMarkup("Module import"),
  handlers: [
    "list_builder" => "Drupal\ai_dashboard\ModuleImportListBuilder",
    "form" => [
      "add" => "Drupal\ai_dashboard\Form\ModuleImportForm",
      "edit" => "Drupal\ai_dashboard\Form\ModuleImportForm",
      "delete" => "Drupal\Core\Entity\EntityDeleteForm",
    ]
  ],
  config_prefix: "module_import",
  admin_permission: "administer ai dashboard imports",
  entity_keys: [
    "id" => "id",
    "label" => "label",
    "uuid" => "uuid",
    "status" => "status"
  ],
  config_export: [
    "id",
    "label",
    "uuid",
    "status",
    "source_type",
    "project_id",
    "project_name",
    "filter_tags",
    "status_filter",
    "max_issues",
    "date_filter",
    "active"
  ],
  links: [
    "add-form" => "/admin/config/ai-dashboard/module-import/add",
    "edit-form" => "/admin/config/ai-dashboard/module-import/{module_import}",
    "delete-form" => "/admin/config/ai-dashboard/module-import/{module_import}/delete",
    "collection" => "/admin/config/ai-dashboard/module-import"
  ]
)]
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
  protected string $filter_tags;

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
    return array_filter(explode(',', $this->filter_tags));
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

}
