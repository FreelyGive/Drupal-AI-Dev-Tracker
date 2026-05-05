<?php

namespace Drupal\issue_analysis\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\issue_analysis\Service\DailyDigestService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes a daily digest generation job.
 *
 * @QueueWorker(
 *   id = "issue_analysis_daily_digest",
 *   title = @Translation("Issue Analysis: Daily Digest"),
 *   cron = {"time" = 300}
 * )
 */
class DailyDigestWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $pluginId,
    mixed $pluginDefinition,
    protected DailyDigestService $digestService,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition): static {
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container->get('issue_analysis.daily_digest'),
      $container->get('logger.factory')->get('issue_analysis'),
    );
  }

  public function processItem(mixed $data): void {
    $module = $data['module'] ?? NULL;

    $this->digestService->run($module, function (string $msg): void {
      $this->logger->info($msg);
    });
  }

}
