<?php

namespace Drupal\issue_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
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
 *
 * This endpoint only enqueues the digest job and returns immediately. The
 * actual generation runs via `drush queue:run issue_analysis_daily_digest`
 * (CLI, no HTTP timeout) or via Drupal cron.
 */
class DailyDigestCronController extends ControllerBase {

  public function __construct(
    protected QueueFactory $queueFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('queue'),
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

    if ($queue->numberOfItems() > 0) {
      return new JsonResponse(['status' => 'already_queued']);
    }

    $queue->createItem(['module' => NULL]);
    return new JsonResponse(['status' => 'queued']);
  }

}
