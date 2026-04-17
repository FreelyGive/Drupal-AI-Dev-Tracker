<?php

namespace Drupal\wisetrout_do_bot\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/**
 * Processes intensive tasks for my_module.
 *
 * @QueueWorker(
 * id = "telegram_bot_queue",
 * title = @Translation("Telegram bot daily summary processor"),
 * cron = {"time" = 60}
 * )
 */
class DailySummaryProcessor extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    
  }

}