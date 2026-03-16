<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;

/**
 * Provides a resource to wipeout all user data related to Telegram bot.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_wipeout",
 * label = @Translation("Telegram bot wipeout"),
 * uri_paths = {
 * "canonical" = "/api/telegram/wipeout"
 * }
 * )
 */
class Wipeout extends ResourceBase {
  public function post() {
    $data = ['userInfo' => NULL];
    return new ResourceResponse($data);
  }
}