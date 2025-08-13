<?php

namespace Drupal\ai_dashboard;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\ai_dashboard\Entity\AssignmentRecord;

/**
 * Defines a class to build a listing of Assignment Record entities.
 */
class AssignmentRecordListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('ID');
    $header['issue'] = $this->t('Issue');
    $header['assignee'] = $this->t('Assignee');
    $header['week_id'] = $this->t('Week ID');
    $header['week_date'] = $this->t('Week Date');
    $header['status'] = $this->t('Issue Status');
    $header['source'] = $this->t('Source');
    $header['assigned_date'] = $this->t('Assigned');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ai_dashboard\Entity\AssignmentRecord $entity */
    $row['id'] = $entity->id();
    
    // Issue link
    $issue = $entity->get('issue_id')->entity;
    if ($issue) {
      $row['issue'] = Link::createFromRoute(
        $issue->getTitle(),
        'entity.node.canonical',
        ['node' => $issue->id()]
      );
    } else {
      $row['issue'] = $this->t('Missing Issue');
    }

    // Assignee link  
    $assignee = $entity->get('assignee_id')->entity;
    if ($assignee) {
      $row['assignee'] = Link::createFromRoute(
        $assignee->getTitle(),
        'entity.node.canonical',
        ['node' => $assignee->id()]
      );
    } else {
      $row['assignee'] = $this->t('Missing Assignee');
    }

    $row['week_id'] = $entity->get('week_id')->value;
    
    // Format week date nicely
    $week_date = $entity->get('week_date')->date;
    if ($week_date) {
      $row['week_date'] = $week_date->format('M j, Y');
    } else {
      $row['week_date'] = '';
    }

    $row['status'] = $entity->get('issue_status_at_assignment')->value;
    $row['source'] = $entity->get('source')->value;
    
    // Format assigned date
    $assigned_date = $entity->get('assigned_date')->value;
    if ($assigned_date) {
      $row['assigned_date'] = \Drupal::service('date.formatter')
        ->format($assigned_date, 'short');
    } else {
      $row['assigned_date'] = '';
    }

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    
    // Add view operation
    if ($entity->access('view') && $entity->hasLinkTemplate('canonical')) {
      $operations['view'] = [
        'title' => $this->t('View'),
        'weight' => 0,
        'url' => $entity->toUrl(),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds() {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('assigned_date', 'DESC');

    // Only add the pager if a limit is specified.
    if ($this->limit) {
      $query->pager($this->limit);
    }

    return $query->execute();
  }

}