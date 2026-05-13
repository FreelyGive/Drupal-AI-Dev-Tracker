<?php

namespace Drupal\issue_analysis\Drush\Commands;

use Drupal\issue_analysis\Service\AiSummariserService;
use Drupal\issue_analysis\Service\DailyDigestService;
use Drupal\issue_analysis\Service\NewsletterDataFetcherService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for newsletter-style project activity summaries.
 */
class NewsletterCommands extends DrushCommands {

  /**
   * Constructs a new NewsletterCommands instance.
   */
  public function __construct(
    protected NewsletterDataFetcherService $fetcher,
    protected AiSummariserService $summariser,
    protected DailyDigestService $digestService,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('issue_analysis.newsletter_fetcher'),
      $container->get('issue_analysis.summariser'),
      $container->get('issue_analysis.daily_digest'),
    );
  }

  // ---------------------------------------------------------------------------
  // Fetch command
  // ---------------------------------------------------------------------------

  /**
   * Fetch drupal.org and GitLab activity for one or all ai_module nodes.
   *
   * Outputs raw JSON that can be inspected or piped into ia-ns for summarisation.
   *
   * @param string $period
   *   Time period: 24h, 7d, or 30d.
   */
  #[CLI\Command(name: 'issue-analysis:newsletter-fetch', aliases: ['ia-nf'])]
  #[CLI\Argument(name: 'period', description: 'Time period to fetch: 24h, 7d, or 30d.')]
  #[CLI\Option(name: 'module', description: 'Machine name of a single module (e.g. "ai"). Omit to fetch all.')]
  #[CLI\Option(name: 'since', description: 'Custom start date (YYYY-MM-DD). Overrides period.')]
  #[CLI\Option(name: 'until', description: 'Custom end date (YYYY-MM-DD). Used only with --since.')]
  #[CLI\Option(name: 'output', description: 'Write JSON output to this file path instead of stdout.')]
  #[CLI\Usage(name: 'drush ia-nf 7d', description: 'Fetch last 7 days for all modules, print JSON.')]
  #[CLI\Usage(name: 'drush ia-nf 24h --module=ai', description: 'Fetch last 24h for the "ai" module only.')]
  #[CLI\Usage(name: 'drush ia-nf 30d --output=/tmp/report.json', description: 'Save 30-day report to file.')]
  public function newsletterFetch(
    string $period = '7d',
    array $options = ['module' => NULL, 'since' => NULL, 'until' => NULL, 'output' => NULL],
  ): void {
    try {
      [$since, $until] = $this->resolveDateRange($period, $options);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return;
    }

    $module = $options['module'] ?? NULL;

    $this->io()->writeln(sprintf(
      '<info>Fetching %s activity from %s to %s...</info>',
      $module ? "\"$module\"" : 'all modules',
      $since->format('Y-m-d H:i'),
      $until->format('Y-m-d H:i'),
    ));

    if (!$module) {
      $modules = $this->fetcher->getAllModules();
      $this->io()->writeln(sprintf('<comment>Found %d ai_module node(s).</comment>', count($modules)));
    }

    $results = $this->fetcher->fetchAllModulesData($module, $since, $until);

    $this->printSummary($results);

    $json = json_encode([
      'period' => $period,
      'since' => $since->format(\DateTime::ATOM),
      'until' => $until->format(\DateTime::ATOM),
      'modules' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $outputFile = $options['output'] ?? $this->digestService->resolveOutputPath($period, $since->format('Y-m-d'), 'json');
    file_put_contents($outputFile, $json . "\n");
    $this->io()->success("Output written to $outputFile");

    $dataFile = $this->digestService->resolveOutputPath($period, $since->format('Y-m-d'), 'md', '-data');
    file_put_contents($dataFile, $this->digestService->buildDataMarkdown($results, $period, $since->format(\DateTime::ATOM), $until->format(\DateTime::ATOM)) . "\n");
    $this->io()->success("Data overview written to $dataFile");
  }

  // ---------------------------------------------------------------------------
  // Daily digest command
  // ---------------------------------------------------------------------------

  /**
   * Fetch the last 24h of activity and generate both developer and executive newsletters.
   */
  #[CLI\Command(name: 'issue-analysis:daily-digest', aliases: ['ia-daily'])]
  #[CLI\Option(name: 'module', description: 'Machine name of a single module. Omit to process all.')]
  #[CLI\Usage(name: 'drush ia-daily', description: 'Fetch 24h data and generate developer + executive newsletters.')]
  #[CLI\Usage(name: 'drush ia-daily --module=ai', description: 'Run the daily digest for the "ai" module only.')]
  public function dailyDigest(
    array $options = ['module' => NULL],
  ): void {
    $module = $options['module'] ?? NULL;
    $io = $this->io();

    $this->digestService->run($module, function (string $msg) use ($io): void {
      $io->writeln("<info>$msg</info>");
    });
  }

  // ---------------------------------------------------------------------------
  // Sprint digest command
  // ---------------------------------------------------------------------------

  /**
   * Aggregate the last 14 days of daily JSON data files into a sprint digest.
   *
   * Reads all 24h_*.json files from public://issues-digest/ that fall within
   * the last 14 days, merges and deduplicates per-module activity, then
   * generates developer and executive newsletters and saves a daily_digest
   * node with field_digest_period = '2w'.
   */
  #[CLI\Command(name: 'issue-analysis:biweekly-digest', aliases: ['ia-biweekly'])]
  #[CLI\Option(name: 'module', description: 'Machine name of a single module. Omit to process all.')]
  #[CLI\Usage(name: 'drush ia-biweekly', description: 'Aggregate last 14 days of data files and generate sprint digest.')]
  #[CLI\Usage(name: 'drush ia-biweekly --module=ai', description: 'Run sprint digest for the "ai" module only.')]
  public function biweeklyDigest(
    array $options = ['module' => NULL],
  ): void {
    $module = $options['module'] ?? NULL;
    $io = $this->io();

    $this->digestService->runBiweekly($module, function (string $msg) use ($io): void {
      $io->writeln("<info>$msg</info>");
    });
  }

  // ---------------------------------------------------------------------------
  // Summarise command
  // ---------------------------------------------------------------------------

  /**
   * Summarise a newsletter data JSON file (output of ia-nf) using an LLM.
   *
   * @param string $inputFile
   *   Path to the JSON file produced by ia-nf.
   */
  #[CLI\Command(name: 'issue-analysis:newsletter-summarise', aliases: ['ia-ns'])]
  #[CLI\Argument(name: 'inputFile', description: 'Path to the JSON file produced by ia-nf.')]
  #[CLI\Option(name: 'output', description: 'Write the newsletter text to this file instead of stdout.')]
  #[CLI\Option(name: 'format', description: 'Output format: markdown (default) or plain.')]
  #[CLI\Option(name: 'module', description: 'Summarise only this module from the file (machine name).')]
  #[CLI\Option(name: 'persona', description: 'Target audience: developer (default) or executive.')]
  #[CLI\Usage(name: 'drush ia-ns /tmp/report.json', description: 'Summarise all modules in the file.')]
  #[CLI\Usage(name: 'drush ia-ns /tmp/report.json --module=ai --output=/tmp/newsletter.md', description: 'Summarise the "ai" module and save.')]
  #[CLI\Usage(name: 'drush ia-ns /tmp/report.json --persona=executive --output=/tmp/exec.md', description: 'Executive-style summary for non-technical readers.')]
  public function newsletterSummarise(
    string $inputFile,
    array $options = ['output' => NULL, 'format' => 'markdown', 'module' => NULL, 'persona' => 'developer'],
  ): void {
    if (!file_exists($inputFile)) {
      $this->logger()->error("File not found: $inputFile");
      return;
    }

    $raw = file_get_contents($inputFile);
    $data = json_decode($raw, TRUE);
    if (!is_array($data) || !isset($data['modules'])) {
      $this->logger()->error("Invalid JSON structure. Expected output from ia-nf.");
      return;
    }

    $modules = $data['modules'];
    $filterModule = $options['module'] ?? NULL;

    if ($filterModule) {
      $modules = array_values(array_filter($modules, fn($m) => $m['machine_name'] === $filterModule));
      if (!$modules) {
        $this->logger()->error("Module '$filterModule' not found in the file.");
        return;
      }
    }

    $period = $data['period'] ?? 'custom';
    $since = $data['since'] ?? '';
    $until = $data['until'] ?? '';
    $format = $options['format'] ?? 'markdown';
    $persona = $options['persona'] ?? 'developer';
    if (!in_array($persona, ['developer', 'executive'], TRUE)) {
      $this->logger()->error("Invalid --persona '$persona'. Use: developer, executive.");
      return;
    }

    $this->io()->writeln(sprintf(
      '<info>Summarising %d module(s) via LLM...</info>',
      count($modules),
    ));

    $sections = [];
    foreach ($modules as $module) {
      $machineName = $module['machine_name'];

      $issueCount = count($module['issues'] ?? []);
      $mrCount = count($module['merge_requests'] ?? []);
      $commitCount = count($module['commits'] ?? []);

      if ($issueCount === 0 && $mrCount === 0 && $commitCount === 0) {
        $this->io()->writeln("  Skipping $machineName (no activity).");
        continue;
      }

      $this->io()->writeln("  Summarising $machineName ($issueCount issues, $mrCount MRs, $commitCount commits)...");

      try {
        $sections[$machineName] = $this->digestService->summariseModule($module, $period, $since, $until, $format, $persona);
      }
      catch (\RuntimeException $e) {
        $this->logger()->error("Failed to summarise $machineName: " . $e->getMessage());
        $sections[$machineName] = "_Summarisation failed: " . $e->getMessage() . "_";
      }
    }

    $this->io()->writeln('  Generating TL;DR...');
    try {
      $tldr = $this->digestService->generateTldr($sections, $period, $format, $persona);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error("Failed to generate TL;DR: " . $e->getMessage());
      $tldr = NULL;
    }

    $newsletter = $this->digestService->assembleNewsletter($sections, $tldr, $period, $since, $until, $format);

    $ext = $format === 'plain' ? 'txt' : 'md';
    $suffix = $persona === 'executive' ? '-executive' : '-dev';
    $outputFile = $options['output'] ?? $this->digestService->resolveOutputPath($period, $since, $ext, $suffix);
    file_put_contents($outputFile, $newsletter . "\n");
    $this->io()->success("Newsletter written to $outputFile");
  }

  // ---------------------------------------------------------------------------
  // List command
  // ---------------------------------------------------------------------------

  /**
   * Lists all ai_module nodes that would be iterated by the fetcher.
   */
  #[CLI\Command(name: 'issue-analysis:list-modules', aliases: ['ia-lm'])]
  #[CLI\Usage(name: 'drush ia-lm', description: 'List all ai_module nodes.')]
  public function listModules(): void {
    $modules = $this->fetcher->getAllModules();

    if (!$modules) {
      $this->io()->writeln('<comment>No published ai_module nodes found.</comment>');
      return;
    }

    $this->io()->writeln(sprintf('<info>%d ai_module node(s):</info>', count($modules)));
    foreach ($modules as $m) {
      $this->io()->writeln(sprintf('  [%d] %-35s  machine name: %s', $m['nid'], $m['title'], $m['machine_name']));
    }
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Resolves the [since, until] pair from period or --since/--until options.
   *
   * If --since is provided it takes precedence over $period. --until defaults
   * to "now" when omitted alongside --since.
   *
   * @param string $period
   *   Period shorthand: "24h", "7d", or "30d".
   * @param array $options
   *   Command options array; may contain "since" and "until" keys.
   *
   * @return array{\DateTimeImmutable, \DateTimeImmutable}
   *   Tuple of [since, until] as UTC DateTimeImmutable instances.
   *
   * @throws \InvalidArgumentException
   *   When --since or --until contain an unparseable date string.
   */
  private function resolveDateRange(string $period, array $options): array {
    if (!empty($options['since'])) {
      $since = \DateTimeImmutable::createFromFormat('Y-m-d', $options['since'], new \DateTimeZone('UTC'));
      if (!$since) {
        throw new \InvalidArgumentException("Invalid --since date '{$options['since']}'. Use YYYY-MM-DD.");
      }
      $since = $since->setTime(0, 0, 0);

      if (!empty($options['until'])) {
        $until = \DateTimeImmutable::createFromFormat('Y-m-d', $options['until'], new \DateTimeZone('UTC'));
        if (!$until) {
          throw new \InvalidArgumentException("Invalid --until date '{$options['until']}'. Use YYYY-MM-DD.");
        }
        $until = $until->setTime(23, 59, 59);
      }
      else {
        $until = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
      }

      return [$since, $until];
    }

    return NewsletterDataFetcherService::periodToDateRange($period);
  }

  /**
   * Prints a human-readable activity count summary to the console.
   *
   * @param array $results
   *   Module results array from NewsletterDataFetcherService::fetchAllModulesData().
   */
  private function printSummary(array $results): void {
    $this->io()->writeln('');
    $this->io()->writeln('<info>Results summary:</info>');

    foreach ($results as $r) {
      $errorNote = $r['errors'] ? ' (' . count($r['errors']) . ' error(s))' : '';
      $this->io()->writeln(sprintf(
        '  %-35s  issues: %3d  |  MRs: %3d  |  commits: %3d%s',
        $r['machine_name'],
        count($r['issues']),
        count($r['merge_requests']),
        count($r['commits']),
        $errorNote,
      ));

      foreach ($r['errors'] as $err) {
        $this->io()->writeln("    <error>$err</error>");
      }
    }

    $this->io()->writeln('');
  }


}
