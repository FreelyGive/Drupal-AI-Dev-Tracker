<?php

namespace Drupal\issue_analysis\Drush\Commands;

use Drupal\issue_analysis\Service\IssueAnalysisService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for issue analysis.
 */
class IssueAnalysisCommands extends DrushCommands {

  /**
   * Constructs a new IssueAnalysisCommands object.
   *
   * @param \Drupal\issue_analysis\Service\IssueAnalysisService $issueAnalysisService
   *   The issue analysis service.
   */
  public function __construct(
    protected IssueAnalysisService $issueAnalysisService,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('issue_analysis.service'),
    );
  }

  /**
   * Analyse a Drupal.org issue using an LLM and print the result as JSON.
   *
   * @param string $issueNumber
   *   The drupal.org issue number.
   */
  #[CLI\Command(name: 'issue-analysis:analyse', aliases: ['ia'])]
  #[CLI\Argument(name: 'issueNumber', description: 'Drupal.org issue number.')]
  #[CLI\Usage(name: 'drush issue-analysis:analyse 3429851', description: 'Analyse issue #3429851.')]
  public function analyse(string $issueNumber): void {
    try {
      $result = $this->issueAnalysisService->analyseIssue($issueNumber);
      $this->io()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
    }
  }

}
