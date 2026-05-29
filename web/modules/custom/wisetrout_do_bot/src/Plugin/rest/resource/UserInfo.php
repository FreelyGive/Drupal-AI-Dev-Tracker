<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\node\Entity\Node;

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

    private function readSubscriberInfo($cid, $database){
      $queryResult = $database
      ->query("SELECT status, type FROM {telegram_subscribers} WHERE chat_id = :cid", [':cid' => $cid])
      ->fetchAssoc();
      return $queryResult;
    }

    private function readModulesList($cid, $database){

      $moduleIds = $database
      ->query("SELECT module_id FROM {telegram_subscriptions} WHERE chat_id = :cid", [':cid' => $cid])
      ->fetchCol();

      $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($moduleIds);

      $modules = [];

      foreach($nodes as $node){
        $modules[] = $node->field_module_machine_name[0]->value;
      }

      return array_values($modules);
    }

    public function get($cid){

    $database = \Drupal::database();

    $subscriberInfo = $this->readSubscriberInfo($cid, $database);

    if(!$subscriberInfo) {
      return new ResourceResponse(null, 204);
    }else{
      $modulesList = $this->readModulesList($cid, $database);
      return new ResourceResponse([
        'subscribed' => !!$subscriberInfo['status'],
        'modules' => $modulesList,
        'type' => $subscriberInfo['type'],
      ]);
    }

  }
}
