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

    $cid = strval($params['userInfo']['id']);

    $database = \Drupal::database();
    $selectQuery = $database->query("SELECT * FROM {telegram_subscriptions} WHERE chat_id = :cid", [':cid' => $cid]);
    $existingSubscriptions = $selectQuery->fetchAll();

    $insertQuery = $database->insert('telegram_subscriptions')->fields(['chat_id', 'module', 'created', 'status']);

    foreach($params['modules'] as $module) {
      $existingSubscription = array_find($existingSubscriptions, function($value){
        return $value['module'] === $module;
      });

      if(!$existingSubscription) {
        $valuesToInsert = [
          'chat_id' => $cid,
          'module' => $module,
          'created' => $params['timestamp'],
          'status' => 1
        ];
        $insertQuery->values($valuesToInsert);
      }
    }


  $insertQuery->execute();

    return new ResourceResponse([
      'message' => 'success',
      'cid' => $cid
      ]);
  }
}