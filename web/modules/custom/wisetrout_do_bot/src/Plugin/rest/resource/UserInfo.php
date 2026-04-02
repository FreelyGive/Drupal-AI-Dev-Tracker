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

    public function get($cid){

    $database = \Drupal::database();
    $selectQuery = $database->query("SELECT module, status FROM {telegram_subscriptions} WHERE chat_id = :cid", [':cid' => $cid]);
    $result = $selectQuery->fetchAll();

    $modules = [];

    if(count($result) === 0) {
      $data = NULL;
    } else {

      $modules = [];

      foreach($result as $resultRow){
        $modules[] = $resultRow->module;
      }

      $data = [
        'modules' => $modules,
        'subscribed' => $result[0]->status == 1,
      ];
    }


    return new ResourceResponse([
      'userInfo' => $data,
    ]);
  }
}