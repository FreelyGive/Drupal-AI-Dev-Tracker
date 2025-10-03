<?php

namespace Drupal\ai_dashboard\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_dashboard\Entity\AssignmentRecord;

/**
 * Form controller for Assignment Record edit forms.
 */
class AssignmentRecordForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ai_dashboard\Entity\AssignmentRecord $entity */
    $entity = $this->entity;

    // Pre-populate issue from query parameter if this is a new entity.
    if ($entity->isNew()) {
      $request = \Drupal::request();
      $issue_id = $request->query->get('issue_id');
      if ($issue_id && is_numeric($issue_id)) {
        $issue = \Drupal\node\Entity\Node::load($issue_id);
        if ($issue && $issue->bundle() === 'ai_issue') {
          $form['issue_id']['widget'][0]['target_id']['#default_value'] = $issue;
          
          // Also set current week as default.
          $current_week_id = AssignmentRecord::getCurrentWeekId();
          $form['week_id']['widget'][0]['value']['#default_value'] = $current_week_id;
          
          // Set week_date based on current week.
          $current_week_date = AssignmentRecord::weekIdToDate($current_week_id);
          $form['week_date']['widget'][0]['value']['#default_value'] = $current_week_date->format('Y-m-d');
        }
      }
    }

    // Add help text for week_id field.
    if (isset($form['week_id'])) {
      $form['week_id']['widget'][0]['value']['#description'] = $this->t('Format: YYYYWW (e.g., 202401 for first week of 2024, 202452 for last week of 2024). Current week: @current', [
        '@current' => AssignmentRecord::getCurrentWeekId(),
      ]);
    }

    // Auto-populate week_date when week_id changes.
    if (isset($form['week_date']) && isset($form['week_id'])) {
      $form['week_id']['widget'][0]['value']['#ajax'] = [
        'callback' => '::updateWeekDate',
        'wrapper' => 'week-date-wrapper',
      ];
      $form['week_date']['#prefix'] = '<div id="week-date-wrapper">';
      $form['week_date']['#suffix'] = '</div>';
    }

    // Add a note about assignment sources.
    $form['source']['widget']['#description'] = $this->t('How this assignment was created. Use "manual" for assignments created through this form.');

    // Add username and organization fields (read-only display)
    if (!$entity->isNew()) {
      $database = \Drupal::database();
      $record = $database->select('assignment_record', 'ar')
        ->fields('ar', ['assignee_username', 'assignee_organization'])
        ->condition('id', $entity->id())
        ->execute()
        ->fetchAssoc();

      if ($record) {
        // Display username if available
        if (!empty($record['assignee_username'])) {
          $form['assignee_username_display'] = [
            '#type' => 'item',
            '#title' => $this->t('Drupal.org Username'),
            '#markup' => $record['assignee_username'],
            '#weight' => 5,
          ];
        }

        // Display organization if available
        if (!empty($record['assignee_organization'])) {
          $form['assignee_organization_display'] = [
            '#type' => 'item',
            '#title' => $this->t('Organization (at time of assignment)'),
            '#markup' => $record['assignee_organization'],
            '#weight' => 6,
          ];
        }
      }
    }

    return $form;
  }

  /**
   * AJAX callback to update week_date based on week_id.
   */
  public function updateWeekDate(array &$form, FormStateInterface $form_state) {
    $week_id = $form_state->getValue(['week_id', 0, 'value']);
    
    if ($week_id && is_numeric($week_id)) {
      try {
        $week_date = AssignmentRecord::weekIdToDate((int) $week_id);
        $form['week_date']['widget'][0]['value']['#default_value'] = $week_date->format('Y-m-d');
      } catch (\Exception $e) {
        // Invalid week_id, leave week_date unchanged
      }
    }

    return $form['week_date'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $week_id = $form_state->getValue(['week_id', 0, 'value']);
    
    // Validate week_id format.
    if ($week_id && is_numeric($week_id)) {
      $week_id = (int) $week_id;
      $year = floor($week_id / 100);
      $week = $week_id % 100;
      
      if ($year < 2020 || $year > 2030) {
        $form_state->setErrorByName('week_id', $this->t('Year must be between 2020 and 2030.'));
      }
      
      if ($week < 1 || $week > 53) {
        $form_state->setErrorByName('week_id', $this->t('Week number must be between 1 and 53.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\ai_dashboard\Entity\AssignmentRecord $entity */
    $entity = $this->entity;

    // Auto-update week_date based on week_id.
    $week_id = $entity->get('week_id')->value;
    if ($week_id) {
      try {
        $week_date = AssignmentRecord::weekIdToDate((int) $week_id);
        $entity->set('week_date', $week_date->format('Y-m-d'));
      } catch (\Exception $e) {
        // Log error but don't fail save
        \Drupal::logger('ai_dashboard')->warning('Invalid week_id @week_id when saving assignment record.', ['@week_id' => $week_id]);
      }
    }

    $result = parent::save($form, $form_state);

    $link = $entity->toLink($this->t('View'))->toRenderable();

    $message_arguments = ['%label' => $this->entity->label()];
    $logger_arguments = [
      '%label' => $entity->label(),
      'link' => \Drupal::service('renderer')->render($link),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addMessage($this->t('Created new assignment record %label.', $message_arguments));
        $this->logger('ai_dashboard')->notice('Created new assignment record %label', $logger_arguments);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addMessage($this->t('Updated assignment record %label.', $message_arguments));
        $this->logger('ai_dashboard')->notice('Updated assignment record %label.', $logger_arguments);
        break;
    }

    $form_state->setRedirect('entity.assignment_record.collection');
    return $result;
  }

}