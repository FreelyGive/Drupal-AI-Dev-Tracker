<?php

namespace Drupal\issue_analysis\Service;

use Drupal\Core\State\StateInterface;

/**
 * Orchestrates the daily digest: fetch, summarise, write files.
 *
 * Extracted from NewsletterCommands so both the Drush command and the admin
 * form can trigger generation without going through the CLI.
 */
class DailyDigestService {

  const STATE_LAST_RUN = 'issue_analysis.daily_digest_last_run';

  public function __construct(
    protected NewsletterDataFetcherService $fetcher,
    protected AiSummariserService $summariser,
    protected StateInterface $state,
  ) {}

  /**
   * Runs the full daily digest and writes all output files.
   *
   * @param string|null $module
   *   Optional machine name to limit to a single module.
   * @param callable|null $logger
   *   Optional callable(string $message) for progress feedback.
   */
  public function run(?string $module = NULL, ?callable $logger = NULL): void {
    $log = $logger ?? fn($msg) => NULL;
    set_time_limit(0);
    $period = '24h';

    [$since, $until] = NewsletterDataFetcherService::periodToDateRange($period);
    $generatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $log(sprintf('Fetching %s activity from %s to %s...', $module ? "\"$module\"" : 'all modules', $since->format('Y-m-d H:i'), $until->format('Y-m-d H:i')));

    $results = $this->fetcher->fetchAllModulesData($module, $since, $until);
    $dateStr = $since->format('Y-m-d');
    $generatedLine = '_Generated: ' . $generatedAt->format('Y-m-d H:i') . ' GMT_';

    // JSON.
    $json = json_encode([
      'period' => $period,
      'since' => $since->format(\DateTime::ATOM),
      'until' => $until->format(\DateTime::ATOM),
      'generated_at' => $generatedAt->format(\DateTime::ATOM),
      'modules' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $jsonFile = $this->resolveOutputPath($period, $dateStr, 'json');
    file_put_contents($jsonFile, $json . "\n");
    $log("Data written to $jsonFile");

    // Data markdown.
    $dataMarkdown = $this->buildDataMarkdown($results, $period, $since->format(\DateTime::ATOM), $until->format(\DateTime::ATOM), $generatedLine);
    $dataFile = $this->resolveOutputPath($period, $dateStr, 'md', '-data');
    file_put_contents($dataFile, $dataMarkdown . "\n");

    $newsletterDir = DRUPAL_ROOT . '/issue_analysis';
    file_put_contents("$newsletterDir/1d-data.md", $dataMarkdown . "\n");
    $log("Data overview written to $dataFile");

    // Dev + CEO newsletters.
    foreach (['developer', 'ceo'] as $persona) {
      $suffix = $persona === 'ceo' ? '-ceo' : '-dev';
      $log("Summarising modules ($persona persona)...");

      $sections = [];
      $log(sprintf('  Processing %d module(s)...', count($results)));
      foreach ($results as $mod) {
        $machineName = $mod['machine_name'];
        if (empty($mod['issues']) && empty($mod['merge_requests']) && empty($mod['commits'])) {
          continue;
        }
        $log(sprintf('  Summarising %s (%d issues, %d MRs, %d commits)...', $machineName, count($mod['issues']), count($mod['merge_requests']), count($mod['commits'])));
        try {
          $sections[$machineName] = $this->summariseModule($mod, $period, $since->format(\DateTime::ATOM), $until->format(\DateTime::ATOM), 'markdown', $persona);
        }
        catch (\RuntimeException $e) {
          $sections[$machineName] = "_Summarisation failed: " . $e->getMessage() . "_";
        }
      }

      $log('  Generating TL;DR...');
      $tldr = NULL;
      try {
        $tldr = $this->generateTldr($sections, $period, 'markdown', $persona);
      }
      catch (\RuntimeException) {}

      $newsletter = $this->assembleNewsletter($sections, $tldr, $period, $since->format(\DateTime::ATOM), $until->format(\DateTime::ATOM), 'markdown', $generatedLine);
      $outFile = $this->resolveOutputPath($period, $dateStr, 'md', $suffix);
      file_put_contents($outFile, $newsletter . "\n");

      $stableName = $persona === 'ceo' ? '1d-summary-ceo.md' : '1d-summary-dev.md';
      file_put_contents("$newsletterDir/$stableName", $newsletter . "\n");
      $log("Newsletter ($persona) written to $outFile");
    }

    // Sidebar.
    $sidebar = "* [Executive audience](1d-summary-ceo.md)\n* [Developer audience](1d-summary-dev.md)\n* [Data](1d-data.md)\n* [AI prompts](prompts.md)\n";
    file_put_contents("$newsletterDir/_sidebar.md", $sidebar);

    // Record last run timestamp.
    $this->state->set(self::STATE_LAST_RUN, $generatedAt->format(\DateTime::ATOM));
    $log('Done.');
  }

  /**
   * Builds a Batch API definition for the daily digest.
   *
   * Operations:
   *   1. Fetch all module data and store in tempstore.
   *   2. Summarise each active module for both personas (one op per module).
   *   3. Finalise: assemble newsletters, write all files, record last-run.
   */
  public function buildBatch(?string $module = NULL): array {
    $operations = [];

    // Step 1 — fetch.
    $operations[] = [
      [static::class, 'batchFetch'],
      [$module],
    ];

    // Steps 2 — summarise each module × persona. We don't know the module list
    // yet, so we add a single dispatcher operation that fans out internally.
    foreach (['developer', 'ceo'] as $persona) {
      $operations[] = [
        [static::class, 'batchSummarisePersona'],
        [$persona],
      ];
    }

    // Step 3 — finalise.
    $operations[] = [[static::class, 'batchFinalise'], []];

    return [
      'title' => t('Generating daily digest...'),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
      'init_message' => t('Starting daily digest generation...first step will take a while.'),
      'progress_message' => t('Completed @current of @total steps.'),
      'error_message' => t('Daily digest generation encountered an error.'),
    ];
  }

  // ---------------------------------------------------------------------------
  // Batch operation callbacks (must be static — called by the Batch API)
  // ---------------------------------------------------------------------------

  /**
   * Batch op 1: fetch all module data and store in $_SESSION-backed tempstore.
   */
  public static function batchFetch(?string $module, array &$context): void {
    set_time_limit(0);
    $context['message'] = t('Fetching GitLab activity...');

    /** @var \Drupal\issue_analysis\Service\NewsletterDataFetcherService $fetcher */
    $fetcher = \Drupal::service('issue_analysis.newsletter_fetcher');
    [$since, $until] = NewsletterDataFetcherService::periodToDateRange('24h');

    $results = $fetcher->fetchAllModulesData($module, $since, $until);

    $generatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $context['results']['results'] = $results;
    $context['results']['since'] = $since->format(\DateTime::ATOM);
    $context['results']['until'] = $until->format(\DateTime::ATOM);
    $context['results']['generated_at'] = $generatedAt->format(\DateTime::ATOM);
    $context['results']['sections'] = [];
  }

  /**
   * Batch op 2: summarise all active modules for one persona.
   */
  public static function batchSummarisePersona(string $persona, array &$context): void {
    set_time_limit(0);
    $context['message'] = t('Summarising modules (@persona persona)...', ['@persona' => $persona]);

    $results = $context['results']['results'] ?? [];
    $since = $context['results']['since'] ?? '';
    $until = $context['results']['until'] ?? '';

    /** @var \Drupal\issue_analysis\Service\AiSummariserService $summariser */
    $summariser = \Drupal::service('issue_analysis.summariser');
    $service = \Drupal::service('issue_analysis.daily_digest');

    $sections = [];
    foreach ($results as $mod) {
      if (empty($mod['issues']) && empty($mod['merge_requests']) && empty($mod['commits'])) {
        continue;
      }
      try {
        $sections[$mod['machine_name']] = $service->summariseModule($mod, '24h', $since, $until, 'markdown', $persona);
      }
      catch (\RuntimeException $e) {
        $sections[$mod['machine_name']] = '_Summarisation failed: ' . $e->getMessage() . '_';
      }
    }

    $tldr = NULL;
    try {
      $tldr = $service->generateTldr($sections, '24h', 'markdown', $persona);
    }
    catch (\RuntimeException) {}

    $context['results']['sections'][$persona] = [
      'sections' => $sections,
      'tldr' => $tldr,
    ];
  }

  /**
   * Batch op 3: assemble and write all output files.
   */
  public static function batchFinalise(array &$context): void {
    $context['message'] = t('Writing newsletter files...');

    $results = $context['results']['results'] ?? [];
    $since = $context['results']['since'] ?? '';
    $until = $context['results']['until'] ?? '';
    $generatedAt = $context['results']['generated_at'] ?? '';
    $allSections = $context['results']['sections'] ?? [];

    $service = \Drupal::service('issue_analysis.daily_digest');
    $period = '24h';
    $dateStr = substr($since, 0, 10);
    $generatedLine = '_Generated: ' . substr($generatedAt, 0, 16) . ' GMT_';
    $newsletterDir = DRUPAL_ROOT . '/issue_analysis';

    // JSON.
    $json = json_encode([
      'period' => $period,
      'since' => $since,
      'until' => $until,
      'generated_at' => $generatedAt,
      'modules' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($service->resolveOutputPath($period, $dateStr, 'json'), $json . "\n");

    // Data markdown.
    $dataMarkdown = $service->buildDataMarkdown($results, $period, $since, $until, $generatedLine);
    file_put_contents($service->resolveOutputPath($period, $dateStr, 'md', '-data'), $dataMarkdown . "\n");
    file_put_contents("$newsletterDir/1d-data.md", $dataMarkdown . "\n");

    // Newsletters.
    foreach (['developer', 'ceo'] as $persona) {
      $suffix = $persona === 'ceo' ? '-ceo' : '-dev';
      $stableName = $persona === 'ceo' ? '1d-summary-ceo.md' : '1d-summary-dev.md';
      $sections = $allSections[$persona]['sections'] ?? [];
      $tldr = $allSections[$persona]['tldr'] ?? NULL;

      $newsletter = $service->assembleNewsletter($sections, $tldr, $period, $since, $until, 'markdown', $generatedLine);
      file_put_contents($service->resolveOutputPath($period, $dateStr, 'md', $suffix), $newsletter . "\n");
      file_put_contents("$newsletterDir/$stableName", $newsletter . "\n");
    }

    // Sidebar.
    file_put_contents("$newsletterDir/_sidebar.md", "* [Executive audience](1d-summary-ceo.md)\n* [Developer audience](1d-summary-dev.md)\n* [Data](1d-data.md)\n* [AI prompts](prompts.md)\n");

    // Record last run.
    \Drupal::service('state')->set(self::STATE_LAST_RUN, $generatedAt);
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Daily digest generated successfully.'));
    }
    else {
      \Drupal::messenger()->addError(t('Daily digest generation failed. Check the error log.'));
    }
  }

  /**
   * Returns the last run timestamp as a formatted GMT string, or NULL.
   */
  public function lastRunFormatted(): ?string {
    $ts = $this->state->get(self::STATE_LAST_RUN);
    if (!$ts) {
      return NULL;
    }
    $dt = new \DateTimeImmutable($ts, new \DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i') . ' GMT';
  }

  // ---------------------------------------------------------------------------
  // Generation helpers (mirrors NewsletterCommands private methods)
  // ---------------------------------------------------------------------------

  /**
   * Calls the LLM to produce a summary section for a single module.
   *
   * @param array $module
   *   Module data array as returned by NewsletterDataFetcherService.
   * @param string $period
   *   Human-readable period label (e.g. "24h").
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
   *   LLM-generated Markdown or plain-text section.
   */
  public function summariseModule(array $module, string $period, string $since, string $until, string $format, string $persona): string {
    $machineName = $module['machine_name'];
    $title = $module['title'] ?? $machineName;
    $issues = $module['issues'] ?? [];
    $mrs = $module['merge_requests'] ?? [];
    $commits = $module['commits'] ?? [];

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
      'ceo' => "You are writing for a non-technical executive audience (CEO/leadership level).\nFocus on: business impact, strategic progress, risks, and what is being delivered.\nAvoid technical jargon. Do not mention branch names, function names, or API details.\nExplain what each piece of work means for users or the project's goals.",
      default => "You are writing for a technical developer audience.\nFocus on: what was merged or shipped, specific bugs fixed, APIs changed, contributors, and what is blocking progress.\nBe specific — mention function names, module names, and MR references where relevant.",
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
   * Calls the LLM to produce a TL;DR across all per-module summaries.
   *
   * @param array $sections
   *   Keyed array of machine_name => summary text.
   * @param string $period
   *   Human-readable period label (e.g. "24h").
   * @param string $format
   *   Output format: "markdown" or "plain".
   * @param string $persona
   *   Target audience: "developer" or "ceo".
   *
   * @return string
   *   LLM-generated TL;DR with Shipped and Ongoing sections.
   */
  public function generateTldr(array $sections, string $period, string $format, string $persona): string {
    $combined = implode("\n\n---\n\n", $sections);

    $personaInstruction = match ($persona) {
      'ceo' => 'You are writing for a non-technical executive (CEO/leadership). Focus on business impact, strategic progress, and delivery milestones. Avoid all technical jargon.',
      default => 'You are writing for a technical developer audience. Be specific — name modules, merged features, and critical bugs.',
    };

    $formatInstruction = $format === 'markdown'
      ? "Format as two Markdown sections:\n\n### Shipped\nA numbered list of items that were completed, merged, or released. Each item must start with a bold title on the same line as the number, followed by one sentence of explanation. Example:\n1. **Title here** — Explanation sentence.\n\n### Ongoing\nA numbered list of the most significant in-progress items. Same format — bold title, one sentence.\n\nUse up to 5 items per section. Do not include any other text or headings."
      : "Format as two plain text sections:\n\nSHIPPED\nA numbered list of completed or merged items. Each item starts with an ALL-CAPS title, then a dash, then one sentence.\n\nONGOING\nA numbered list of in-progress items. Same format.\n\nUp to 5 items per section. No Markdown.";

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
   * Adds a navigation index after the TL;DR and injects a "View issues data"
   * link beneath each module's ### heading.
   *
   * @param array $sections
   *   Keyed array of machine_name => summary text.
   * @param string|null $tldr
   *   Pre-generated TL;DR block, or NULL to omit.
   * @param string $period
   *   Human-readable period label (e.g. "24h").
   * @param string $since
   *   ISO 8601 start datetime string.
   * @param string $until
   *   ISO 8601 end datetime string.
   * @param string $format
   *   Output format: "markdown" or "plain".
   * @param string $generatedLine
   *   Optional italic "Generated: ..." line added after the period header.
   *
   * @return string
   *   Assembled newsletter document.
   */
  public function assembleNewsletter(array $sections, ?string $tldr, string $period, string $since, string $until, string $format, string $generatedLine = ''): string {
    if (!$sections) {
      return $format === 'markdown'
        ? "# Drupal AI Newsletter\n\n_No module activity found for the period._"
        : "Drupal AI Newsletter\n\nNo module activity found for the period.";
    }

    $sinceDate = substr($since, 0, 10);
    $untilDate = substr($until, 0, 10);

    if ($format === 'markdown') {
      $lines = ["# Drupal AI Activity Newsletter", "", "_Period: {$sinceDate} to {$untilDate}_"];
      if ($generatedLine) {
        $lines[] = $generatedLine;
      }
      $lines[] = "";
      if ($tldr) {
        $lines[] = "## TL;DR";
        $lines[] = "";
        $lines[] = $tldr;
        $lines[] = "";
        $lines[] = "---";
        $lines[] = "";
      }

      // Navigation index — one link per module section.
      $lines[] = "## Modules";
      $lines[] = "";
      foreach ($sections as $machineName => $text) {
        // Extract the ### heading the LLM wrote (first line starting with ###).
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

      // Per-module sections with injected data link under the heading.
      $dataBase = '/1d-data';
      foreach ($sections as $machineName => $text) {
        // Derive the anchor from the LLM-generated title so it matches the
        // ## heading in the data document (docsify uses the heading text).
        $sectionTitle = $machineName;
        if (preg_match('/^###\s+(.+)$/m', $text, $tm)) {
          $sectionTitle = trim($tm[1]);
        }
        $dataAnchor = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sectionTitle));
        $dataLink = "_[View issues data]({$dataBase}?id={$dataAnchor})_";
        $text = preg_replace(
          '/^(###\s+.+)$/m',
          "$1\n\n{$dataLink}",
          $text,
          1,
        );
        $lines[] = $text;
        $lines[] = '';
      }
      return implode("\n", $lines);
    }

    $lines = ["Drupal AI Activity Newsletter", "Period: $sinceDate to $untilDate"];
    if ($generatedLine) {
      $lines[] = strip_tags($generatedLine);
    }
    $lines[] = "";
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
   * Builds the Markdown data document listing all issues, MRs, and commits.
   *
   * Includes a navigation index at the top and inline-quoted GitLab comments.
   * Only modules with at least one piece of activity are included.
   *
   * @param array $results
   *   Module results from NewsletterDataFetcherService::fetchAllModulesData().
   * @param string $period
   *   Human-readable period label (e.g. "24h").
   * @param string $since
   *   ISO 8601 start datetime string.
   * @param string $until
   *   ISO 8601 end datetime string.
   * @param string $generatedLine
   *   Optional italic "Generated: ..." line added after the period header.
   *
   * @return string
   *   Rendered Markdown string.
   */
  public function buildDataMarkdown(array $results, string $period, string $since, string $until, string $generatedLine = ''): string {
    $sinceDate = substr($since, 0, 10);
    $untilDate = substr($until, 0, 10);

    $active = array_filter($results, fn($m) =>
      !empty($m['issues']) || !empty($m['merge_requests']) || !empty($m['commits'])
    );

    $lines = ["# Drupal AI Activity Data — $period", "", "_Period: {$sinceDate} to {$untilDate}_"];
    if ($generatedLine) {
      $lines[] = $generatedLine;
    }
    $lines[] = "";
    $lines[] = "## Modules";
    $lines[] = "";

    foreach ($active as $m) {
      $title = $m['title'] ?? $m['machine_name'];
      $anchor = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
      $lines[] = sprintf('- [%s](#%s) — %d issues, %d MRs, %d commits', $title, $anchor, count($m['issues']), count($m['merge_requests']), count($m['commits']));
    }

    $lines[] = "";
    $lines[] = "---";
    $lines[] = "";

    foreach ($active as $m) {
      $title = $m['title'] ?? $m['machine_name'];
      $lines[] = "## $title";
      $lines[] = "";

      $publicIssues = array_values(array_filter($m['issues'], fn($i) => empty($i['confidential'])));
      $confidentialCount = count($m['issues']) - count($publicIssues);
      if ($publicIssues) {
        $lines[] = "### Issues";
        $lines[] = "";
        foreach ($publicIssues as $i) {
          $assignees = $i['assignees'] ? implode(', ', $i['assignees']) : 'unassigned';
          $drupalRef = $i['drupal_issue_number'] ? " · [d.o #{$i['drupal_issue_number']}]({$i['drupal_url']})" : '';
          $labels = $i['labels'] ? ' · ' . implode(', ', array_slice($i['labels'], 0, 4)) : '';
          $lines[] = "- **[{$i['title']}]({$i['web_url']})**{$drupalRef} · {$i['state']} · {$assignees} · {$i['comment_count']} comments{$labels}";
          if (!empty($i['comments'])) {
            foreach ($i['comments'] as $comment) {
              $date = substr($comment['created_at'], 0, 10);
              $body = str_replace("\n", "\n  > ", trim($comment['body']));
              $lines[] = "  > **{$comment['author']}** ({$date}): {$body}";
            }
          }
        }
        if ($confidentialCount > 0) {
          $lines[] = "";
          $lines[] = "_$confidentialCount confidential issue(s) not shown._";
        }
        $lines[] = "";
      }
      elseif ($confidentialCount > 0) {
        $lines[] = "### Issues";
        $lines[] = "";
        $lines[] = "_$confidentialCount confidential issue(s) not shown._";
        $lines[] = "";
      }

      if ($m['merge_requests']) {
        $lines[] = "### Merge Requests";
        $lines[] = "";
        foreach ($m['merge_requests'] as $mr) {
          $merged = $mr['merged_at'] ? 'merged ' . substr($mr['merged_at'], 0, 10) : $mr['state'];
          $diffNote = isset($mr['diff_lines']) && $mr['diff_lines'] > 0 ? " · {$mr['diff_lines']} diff lines" : '';
          $lines[] = "- **[{$mr['title']}]({$mr['web_url']})** · {$mr['author']} · {$merged} · `{$mr['source_branch']}`{$diffNote}";
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
   * Resolves a default output file path under public://issues-digest/.
   *
   * Filename format: {period}_{YYYY-MM-DD}{suffix}.{ext}
   *
   * @param string $period
   *   The period string (e.g. "24h", "7d").
   * @param string $since
   *   Date string; only the first 10 characters (YYYY-MM-DD) are used.
   * @param string $ext
   *   File extension without dot (e.g. "json", "md").
   * @param string $suffix
   *   Optional suffix before the extension (e.g. "-ceo", "-data").
   *
   * @return string
   *   Absolute filesystem path to the output file.
   */
  public function resolveOutputPath(string $period, string $since, string $ext, string $suffix = ''): string {
    $dir = \Drupal::service('file_system')->realpath('public://') . '/issues-digest';
    if (!is_dir($dir)) {
      mkdir($dir, 0775, TRUE);
    }
    $date = substr($since, 0, 10);
    return "$dir/{$period}_{$date}{$suffix}.{$ext}";
  }

}
