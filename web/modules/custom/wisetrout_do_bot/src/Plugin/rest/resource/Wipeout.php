<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\Core\Database\Connection;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource to wipeout all user data related to Telegram bot.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_wipeout",
 * label = @Translation("Telegram bot wipeout"),
 * uri_paths = {
 * "create" = "/api/telegram/wipeout"
 * }
 * )
 */
class Wipeout extends ResourceBase {
  protected $currentRequest;

  protected Connection $database;

  private function deleteUserInfo($cid){
    $this->database
    ->delete('telegram_subscribers')
    ->condition('chat_id', $cid)
    ->execute();
  }

  private function deleteUserSubscriptions($cid){
    $this->database
    ->delete('telegram_subscriptions')
    ->condition('chat_id', $cid)
    ->execute();
  }

   public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
      $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
      $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
      $instance->database = $container->get('database');
      return $instance;
  }


  public function post() {
    $content = $this->currentRequest->getContent();

    $params = json_decode($content, TRUE);

    $cid = $params['chatId'];

    $this->deleteUserInfo($cid);
    $this->deleteUserSubscriptions($cid);

    return new ResourceResponse(['message' => 'success!']);
  }
}
