<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource to pause/renew subscription to Telegram bot.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_unsubscribe",
 * label = @Translation("Telegram bot pause/renew subscription"),
 * uri_paths = {
 * "create" = "/api/telegram/update-status"
 * }
 * )
 */
class UpdateStatus extends ResourceBase {

   protected $currentRequest;

   public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
      $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
      $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
      return $instance;
  }


  public function post() {
    $content = $this->currentRequest->getContent();
    
    $params = json_decode($content, TRUE);

    $cid = strval($params['chatId']);
    $newStatus = $params['subscribed'] ? 1 : 0;

    $database = \Drupal::database();

    $database
    ->update('telegram_subscribers')
    ->fields([ 
      'status' => $newStatus,
    ])
    ->condition('chat_id', $cid)
    ->execute();

    return new ResourceResponse(['message' => 'success!']);
  }
}