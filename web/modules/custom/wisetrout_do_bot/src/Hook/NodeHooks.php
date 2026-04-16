<?php

namespace Drupal\wisetrout_do_bot\Hook;

use Drupal\Core\Entity\EntityEvents;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for wisetrout_do_bot.
 */
class NodeHooks {

  /**
   * Reacts to node insertion.
   */
  #[Hook('node_insert')]
  public function onNodeInsert(NodeInterface $node): void {
    if ($node->isPublished()){
      if ($node->getType() === 'ai_issue') {
      $this->notifyAboutIssueCreation($node);
      }
      if ($node->getType() === 'ai_module') {
        $this->notifyAboutModuleCreation($node);
      }
    }
  }

  #[Hook('node_update')]
  public function onNodeUpdate(NodeInterface $node): void {
    if ($node->isPublished() && $node->getType() === 'ai_issue'){
      if($node->original->isPublished()) {
        $this->notifyAboutIssueUpdate($node);
      }else {
        $this->notifyAboutIssueCreation($node);
      }
    }

    if ($node->isPublished() && $node->getType() === 'ai_module' && !($node->original->isPublished())){
      $this->notifyAboutModuleCreation($node);
    }
  }

  protected function notifyAboutIssueCreation(NodeInterface $node): void{

    $chatIds = $this->getModuleChatIds($node);

    $moduleName = $node->get('field_issue_module')->entity->label();

    $authorName = $node->getOwner()->getDisplayName();

    $message = "🌱 <b>New issue created</b> in {$moduleName} by {$authorName}: {$node->label()}
    <b>project URL</b>: {$node->get('field_issue_url')->uri}";


    $this->sendBotNotifications($chatIds, $message);

  }
  protected function notifyAboutIssueUpdate(NodeInterface $node): void{

    $chatIds = $this->getModuleChatIds($node);
    $updates = $this->findNodeChanges($node);

    if(!count($updates)) {
      return;
    }

    $message = "✏️<b>Issue updated </b>: {$node->label()}";

    foreach($updates as $update){
      $message = $message . "\n{$update['title']}: {$update['old_value']} -> {$update['new_value']}";
    }


    $this->sendBotNotifications($chatIds, $message);
  }

  protected function notifyAboutModuleCreation(NodeInterface $node): void{
    $activeUserIds = $this->getActiveUserIds();
     $message = "🏷️<b>New module created:</b> {$node->label()}";
    ;
    $this->sendBotNotifications($activeUserIds, $message);
  }

  protected function getModuleChatIds($node){
    $moduleEntity = $node->get('field_issue_module')->entity;
    $moduleId = $moduleEntity->id();
    $db = \Drupal::database();
    $chatIds = $db
    ->query("SELECT chat_id FROM {telegram_subscriptions} WHERE module_id = :module_id", [
        ':module_id' => $moduleId,
    ])
    ->fetchCol();
    $activeChatIds = $db
    ->query("SELECT chat_id FROM {telegram_subscribers} WHERE chat_id IN (:chat_ids[]) and status = 1", [
        ':chat_ids[]' => $chatIds,
    ])
    ->fetchCol();
    return $activeChatIds;
  }

  protected function getActiveUserIds(){
    $db = \Drupal::database();
    $activeUserIds = $db
    ->query("SELECT chat_id FROM {telegram_subscribers} WHERE status = 1")
    ->fetchCol();
    return $activeUserIds;
  }

  protected function findNodeChanges($node){
    $changedFields = [];
    $original = $node->original;
    foreach ($node->getFieldDefinitions() as $fieldName => $fieldDefinition) {

      if (str_starts_with($fieldName, 'field_') && !$node->get($fieldName)->equals($original->get($fieldName))) {
        
        $changedFields[] = [
          'title' => (string) $fieldDefinition->getLabel(),
          'old_value' => $this->convertFieldToString($original, $fieldName),
          'new_value' => $this->convertFieldToString($node, $fieldName),
        ];
      }
    }

    return $changedFields;
  }

  protected function convertFieldToString($node, $fieldName){
    $field = $node->get($fieldName);
  
    if ($field->isEmpty()) {
      return '';
    }

    $values = [];
    foreach ($field as $item) {
      $itemValues = $item->getValue();
      $values[] = $itemValues['value'] ?? $item_values['target_id'] ?? (string) reset($itemValues);
    }

    return implode(', ', $values);
  }

  protected function sendBotNotifications($chatIds, $message){


    $httpClient = \Drupal::httpClient();

    foreach($chatIds as $chatId){
    
      $url = 'https://api.telegram.org/bot' 
      . $_ENV['BOT_TOKEN']
      . '/sendMessage';
      $payload = [
        'chat_id' => $chatId,
        'text' => $message,
        "parse_mode" => "HTML",
      ];

      $response = $httpClient
      ->post($url, [
        'body' => json_encode($payload),
        'headers' => [
          'Content-Type' => 'application/json',
        ]
      ]);
    }
  }
}