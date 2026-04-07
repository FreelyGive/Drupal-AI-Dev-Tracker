<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource to subscribe to Telegram bot.
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

public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
    return $instance;
  }


  public function post() {
    $content = $this->currentRequest->getContent();
    
    $params = json_decode($content, TRUE);
    
    $responseData = [];

    $cid = strval($params['userInfo']['id']);

    $database = \Drupal::database();

    $subscriberData = $database->query("SELECT * FROM {telegram_subscribers} WHERE chat_id = :cid", [':cid' => $cid])->fetchField();

    $responseData['user exists'] = !!$subscriberData;

    if(!$subscriberData){
      $subsriberCreationResult = $database->insert('telegram_subscribers')
      ->fields([
        'chat_id' => $cid, 
        'status' => 1,
        'created' => $params['timestamp']
        ])
        ->execute();
    }

    $existingSubscriptions = $database
    ->query("SELECT * FROM {telegram_subscriptions} WHERE chat_id = :cid", [':cid' => $cid])
    ->fetchAll();

    $moduleIds = \Drupal::entityQuery('node')
    ->condition('type', 'ai_module')
    ->accessCheck(FALSE)
    ->condition('field_module_machine_name', $params['modules'], 'IN')
    ->execute();

    
    // Subscribe to new modules
    $modulesIdsToAdd = [];
    $insertQuery = $database
    ->insert('telegram_subscriptions')
    ->fields(['chat_id', 'module_id', 'created']);

  
    foreach($moduleIds as $moduleId){
      
      $subscriptionExists = array_find($existingSubscriptions, function($existingSubscription){
        return $existingSubscription->module_id === $moduleId;
      });

      if(!$subscriptionExists){
        $moduleIdsToAdd[] = $moduleId;
        $valuesToInsert = [
          'chat_id' => $cid,
          'module_id' => $moduleId,
          'created' => $params['timestamp']
        ];
        $insertQuery->values($valuesToInsert);
      }
    }

    // $insertQuery->execute();

    // Unsubscribe from modules

    $moduleIdsToDelete = [];

    foreach($existingSubscriptions as $existingSubscription){
      $keepSubscription = array_find($moduleIds, function($moduleId){
        return $moduleId === $existingSubscription->module_id;
      });
      if(!$keepSubscription){
        $moduleIdsToDelete[] = $existingSubscription->module_id;
      }
    }

    $responseData['to delete'] = $moduleIdsToDelete;
    $responseData['to add'] = $moduleIdsToAdd;

    

    return new ResourceResponse($responseData);
  }
}