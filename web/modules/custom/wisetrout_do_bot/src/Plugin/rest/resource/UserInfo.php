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

    private function readUserStatus($cid, $database){
      $queryResult = $database
      ->query("SELECT status FROM {telegram_subscribers} WHERE chat_id = :cid", [':cid' => $cid])
      ->fetchAssoc();
      $status = $queryResult ? $queryResult['status'] : null;
      return $status;
    }

    private function readModulesList($cid, $database){

      $dbRows = $database
      ->query("SELECT module_id FROM {telegram_subscriptions} WHERE chat_id = :cid", [':cid' => $cid])
      ->fetchAll();

      $moduleIds = [];

      foreach($dbRows as $dbRow){
        $moduleIds[] = $dbRow->module_id;
      }

      $nodes = \Drupal\node\Entity\node::loadMultiple($nids);

      $modules = [];

      foreach($nodes as $node){
        $modules[] = $node->field_module_machine_name[0]->value;
      }

      return $modules;

      // return $moduleIds;
    }

    public function get($cid){

    $database = \Drupal::database();
    
    $userStatus = $this->readUserStatus($cid, $database);
    $responseData;

    if($userStatus === null) {
      $responseData = null;
    }else {
      $modulesList = $this->readModulesList($cid, $database);
      $responseData = [
        'subscribed' => !!$userStatus,
        'modules' => $modulesList,
      ];
    }

    return new ResourceResponse($responseData);
  }
}