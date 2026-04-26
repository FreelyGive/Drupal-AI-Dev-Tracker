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

    $outputFile = $options['output'] ?? $this->resolveOutputPath($period, $since->format('Y-m-d'), 'json');
    file_put_contents($outputFile, $json . "\n");
    $this->io()->success("Output written to $outputFile");

    $dataFile = $this->resolveOutputPath($period, $since->format('Y-m-d'), 'md', '-data');
    file_put_contents($dataFile, $this->buildDataMarkdown($results, $period, $since->format(\DateTime::ATOM), $until->format(\DateTime::ATOM)) . "\n");
    $this->io()->success("Data overview written to $dataFile");
  }

  // ---------------------------------------------------------------------------
  // Daily digest command
  // ---------------------------------------------------------------------------

  /**
   * Fetch the last 24h of activity and generate both developer and CEO newsletters.
   */
  #[CLI\Command(name: 'issue-analysis:daily-digest', aliases: ['ia-daily'])]
  #[CLI\Option(name: 'module', description: 'Machine name of a single module. Omit to process all.')]
  #[CLI\Usage(name: 'drush ia-daily', description: 'Fetch 24h data and generate dev + CEO newsletters.')]
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
  #[CLI\Option(name: 'persona', description: 'Target audience: developer (default) or ceo.')]
  #[CLI\Usage(name: 'drush ia-ns /tmp/report.json', description: 'Summarise all modules in the file.')]
  #[CLI\Usage(name: 'drush ia-ns /tmp/report.json --module=ai --output=/tmp/newsletter.md', description: 'Summarise the "ai" module and save.')]
  #[CLI\Usage(name: 'drush ia-ns /tmp/report.json --persona=ceo --output=/tmp/exec.md', description: 'Executive-style summary for non-technical readers.')]
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
    if (!in_array($persona, ['developer', 'ceo'], TRUE)) {
      $this->logger()->error("Invalid --persona '$persona'. Use: developer, ceo.");
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
        $sections[$machineName] = $this->summariseModule($module, $period, $since, $until, $format, $persona);
      }
      catch (\RuntimeException $e) {
        $this->logger()->error("Failed to summarise $machineName: " . $e->getMessage());
        $sections[$machineName] = "_Summarisation failed: " . $e->getMessage() . "_";
      }
    }

    $this->io()->writeln('  Generating TL;DR...');
    try {
      $tldr = $this->generateTldr($sections, $period, $format, $persona);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error("Failed to generate TL;DR: " . $e->getMessage());
      $tldr = NULL;
    }

    $newsletter = $this->assembleNewsletter($sections, $tldr, $period, $since, $until, $format);

    $ext = $format === 'plain' ? 'txt' : 'md';
    $suffix = $persona === 'ceo' ? '-ceo' : '-dev';
    $outputFile = $options['output'] ?? $this->resolveOutputPath($period, $since, $ext, $suffix);
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
   * Calls the LLM to summarise a single module's activity data.
   *
   * @param array $module
   *   Module data array as returned by NewsletterDataFetcherService.
   * @param string $period
   *   Human-readable period label (e.g. "7d").
   * @param string $since
   *   ISO 8601 start datetime string.
   * @param string $until
   *   ISO 8601 end datetime string.
   * @param string $format
   *   Output format: "markdown" or "plain".
   * @param string $persona
   *   Target audience: "developer" or "ceo".
   *
   * @return string
   *   LLM-generated summary text.
   */
  private function summariseModule(array $module, string $period, string $since, string $until, string $format, string $persona): string {
    $machineName = $module['machine_name'];
    $title = $module['title'] ?? $machineName;
    $issues = $module['issues'] ?? [];
    $mrs = $module['merge_requests'] ?? [];
    $commits = $module['commits'] ?? [];

    // Build a compact text representation to keep the prompt lean.
    $confidentialCount = 0;
    $issueLines = [];
    foreach ($issues as $i) {
      if (!empty($i['confidential'])) {
        $confidentialCount++;
        continue;
      }
      $assignees = $i['assignees'] ? implode(', ', $i['assignees']) : 'unassigned';
      $labels = $i['labels'] ? implode(', ', array_slice($i['labels'], 0, 4)) : '';
      $drupalRef = $i['drupal_issue_number'] ? " [#{$i['drupal_issue_number']}]({$i['drupal_url']})" : '';
      $issueLines[] = "- [{$i['title']}]({$i['web_url']}){$drupalRef} | {$i['state']} | {$assignees} | comments: {$i['comment_count']} | {$labels}";
      foreach ($i['comments'] ?? [] as $comment) {
        $date = substr($comment['created_at'], 0, 10);
        $snippet = mb_substr(trim($comment['body']), 0, 300);
        $issueLines[] = "  [{$comment['author']} {$date}]: {$snippet}";
      }
    }

    $mrLines = [];
    foreach ($mrs as $mr) {
      $merged = $mr['merged_at'] ? 'merged ' . substr($mr['merged_at'], 0, 10) : $mr['state'];
      $diffNote = isset($mr['diff_lines']) && $mr['diff_lines'] > 0 ? " | {$mr['diff_lines']} diff lines" : '';
      $mrLines[] = "- [{$mr['title']}]({$mr['web_url']}) by {$mr['author']} | {$merged} | branch: {$mr['source_branch']}{$diffNote}";
    }

    $commitLines = [];
    foreach ($commits as $c) {
      $commitLines[] = "- [{$c['short_id']}]({$c['web_url']}) {$c['title']} — {$c['author_name']} ({$c['authored_date']})";
    }

    $issueSection = $issueLines ? implode("\n", $issueLines) : '(none)';
    $mrSection = $mrLines ? implode("\n", $mrLines) : '(none)';
    $commitSection = $commitLines ? implode("\n", $commitLines) : '(none)';

    $formatInstruction = $format === 'markdown'
      ? "Format your response as Markdown. Start with the exact heading \"### $title\" then use subsections as needed."
      : 'Format your response as plain text with no Markdown.';

    $personaInstruction = match ($persona) {
      'ceo' => <<<'TXT'
You are writing for a non-technical executive audience (CEO/leadership level).
Focus on: business impact, strategic progress, risks, and what is being delivered.
Avoid technical jargon. Do not mention branch names, function names, or API details.
Explain what each piece of work means for users or the project's goals.
TXT,
      default => <<<'TXT'
You are writing for a technical developer audience.
Focus on: what was merged or shipped, specific bugs fixed, APIs changed, contributors, and what is blocking progress.
Be specific — mention function names, module names, and MR references where relevant.
TXT,
    };

    $confidentialNote = $confidentialCount > 0
      ? "Note: $confidentialCount confidential issue(s) existed in this period but have been excluded from the data below. Mention briefly at the end of your section that $confidentialCount confidential issue(s) were not included in this analysis."
      : '';

    $prompt = <<<PROMPT
You are a technical writer producing a newsletter section about recent Drupal module activity.

Module: $title (machine name: $machineName)
Period: $period ($since to $until)

$personaInstruction

Do not list every issue/MR individually — synthesise into prose. Keep it under 200 words.
Do not use emoticons or mdashes.
$confidentialNote
$formatInstruction

--- ISSUES UPDATED ($period) ---
$issueSection

--- MERGE REQUESTS ($period) ---
$mrSection

--- COMMITS ($period) ---
$commitSection
PROMPT;

    return $this->summariser->complete($prompt, ['newsletter_summarise']);
  }

  /**
   * Calls the LLM with all per-module summaries to produce a top-5 TL;DR.
   *
   * @param array $sections
   *   Keyed array of machine_name => summary text.
   * @param string $period
   *   Human-readable period label (e.g. "7d").
   * @param string $format
   *   Output format: "markdown" or "plain".
   * @param string $persona
   *   Target audience: "developer" or "ceo".
   *
   * @return string
   *   LLM-generated TL;DR text with Shipped and Ongoing sections.
   */
  private function generateTldr(array $sections, string $period, string $format, string $persona): string {
    $combined = implode("\n\n---\n\n", $sections);

    $personaInstruction = match ($persona) {
      'ceo' => 'You are writing for a non-technical executive (CEO/leadership). Focus on business impact, strategic progress, and delivery milestones. Avoid all technical jargon.',
      default => 'You are writing for a technical developer audience. Be specific — name modules, merged features, and critical bugs.',
    };

    if ($format === 'markdown') {
      $formatInstruction = <<<'TXT'
Format as two Markdown sections:

### Shipped
A numbered list of items that were completed, merged, or released. Each item must start with a bold title on the same line as the number, followed by one sentence of explanation. Example:
1. **Title here** — Explanation sentence.

### Ongoing
A numbered list of the most significant in-progress items. Same format — bold title, one sentence.

Use up to 5 items per section. Do not include any other text or headings.
TXT;
    }
    else {
      $formatInstruction = <<<'TXT'
Format as two plain text sections:

SHIPPED
A numbered list of completed or merged items. Each item starts with an ALL-CAPS title, then a dash, then one sentence.

ONGOING
A numbered list of in-progress items. Same format.

Up to 5 items per section. No Markdown.
TXT;
    }

    $prompt = <<<PROMPT
You are an editor distilling a Drupal AI project newsletter into its most important highlights.

$personaInstruction

Read all the module summaries below. Separate the highlights into two categories:
- SHIPPED: things that were merged, fixed, released, or completed during this period.
- ONGOING: things that are actively in progress, under review, or blocked.

Be specific — name the module, what happened, and why it matters.
Do not use emoticons or mdashes. Do not include any text outside the two sections.

$formatInstruction

--- MODULE SUMMARIES ---
$combined
PROMPT;

    return $this->summariser->complete($prompt, ['newsletter_tldr']);
  }

  /**
   * Assembles all per-module sections into a final newsletter document.
   *
   * Includes a navigation index after the TL;DR and injects a "View issues
   * data" link beneath each module heading.
   *
   * @param array $sections
   *   Keyed array of machine_name => summary text.
   * @param string|null $tldr
   *   Pre-generated TL;DR block, or NULL to omit.
   * @param string $period
   *   Human-readable period label (e.g. "7d").
   * @param string $since
   *   ISO 8601 start datetime string.
   * @param string $until
   *   ISO 8601 end datetime string.
   * @param string $format
   *   Output format: "markdown" or "plain".
   *
   * @return string
   *   The assembled newsletter document.
   */
  private function assembleNewsletter(array $sections, ?string $tldr, string $period, string $since, string $until, string $format): string {
    if (!$sections) {
      return $format === 'markdown'
        ? "# Drupal AI Newsletter\n\n_No module activity found for the period._"
        : "Drupal AI Newsletter\n\nNo module activity found for the period.";
    }

    $sinceDate = substr($since, 0, 10);
    $untilDate = substr($until, 0, 10);

    if ($format === 'markdown') {
      $lines = ["# Drupal AI Activity Newsletter", "", "_Period: {$sinceDate} to {$untilDate}_", ""];
      if ($tldr) {
        $lines[] = "## TL;DR";
        $lines[] = "";
        $lines[] = $tldr;
        $lines[] = "";
        $lines[] = "---";
        $lines[] = "";
      }

      // Navigation index.
      $lines[] = "## Modules";
      $lines[] = "";
      foreach ($sections as $machineName => $text) {
        $title = $machineName;
        if (preg_match('/^###\s+(.+)$/m', $text, $m)) {
          $title = trim($m[1]);
        }
        $anchor = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $lines[] = "- [$title](#$anchor)";
      }
      $lines[] = "";
      $lines[] = "---";
      $lines[] = "";

      // Per-module sections with injected data link.
      $dataBase = '/1d-data';
      foreach ($sections as $machineName => $text) {
        $sectionTitle = $machineName;
        if (preg_match('/^###\s+(.+)$/m', $text, $tm)) {
          $sectionTitle = trim($tm[1]);
        }
        $dataAnchor = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sectionTitle));
        $dataLink = "_[View issues data]({$dataBase}?id={$dataAnchor})_";
        $text = preg_replace('/^(###\s+.+)$/m', "$1\n\n{$dataLink}", $text, 1);
        $lines[] = $text;
        $lines[] = '';
      }
      return implode("\n", $lines);
    }

    $lines = ["Drupal AI Activity Newsletter", "Period: $sinceDate to $untilDate", ""];
    if ($tldr) {
      $lines[] = "TL;DR";
      $lines[] = $tldr;
      $lines[] = "";
      $lines[] = str_repeat('-', 60);
      $lines[] = "";
    }
    foreach ($sections as $name => $text) {
      $lines[] = strtoupper($name);
      $lines[] = $text;
      $lines[] = '';
    }
    return implode("\n", $lines);
  }

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
   * Builds a Markdown data overview of the fetched results with a nav index.
   *
   * Produces a navigable document listing every active module's issues, MRs,
   * and commits in full detail, including GitLab comments quoted inline.
   *
   * @param array $results
   *   Module results as returned by NewsletterDataFetcherService::fetchAllModulesData().
   * @param string $period
   *   Human-readable period label (e.g. "7d").
   * @param string $since
   *   ISO 8601 start datetime string.
   * @param string $until
   *   ISO 8601 end datetime string.
   *
   * @return string
   *   Rendered Markdown string.
   */
  private function buildDataMarkdown(array $results, string $period, string $since, string $until): string {
    $sinceDate = substr($since, 0, 10);
    $untilDate = substr($until, 0, 10);

    // Only include modules that have activity.
    $active = array_filter($results, fn($m) =>
      !empty($m['issues']) || !empty($m['merge_requests']) || !empty($m['commits'])
    );

    $lines = [
      "# Drupal AI Activity Data — $period",
      "",
      "_Period: {$sinceDate} to {$untilDate}_",
      "",
      "## Modules",
      "",
    ];

    // Navigation index.
    foreach ($active as $m) {
      $title = $m['title'] ?? $m['machine_name'];
      $anchor = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
      $issueCount = count($m['issues']);
      $mrCount = count($m['merge_requests']);
      $commitCount = count($m['commits']);
      $lines[] = "- [$title](#$anchor) — $issueCount issues, $mrCount MRs, $commitCount commits";
    }

    $lines[] = "";
    $lines[] = "---";
    $lines[] = "";

    // Per-module sections.
    foreach ($active as $m) {
      $title = $m['title'] ?? $m['machine_name'];
      $lines[] = "## $title";
      $lines[] = "";

      if ($m['issues']) {
        $lines[] = "### Issues";
        $lines[] = "";
        foreach ($m['issues'] as $i) {
          $assignees = $i['assignees'] ? implode(', ', $i['assignees']) : 'unassigned';
          $drupalRef = $i['drupal_issue_number'] ? " · [d.o #{$i['drupal_issue_number']}]({$i['drupal_url']})" : '';
          $labels = $i['labels'] ? ' · ' . implode(', ', array_slice($i['labels'], 0, 4)) : '';
          $lines[] = "- **[{$i['title']}]({$i['web_url']})**{$drupalRef} · {$i['state']} · {$assignees} · {$i['comment_count']} comments{$labels}";

          if (!empty($i['comments'])) {
            foreach ($i['comments'] as $comment) {
              $date = substr($comment['created_at'], 0, 10);
              $body = trim($comment['body']);
              // Indent multi-line comment bodies.
              $body = str_replace("\n", "\n  > ", $body);
              $lines[] = "  > **{$comment['author']}** ({$date}): {$body}";
            }
          }
        }
        $lines[] = "";
      }

      if ($m['merge_requests']) {
        $lines[] = "### Merge Requests";
        $lines[] = "";
        foreach ($m['merge_requests'] as $mr) {
          $merged = $mr['merged_at'] ? 'merged ' . substr($mr['merged_at'], 0, 10) : $mr['state'];
          $diffNote = isset($mr['diff_lines']) && $mr['diff_lines'] > 0 ? " · {$mr['diff_lines']} diff lines" : '';
          $lines[] = "- **[{$mr['title']}]({$mr['web_url']})**  · {$mr['author']} · {$merged} · `{$mr['source_branch']}`{$diffNote}";
        }
        $lines[] = "";
      }

      if ($m['commits']) {
        $lines[] = "### Commits";
        $lines[] = "";
        foreach ($m['commits'] as $c) {
          $date = substr($c['authored_date'], 0, 10);
          $lines[] = "- [`{$c['short_id']}`]({$c['web_url']}) {$c['title']} — {$c['author_name']} ({$date})";
        }
        $lines[] = "";
      }

      $lines[] = "---";
      $lines[] = "";
    }

    return implode("\n", $lines);
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

  /**
   * Resolves a default output file path under sites/default/files/issues-digest/.
   *
   * Filename format: {period}_{YYYY-MM-DD}{suffix}.{ext}
   * e.g. 24h_2026-04-24.json, 7d_2026-04-17-ceo.md
   *
   * @param string $period
   *   The period string (e.g. "24h", "7d", "30d").
   * @param string $since
   *   ISO 8601 since date (used for the date part of the filename).
   * @param string $ext
   *   File extension without dot (e.g. "json", "md", "txt").
   * @param string $suffix
   *   Optional suffix before the extension (e.g. "-ceo").
   */
  private function resolveOutputPath(string $period, string $since, string $ext, string $suffix = ''): string {
    $dir = \Drupal::service('file_system')->realpath('public://') . '/issues-digest';
    if (!is_dir($dir)) {
      mkdir($dir, 0775, TRUE);
    }
    $date = substr($since, 0, 10);
    return "$dir/{$period}_{$date}{$suffix}.{$ext}";
  }

}
