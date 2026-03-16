<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;

/**
 * Provides a resource to get list of modules user can subscribe to.
 *
 * @RestResource(
 * id = "wisetrout_do_bot_modules_list",
 * label = @Translation("Telegram bot modules list"),
 * uri_paths = {
 * "canonical" = "/api/telegram/modules-list"
 * }
 * )
 */
class ModulesList extends ResourceBase {
  public function get() {
    $data = [
        [
            'name' =>  "Ctools",
            'machine_name' =>  "ctools"
        ], 
        [
            'name' =>  "Meta tag",
            'machine_name' =>  "metatag"
        ], 
        [
            'name' =>  "Commerce",
            'machine_name' =>  "commerce"
        ], 
    ];
    return new ResourceResponse($data);
  }
}