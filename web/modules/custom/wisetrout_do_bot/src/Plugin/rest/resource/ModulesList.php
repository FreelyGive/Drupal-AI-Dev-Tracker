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

    $nids = \Drupal::entityQuery('node')
    ->condition('type', 'ai_module')
    ->accessCheck(FALSE)
    ->execute();

    $nodes = \Drupal\node\Entity\node::loadMultiple($nids);

    return new ResourceResponse($nodes);
  }
}