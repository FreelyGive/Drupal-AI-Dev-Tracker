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


        $creations = $this->getCreatedIssues();
        $updates = $this->getUpdatedIssues();

        $moduleSummaries = $this->createModuleSummaries($updates, $creations);
        $newModulesSummary = $this->createNewModulesSummary();

        $chatIds = $this->getActiveDailySubscriberIds();
        
        $subscriptions = $this->getDailySubscriptions($chatIds);
        

        $moduleSummaryNotifications = $this->createModuleSummaryNotifications($subscriptions, $moduleSummaries);
        
        $queue = \Drupal::queue('telegram_bot_queue');
        
        $items_to_process = $newModulesSummary ?
        array_merge($moduleSummaryNotifications, $this->createModuleCreationNotifications($chatIds, $newModulesSummary)):
        $moduleSummaryNotifications;

        foreach ($items_to_process as $item_data) {
          $queue->createItem($item_data);
        }

    }

    // Update the last run time.
    \Drupal::state()->set('wisetrout_do_bot.cron_last_run', $current_time);
  }

  protected function getActiveDailySubscriberIds(){
    return \Drupal::database()
    ->query("SELECT chat_id FROM {telegram_subscribers} WHERE status = 1 AND type = 'daily'")
    ->fetchCol();
  }

  protected function getDailySubscriptions($chatIds){
    return \Drupal::database()
    ->query("SELECT chat_id, module_id FROM {telegram_subscriptions} WHERE chat_id IN (:chat_ids[])", [
        ':chat_ids[]' => $chatIds,
    ])
    ->fetchAll();
  }


  protected function getCreatedIssues(){
    $yesterday = strtotime('-1 day');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $createdIds = \Drupal::entityQuery('node')
    ->condition('type', 'ai_issue')
    ->condition('created', $yesterday, '>=')
    ->accessCheck(FALSE)
    ->execute();

    $data = [];
 
    $createdIssueNodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($createdIds);

    return $createdIssueNodes;

  }

  protected function getUpdatedIssues(){
    $yesterday = strtotime('-1 day');
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    $updatedIds = \Drupal::entityQuery('node')
    ->condition('type', 'ai_issue')
    ->condition('changed', $yesterday, '>=')
    ->condition('created', $yesterday, '<') 
    ->accessCheck(FALSE)
    ->execute();

    $data = [];
 
    $updatedIssueNodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($updatedIds);

    return $updatedIssueNodes;
  }

  

  protected function createModuleSummaries($updatedNodes, $createdNodes){
    $summaries = [];
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $moduleUpdates = [];

    foreach($createdNodes as $createdNode){

      $moduleId = $createdNode->get('field_issue_module')->entity->id();

      if($moduleUpdates[$moduleId]){
        $createdIssues = $moduleUpdates[$moduleId]['created'];
        if($moduleUpdates[$moduleId]['created']){
          $moduleUpdates[$moduleId]['created'][] = $createdNode;
        }else{
          $moduleUpdates[$moduleId]['created'] = [$createdNode];
        }
      }else{
        $moduleUpdates[$moduleId] = [
          'label' => $createdNode->get('field_issue_module')->entity->label(),
          'created' => [$createdNode],
        ];
      }
    }

    foreach($updatedNodes as $updatedNode){

      $moduleId = $updatedNode->get('field_issue_module')->entity->id();

      if($moduleUpdates[$moduleId]){
        if($moduleUpdates[$moduleId]['updated']){
          $moduleUpdates[$moduleId]['updated'][] = $updatedNode;
        }else{
          $moduleUpdates[$moduleId]['updated'] = [$updatedNode];
        }
      }else{
        $moduleUpdates[$moduleId] = [
          'label' => $updatedNode->get('field_issue_module')->entity->label(),
          'updated' => [$updatedNode],
        ];
      }

    }

    foreach($moduleUpdates as $moduleId => $moduleInfo){

      $text = "🏷️<b>{$moduleInfo["label"]}</b> updates:\n";

      if($moduleInfo['created']){
        $text .= "<b>New issue(s):</b>\n";
        foreach($moduleInfo['created'] as $createdNode){
          $text .= "🌱{$createdNode->label()}\n";
        }
      }

      if($moduleInfo['updated']){
        $text .= "<b>Issue updates: </b>\n";
        foreach($moduleInfo['updated'] as $issueNode){

          $changes = $this->findDailyChanges($issueNode);

          $text .= "✏️{$issueNode->label()}:\n";
            
          foreach($changes as $change){
            $text .= "{$change['name']}: {$change['old']} -> {$change['new']}\n";
          }

        }
      }

      $summaries[$moduleId] = $text;

    }
    
    return $summaries;

  }

  protected function createNewModulesSummary(){
    $yesterday = strtotime('-1 day');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $createdIds = \Drupal::entityQuery('node')
    ->condition('type', 'ai_module')
    ->condition('created', $yesterday, '>=')
    ->accessCheck(FALSE)
    ->execute();

    if(!count($createdIds)) return null;

    $createdModuleNodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($createdIds);
    $createdModuleNames = array_map(function($node){
        return $node->field_module_machine_name[0]->value;
    }, $createdModuleNodes);

    $modulesList = join(', ', $createdModuleNames);

    $summary = "🏷️ New modules created: {$modulesList}.";
    return $summary;
  }

  protected function createModuleSummaryNotifications($subscriptions, $moduleSummaries){

    $notifications = [];

    foreach($subscriptions as $subscription){
      if($moduleSummaries[$subscription->module_id]){
        $notifications[] = [
          "chatId" => $subscription->chat_id,
          "message" => $moduleSummaries[$subscription->module_id],
        ];
      }
    }

    return $notifications;
  }

  protected function createModuleCreationNotifications($chatIds, $summary){
    $notifications = array_map(function($chatId) use ($summary){
      return [
        "chatId" => $chatId,
        "message" => $summary,
      ];
    }, $chatIds);
    return $notifications;
  }

  protected function findDailyChanges($node){
          
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    
    $current_time = \Drupal::time()->getRequestTime();
    $yesterday = strtotime('-1 day');

    $oldVid = $this->getRevisionBeforeTimestamp($node->id(), $yesterday);
    $oldNode = $storage->loadRevision($oldVid);

    return $this->getChangedFields($node, $oldNode);
  }

  protected function getRevisionBeforeTimestamp($nid, $timestamp) {
    $database = \Drupal::database();
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    return $database->select('node_revision', 'nr')
      ->fields('nr', ['vid'])
      ->condition('nid', $nid)
      ->condition('revision_timestamp', $timestamp, '<')
      ->orderBy('revision_timestamp', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }


  protected function getChangedFields($newNode, $oldNode){
    $changes = [];
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    foreach ($newNode->getFields() as $fieldName => $fieldItem) {
        if (!str_starts_with($fieldName, 'field_')) continue;

        if (!$newNode->get($fieldName)->equals($oldNode->get($fieldName))) {

            $oldValue;
            $newValue;

            $oldValue = $oldNode->get($fieldName)->getString();
            $newValue = $fieldItem->getString();

            if($fieldItem->getFieldDefinition()->getType() === "entity_reference"){
                $oldValue = $this->convertIdsToLabels($oldValue, $storage);
                $newValue = $this->convertIdsToLabels($newValue, $storage);
            }

            $changes[] = [
                'name' => $fieldItem->getFieldDefinition()->getLabel(),
                'old' => $oldValue ? $oldValue : '(none)',
                'new' => $newValue ? $newValue : '(none)',
            ];
        }
     }

  return $changes;
  }

  function convertIdsToLabels($string, $storage){
    if($string === "") return null;
    $idsArray = explode(',', $string);
    $nodesArray = $storage->loadMultiple($idsArray);
    $nodeLabels = array_map(function($node){
      return $node->label();
    }, $nodesArray);
    $labelsString = implode(', ', $nodeLabels);
    return $labelsString;
  }
}