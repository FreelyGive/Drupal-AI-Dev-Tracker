<?php

namespace Drupal\wisetrout_do_bot\Plugin\rest\resource;


use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\node\Entity\Node;

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

    $nids = \Drupal::entityQuery('node')
    ->condition('type', 'ai_module')
    ->accessCheck(FALSE)
    ->execute();

    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);

    $nodeNames = [];

    foreach($nodes as $node){
      $nodeNames[] = $node->field_module_machine_name[0]->value;
    }

    return new ResourceResponse($nodeNames);
  }
}