<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource to update list of modules to follow via Telegram bot.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_subscribe",
 * label = @Translation("Telegram bot subscription"),
 * uri_paths = {
 * "create" = "/api/telegram/subscribe"
 * }
 * )
 */
class Subscribe extends ResourceBase {

  protected $currentRequest;
  private $database;

  private function upsertUser($userInfo){
    
     $subscriberData = $this->database
    ->query("SELECT * FROM {telegram_subscribers} WHERE chat_id = :cid", [':cid' => $userInfo["id"]])
    ->fetchField();

    if($subscriberData){
      $this->database->update('telegram_subscribers')
      ->condition('chat_id', $userInfo["id"])
      ->fields([
        'type' => $userInfo["type"],
      ])
      ->execute();
    }else{
      $this->database->insert('telegram_subscribers')
      ->fields([
        'chat_id' => $userInfo["id"], 
        'status' => 1,
        'created' => $this->currentRequest->server->get('REQUEST_TIME'),
        'type' => $userInfo["type"],
        ])
        ->execute();
    }
  }

  private function updateModulesList($cid, $moduleNames){

    if(!count($moduleNames)){
      $this->deleteAllModules($cid);
     
    }else{
      $existingSubscriptions = $this->database
      ->query("SELECT * FROM {telegram_subscriptions} WHERE chat_id = :cid", [':cid' => $cid])
      ->fetchAll();

      $moduleIds = \Drupal::entityQuery('node')
      ->condition('type', 'ai_module')
      ->accessCheck(FALSE)
      ->condition('field_module_machine_name', $moduleNames, 'IN')
      ->execute();

      $this->subscribeToNewModules($cid, $moduleIds, $existingSubscriptions);
      $this->unsubscribeFromOldModules($cid, $moduleIds, $existingSubscriptions);
    }

    
  }

  private function subscribeToNewModules($cid, $moduleIds, $existingSubscriptions){

    $insertQuery = $this->database
    ->insert('telegram_subscriptions')
    ->fields(['chat_id', 'module_id', 'created']);

  
    foreach($moduleIds as $moduleId){
      
      $subscriptionExists = array_find($existingSubscriptions, function($existingSubscription) use ($moduleId){
        return $existingSubscription->module_id === $moduleId;
      });

      if(!$subscriptionExists){
        $valuesToInsert = [
          'chat_id' => $cid,
          'module_id' => $moduleId,
          'created' => $this->currentRequest->server->get('REQUEST_TIME'),
        ];
        $insertQuery->values($valuesToInsert);
      }
    }

    $insertQuery->execute();
  }

  private function unsubscribeFromOldModules($cid, $moduleIds, $existingSubscriptions){

    $moduleIdsToDelete = [];

    foreach($existingSubscriptions as $existingSubscription){
      $keepSubscription = array_find($moduleIds, function($moduleId) use ($existingSubscription){
        return $moduleId === $existingSubscription->module_id;
      });
      if(!$keepSubscription){
        $moduleIdsToDelete[] = $existingSubscription->module_id;
      }
    }
  
    if(count($moduleIdsToDelete)) {
      $this->database
      ->delete('telegram_subscriptions')
      ->condition('module_id', $moduleIdsToDelete, 'IN')
      ->execute();
    }

    

  }

  private function deleteAllModules($cid){
    $this->database
    ->delete('telegram_subscriptions')
    ->condition('chat_id', $cid)
    ->execute();
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
      $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
      $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
      $instance->database = \Drupal::database();
      return $instance;
  }


  public function post() {
    $content = $this->currentRequest->getContent();
    
    $params = json_decode($content, TRUE);

    $this->upsertUser($params['userInfo']);

    $cid = strval($params['userInfo']['id']);
    $moduleNames = $params['modules'];
    $this->updateModulesList($cid, $moduleNames);

    return new ResourceResponse(NULL, 204);
  }
}