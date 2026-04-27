<?php

namespace Drupal\wisetrout_do_bot\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/**
 * Processes intensive tasks wisetrout_do_bot
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

    $httpClient = \Drupal::httpClient();
    
      $url = 'https://api.telegram.org/bot' 
      . $_ENV['BOT_TOKEN']
      . '/sendMessage';
      $payload = [
        'chat_id' => $data['chatId'],
        'text' => $data['message'],
        "parse_mode" => "HTML",
      ];

      $response = $httpClient
      ->post($url, [
        'body' => json_encode($payload),
        'headers' => [
          'Content-Type' => 'application/json',
        ]
      ]);
  }

}