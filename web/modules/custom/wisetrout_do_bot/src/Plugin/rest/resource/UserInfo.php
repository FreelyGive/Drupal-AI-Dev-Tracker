<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;

/**
 * Provides a resource to get Telegram bot user info.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_user_info",
 * label = @Translation("Telegram bot user info"),
 * uri_paths = {
 * "canonical" = "/api/telegram/user-info/{cid}"
 * }
 * )
 */
class UserInfo extends ResourceBase {
  public function get($cid = NULL) {
    $data = ['userInfo' => NULL];
    return new ResourceResponse($data);
  }
}