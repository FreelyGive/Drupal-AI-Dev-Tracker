<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource to unsubscribe from Telegram bot.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_unsubscribe",
 * label = @Translation("Telegram bot unsubscription"),
 * uri_paths = {
 * "create" = "/api/telegram/unsubscribe"
 * }
 * )
 */
class Unsubscribe extends ResourceBase {

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

    $database = \Drupal::database();

    $database
    ->update('telegram_subscribers')
    ->fields([ 
      'status' => 0,
    ])
    ->condition('chat_id', $cid)
    ->execute();

    return new ResourceResponse(['message' => 'success!']);
  }
}