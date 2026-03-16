<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;

/**
 * Provides a resource to unsubscribe from Telegram bot.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_unsubscribe",
 * label = @Translation("Telegram bot unsubscription"),
 * uri_paths = {
 * "canonical" = "/api/telegram/unsubscribe"
 * }
 * )
 */
class Unsubscribe extends ResourceBase {
  public function post() {
    $data = ['userInfo' => NULL];
    return new ResourceResponse($data);
  }
}