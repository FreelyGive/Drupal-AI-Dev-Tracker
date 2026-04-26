<?php

namespace Drupal\issue_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP endpoint to trigger the daily digest from an external scheduler.
 *
 * Secured by a shared secret token configured in settings.local.php:
 *   $settings['issue_analysis_cron_token'] = 'your-secret-here';
 *
 * Call via: GET /issue-analysis/cron?token=your-secret-here
 */
class DailyDigestCronController extends ControllerBase {

  public function __construct(
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $queueWorkerManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
    );
  }

  public function run(Request $request): JsonResponse {
    $expected = Settings::get('issue_analysis_cron_token', '');

    if (!$expected) {
      return new JsonResponse(['error' => 'Cron token not configured.'], 503);
    }

    if (!hash_equals($expected, (string) $request->query->get('token', ''))) {
      return new JsonResponse(['error' => 'Forbidden.'], 403);
    }

    $queue = $this->queueFactory->get('issue_analysis_daily_digest');
    $queue->createItem(['module' => NULL]);

    $worker = $this->queueWorkerManager->createInstance('issue_analysis_daily_digest');
    $leaseTime = 3600;
    $log = [];

    while ($item = $queue->claimItem($leaseTime)) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (\Exception $e) {
        $queue->releaseItem($item);
        return new JsonResponse(['error' => $e->getMessage()], 500);
      }
    }

    return new JsonResponse(['status' => 'ok', 'log' => $log]);
  }

}
