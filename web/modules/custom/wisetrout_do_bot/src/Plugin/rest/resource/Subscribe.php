<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;

/**
 * Provides a resource to subscribe to Telegram bot.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_subscribe",
 * label = @Translation("Telegram bot subscription"),
 * uri_paths = {
 * "canonical" = "/api/telegram/subscribe"
 * }
 * )
 */
class Subscribe extends ResourceBase {
  public function post() {
    $data = ['userInfo' => NULL];
    return new ResourceResponse($data);
  }
}