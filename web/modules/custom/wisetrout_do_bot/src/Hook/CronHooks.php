<?php

namespace Drupal\wisetrout_do_bot\Hook;

use Drupal\Core\Entity\EntityEvents;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for wisetrout_do_bot.
 */
class CronHooks {

  /**
   * Reacts to cron.
   */
  #[Hook('cron')]
  public function onCron(){
    $current_time = \Drupal::time()->getRequestTime();
    $last_run = \Drupal::state()->get('wisetrout_do_bot.cron_last_run', 0);

    // Check if today is a different day than the last run.
    if (date('Y-m-d', $current_time) !== date('Y-m-d', $last_run)) {

        $chatIds = $this->getActiveDailySubscriberIds();
        $updates = $this->getIssueUpdatesData();
        $subscriptions = $this->getDailySubscriptions($chatIds);
        $newModules = $this->getnewModules();

        $moduleSummaryNotifications = $this->createModuleSummaryNotifications($subscriptions, $updates);
        $moduleCreationNotifications = $this->createModuleCreationNotifications($chatIds, $newModules);
    
        // Fetch the queue service.
        $queue = \Drupal::queue('telegram_bot_queue');
        
        $items_to_process = array_merge($moduleCreationNotifications, $moduleCreationNotifications);

        foreach ($items_to_process as $item_data) {
        // Create an item in the queue.
        $queue->createItem($item_data);
    }

    // Update the last run time.
    \Drupal::state()->set('wisetrout_do_bot.cron_last_run', $current_time);
    }
  }

  protected function getActiveDailySubscriberIds(){
    return \Drupal::database()
    ->query("SELECT chat_id FROM {telegram_subscribers} WHERE status = 1 AND type = 'daily'")
    ->fetchCol();
  }

  protected function getIssueUpdatesData(){

    $createdIssuesData = $this->getCreatedIssuesData();
    $updatedIssuesData = $this->getUpdatedIssuesData();

    return array_merge($updatedIssuesData, $createdIssuesData);

  }

  protected function getDailySubscriptions($chatIds){
    return \Drupal::database()
    ->query("SELECT chat_id, module_id FROM {telegram_subscriptions} WHERE chat_id IN (:chat_ids[])", [
        ':chat_ids[]' => $chatIds,
    ])
    ->fetchAllAssoc();
  }

  protected function getNewModules(){
    $yesterday = strtotime('-1 day');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $createdIds = \Drupal::entityQuery('node')
    ->condition('type', 'ai_module')
    ->condition('created', $yesterday, '>=')
    ->accessCheck(FALSE)
    ->execute();

    $createdModuleNodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
    $createdModuleNames = array_map(function($node){
        return $node->field_module_machine_name[0]->value;
    }, $createdModuleNodes);
    return $createdModuleNames;
  }

  protected function createModuleSummaryNotifications($subscriptions, $updates){
    $notifications = [];
    
  }

  protected function createModuleCreationNotifications(){}

  protected function getCreatedIssuesData(){
    $yesterday = strtotime('-1 day');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $createdIds = \Drupal::entityQuery('node')
    ->condition('type', 'ai_issue')
    ->condition('created', $yesterday, '>=')
    ->accessCheck(FALSE)
    ->execute();

    $data = [];
 
    $createdIssueNodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);

    $data = array_map(function($issueNode){
        return [
            'id' => $issueNode->id(),
            'name' => $issueNode->label(),
            'module' => $issueNode->->get('field_issue_module')->entity->id(),
            'action' => 'created',
        ];
    }, $createdIssueNodes);

    return $data;

  }

  protected function getUpdatedIssuesData(){
    $yesterday = strtotime('-1 day');
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    $updatedIds = \Drupal::entityQuery('node')
    ->condition('type', 'ai_issue')
    ->condition('changed', $yesterday, '>=')
    ->condition('created', $yesterday, '<') 
    ->accessCheck(FALSE)
    ->execute();

    $data = [];
 
    $updatedIssueNodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);

    foreach ($updatedIssueNodes as $issueNode) {
        $vids = $storage->revisionIds($issueNode);
        if (count($vids) >= 2) {
            $previousRev = $storage->loadRevision(end(array_slice($vids, -2, 1)));
            $changes = getChangedFields($issueNode, $previousRev);
            $data[] = [
                'id' => $issueNode->id(),
                'name' => $issueNode->label(),
                'module' => $issueNode->->get('field_issue_module')->entity->id(),
                'action' => 'updated',
                'changes' => $changes,
            ];
        }
    }

    return $data;
  }

  protected function getChangedFields($newNode, $oldNode){
    $changes = [];

    foreach ($newNode->getFields() as $fieldName => $fieldItem) {
        if (!str_starts_with($filedName, 'field_')) continue;

        if (!$newNode->get($fieldName)->equals($oldNode->get($fieldName))) {
            $changes[] = [
                'name': $fieldName,
                'old': $oldNode->get($fieldName),
                'new': $newNode->get($fieldName),
            ];
        }
     }

  return $changes;
  }
}