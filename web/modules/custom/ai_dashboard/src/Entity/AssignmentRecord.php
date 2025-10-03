<?php

namespace Drupal\ai_dashboard\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines the Assignment Record entity.
 *
 * @ContentEntityType(
 *   id = "assignment_record",
 *   label = @Translation("Assignment Record"),
 *   base_table = "assignment_record",
 *   entity_keys = {
 *     "id" = "id",
 *   },
 *   handlers = {
 *     "list_builder" = "Drupal\ai_dashboard\AssignmentRecordListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ai_dashboard\Form\AssignmentRecordForm",
 *       "edit" = "Drupal\ai_dashboard\Form\AssignmentRecordForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "access" = "Drupal\ai_dashboard\AssignmentRecordAccessControlHandler",
 *   },
 *   admin_permission = "administer ai dashboard content",
 *   links = {
 *     "add-form" = "/admin/ai-dashboard/assignment-record/add",
 *     "edit-form" = "/admin/ai-dashboard/assignment-record/{assignment_record}/edit",
 *     "delete-form" = "/admin/ai-dashboard/assignment-record/{assignment_record}/delete",
 *     "collection" = "/admin/ai-dashboard/assignment-records",
 *   },
 * )
 */
class AssignmentRecord extends ContentEntityBase implements ContentEntityInterface {

  /**
   * Convert a DateTime to week_id (YYYYWW format).
   *
   * @param \DateTime $date
   *   The date to convert.
   *
   * @return int
   *   Week ID in YYYYWW format.
   */
  public static function dateToWeekId(\DateTime $date): int {
    // Clone to avoid modifying original
    $week_date = clone $date;
    $week_date->modify('Monday this week');
    
    $year = (int) $week_date->format('Y');
    $week = (int) $week_date->format('W');
    
    return ($year * 100) + $week;
  }

  /**
   * Convert week_id back to DateTime (Monday of that week).
   *
   * @param int $week_id
   *   Week ID in YYYYWW format.
   *
   * @return \DateTime
   *   DateTime object for Monday of that week.
   */
  public static function weekIdToDate(int $week_id): \DateTime {
    $year = floor($week_id / 100);
    $week = $week_id % 100;
    
    // Create DateTime for the Monday of the specified week
    $week_str = str_pad($week, 2, '0', STR_PAD_LEFT);
    return new \DateTime("{$year}-W{$week_str}-1");
  }

  /**
   * Get the current week ID.
   *
   * @return int
   *   Current week ID in YYYYWW format.
   */
  public static function getCurrentWeekId(): int {
    return self::dateToWeekId(new \DateTime());
  }

  /**
   * Load assignment records by properties.
   *
   * @param array $properties
   *   Properties to filter by.
   *
   * @return \Drupal\ai_dashboard\Entity\AssignmentRecord[]
   *   Array of assignment record entities.
   */
  public static function loadByProperties(array $properties): array {
    $storage = \Drupal::entityTypeManager()->getStorage('assignment_record');
    return $storage->loadByProperties($properties);
  }

  /**
   * Get all assignments for a specific week.
   *
   * @param int $week_id
   *   Week ID in YYYYWW format.
   *
   * @return \Drupal\ai_dashboard\Entity\AssignmentRecord[]
   *   Array of assignment records.
   */
  public static function getAssignmentsForWeek(int $week_id): array {
    return self::loadByProperties(['week_id' => $week_id]);
  }

  /**
   * Get all assignments for a specific assignee.
   *
   * @param int $assignee_id
   *   The assignee node ID.
   *
   * @return \Drupal\ai_dashboard\Entity\AssignmentRecord[]
   *   Array of assignment records.
   */
  public static function getAssignmentsForAssignee(int $assignee_id): array {
    return self::loadByProperties(['assignee_id' => $assignee_id]);
  }

  /**
   * Get all assignments for a specific issue.
   *
   * @param int $issue_id
   *   The issue node ID.
   *
   * @return \Drupal\ai_dashboard\Entity\AssignmentRecord[]
   *   Array of assignment records.
   */
  public static function getAssignmentsForIssue(int $issue_id): array {
    return self::loadByProperties(['issue_id' => $issue_id]);
  }

  /**
   * Check if a specific assignment already exists.
   *
   * @param int $issue_id
   *   The issue node ID.
   * @param int $assignee_id
   *   The assignee node ID.
   * @param int $week_id
   *   The week ID.
   *
   * @return bool
   *   TRUE if assignment exists, FALSE otherwise.
   */
  public static function assignmentExists(int $issue_id, int $assignee_id, int $week_id): bool {
    $existing = self::loadByProperties([
      'issue_id' => $issue_id,
      'assignee_id' => $assignee_id,
      'week_id' => $week_id,
    ]);
    return !empty($existing);
  }

  /**
   * Create a new assignment record.
   *
   * @param int $issue_id
   *   The issue node ID.
   * @param int $assignee_id
   *   The assignee node ID.
   * @param int $week_id
   *   The week ID.
   * @param string $source
   *   The source of assignment.
   * @param string $issue_status
   *   The issue status at time of assignment.
   *
   * @return \Drupal\ai_dashboard\Entity\AssignmentRecord|null
   *   The created assignment record or NULL if already exists.
   */
  public static function createAssignment(int $issue_id, int $assignee_id, int $week_id, string $source = 'manual', string $issue_status = 'active'): ?AssignmentRecord {
    // Don't create duplicate assignments
    if (self::assignmentExists($issue_id, $assignee_id, $week_id)) {
      return NULL;
    }

    $week_date = self::weekIdToDate($week_id);

    $assignment = self::create([
      'issue_id' => $issue_id,
      'assignee_id' => $assignee_id,
      'week_id' => $week_id,
      'week_date' => $week_date->format('Y-m-d'),
      'issue_status_at_assignment' => $issue_status,
      'assigned_date' => time(),
      'source' => $source,
    ]);

    $assignment->save();

    // Now populate username and organization if we have an assignee_id
    if ($assignee_id) {
      $database = \Drupal::database();
      $contributor = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($assignee_id);

      if ($contributor && $contributor->hasField('field_drupal_username')) {
        $username = $contributor->get('field_drupal_username')->value;

        // Update the record with username
        if ($username) {
          $database->update('assignment_record')
            ->fields(['assignee_username' => $username])
            ->condition('id', $assignment->id())
            ->execute();
        }
      }
    }

    return $assignment;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['issue_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Issue'))
      ->setDescription(t('The AI Issue this assignment refers to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler_settings', ['target_bundles' => ['ai_issue']])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 1,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ]);

    $fields['assignee_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Assignee'))
      ->setDescription(t('The contributor assigned to this issue.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler_settings', ['target_bundles' => ['ai_contributor']])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 2,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ]);

    $fields['week_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Week ID'))
      ->setDescription(t('Week identifier in YYYYWW format (e.g., 202401 for first week of 2024).'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 3,
      ]);

    $fields['week_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Week Date'))
      ->setDescription(t('Monday of the assignment week (for display and compatibility).'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => 4,
        'settings' => [
          'format_type' => 'medium',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 4,
      ]);

    $fields['issue_status_at_assignment'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Issue Status at Assignment'))
      ->setDescription(t('Snapshot of the issue status when this assignment was created.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 50)
      ->setDefaultValue('active')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'textfield',
        'weight' => 5,
      ]);

    $fields['assigned_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Assigned Date'))
      ->setDescription(t('When this assignment record was created.'))
      ->setRequired(TRUE)
      ->setDefaultValueCallback('time')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 6,
      ]);

    $fields['source'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Assignment Source'))
      ->setDescription(t('How this assignment was created.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'manual' => 'Manual',
        'drupal_org_sync' => 'Drupal.org Sync',
        'drag_drop' => 'Drag & Drop',
        'copy_week' => 'Copy from Previous Week',
        'batch_import' => 'Batch Import',
      ])
      ->setDefaultValue('manual')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 7,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 7,
      ]);

    return $fields;
  }

}