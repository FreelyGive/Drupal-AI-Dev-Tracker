<?php

namespace Drupal\issue_analysis\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\Entity\File;

/**
 * Orchestrates the daily digest: fetch, summarise, write files.
 *
 * Extracted from NewsletterCommands so both the Drush command and the admin
 * form can trigger generation without going through the CLI.
 */
class DailyDigestService {

  const STATE_LAST_RUN = 'issue_analysis.daily_digest_last_run';

  /** @var array<int, array{label: string, prompt: string}> Prompts collected during a run. */
  private array $promptLog = [];

  public function __construct(
    protected NewsletterDataFetcherService $fetcher,
    protected AiSummariserService $summariser,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
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
    $generatedLine = 'Generated: ' . $generatedAt->format('j F Y H:i') . ' GMT';

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

    // Developer + Executive newsletters.
    // Each module summary is cached in State so that if the process is killed
    // mid-run (e.g. queue worker OOM), the next invocation resumes from where
    // it left off instead of repeating all AI API calls.
    $newsletters = [];
    foreach (['developer', 'executive'] as $persona) {
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
          $sections[$machineName] = $this->loadOrSummarise($dateStr, $machineName, $persona, $mod, $period, $since->format(\DateTime::ATOM), $until->format(\DateTime::ATOM));
        }
        catch (\RuntimeException $e) {
          $sections[$machineName] = '<p><em>Summarisation failed: ' . htmlspecialchars($e->getMessage()) . '</em></p>';
        }
      }

      $log('  Generating TL;DR...');
      $tldr = $this->loadOrGenerateTldr($dateStr, $persona, $sections, $period);

      $newsletter = $this->assembleNewsletter($sections, $tldr, $period, $since->format(\DateTime::ATOM), $until->format(\DateTime::ATOM), 'html', $generatedLine);
      $newsletters[$persona] = ['newsletter' => $newsletter, 'tldr' => $tldr];
      $log("Newsletter ($persona) assembled.");
    }

    // Write prompt log and clean up files older than 7 days.
    $promptLogFile = $this->writePromptLog($period, $dateStr);
    $this->cleanupOldFiles();

    // Save digest as a Drupal node.
    $this->createDigestNode($dateStr, $jsonFile, $newsletters, $promptLogFile);

    // Clear per-module step cache now that the node is saved successfully.
    $this->clearStepCache($dateStr, $results);

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
    foreach (['developer', 'executive'] as $persona) {
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
        $sections[$mod['machine_name']] = $service->summariseModule($mod, '24h', $since, $until, 'html', $persona);
      }
      catch (\RuntimeException $e) {
        $sections[$mod['machine_name']] = '<p><em>Summarisation failed: ' . htmlspecialchars($e->getMessage()) . '</em></p>';
      }
    }

    $tldr = NULL;
    try {
      $tldr = $service->generateTldr($sections, '24h', 'html', $persona);
    }
    catch (\RuntimeException) {}

    $context['results']['sections'][$persona] = [
      'sections' => $sections,
      'tldr' => $tldr,
    ];

    // Accumulate prompt log entries across both persona passes.
    $context['results']['prompt_log'] = array_merge(
      $context['results']['prompt_log'] ?? [],
      $service->getPromptLog()
    );
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
    $generatedLine = 'Generated: ' . (new \DateTimeImmutable($generatedAt, new \DateTimeZone('UTC')))->format('j F Y H:i') . ' GMT';

    // JSON.
    $json = json_encode([
      'period' => $period,
      'since' => $since,
      'until' => $until,
      'generated_at' => $generatedAt,
      'modules' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($service->resolveOutputPath($period, $dateStr, 'json'), $json . "\n");

    // Assemble newsletters as HTML for each persona and save as a node.
    $newsletters = [];
    foreach (['developer', 'executive'] as $persona) {
      $sections = $allSections[$persona]['sections'] ?? [];
      $tldr = $allSections[$persona]['tldr'] ?? NULL;
      $newsletter = $service->assembleNewsletter($sections, $tldr, $period, $since, $until, 'html', $generatedLine);
      $newsletters[$persona] = ['newsletter' => $newsletter, 'tldr' => $tldr];
    }
    $jsonFilePath = $service->resolveOutputPath($period, $dateStr, 'json');

    // Write prompt log from entries accumulated across all persona passes.
    $promptLogPath = NULL;
    $accumulatedPrompts = $context['results']['prompt_log'] ?? [];
    if ($accumulatedPrompts) {
      $service->setPromptLog($accumulatedPrompts);
      $promptLogPath = $service->writePromptLog($period, $dateStr);
    }

    $service->cleanupOldFiles();
    $service->createDigestNode($dateStr, $jsonFilePath, $newsletters, $promptLogPath);

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
   * Returns a cached module summary or generates and caches a new one.
   */
  private function loadOrSummarise(string $dateStr, string $machineName, string $persona, array $mod, string $period, string $since, string $until): string {
    $key = "issue_analysis.digest_step.{$dateStr}.{$machineName}.{$persona}";
    $cached = $this->state->get($key);
    if ($cached !== NULL) {
      return $cached;
    }
    $result = $this->summariseModule($mod, $period, $since, $until, 'html', $persona);
    $this->state->set($key, $result);
    return $result;
  }

  /**
   * Returns a cached TL;DR or generates and caches a new one.
   */
  private function loadOrGenerateTldr(string $dateStr, string $persona, array $sections, string $period): ?string {
    $key = "issue_analysis.digest_step.{$dateStr}.tldr.{$persona}";
    $cached = $this->state->get($key);
    if ($cached !== NULL) {
      return $cached ?: NULL;
    }
    $tldr = NULL;
    try {
      $tldr = $this->generateTldr($sections, $period, 'html', $persona);
    }
    catch (\RuntimeException) {}
    $this->state->set($key, $tldr ?? '');
    return $tldr;
  }

  /**
   * Deletes per-module step cache keys after a successful run.
   */
  private function clearStepCache(string $dateStr, array $results): void {
    foreach ($results as $mod) {
      foreach (['developer', 'executive'] as $persona) {
        $this->state->delete("issue_analysis.digest_step.{$dateStr}.{$mod['machine_name']}.{$persona}");
      }
    }
    $this->state->delete("issue_analysis.digest_step.{$dateStr}.tldr.developer");
    $this->state->delete("issue_analysis.digest_step.{$dateStr}.tldr.executive");
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

  /**
   * Creates or updates a daily_digest node for the given date.
   *
   * @param string $dateStr
   *   Date string in Y-m-d format.
   * @param string $jsonFilePath
   *   Absolute path to the JSON data file.
   * @param array $newsletters
   *   Keyed by persona ('developer', 'executive'), each with 'newsletter' and 'tldr'.
   */
  public function createDigestNode(string $dateStr, string $jsonFilePath, array $newsletters, ?string $promptLogPath = NULL): void {
    $dateFormatted = (new \DateTimeImmutable($dateStr))->format('j F Y');
    $title = 'Daily Digest – ' . $dateFormatted;
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Find existing node for this date to avoid duplicates on re-runs.
    $existing = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'daily_digest')
      ->condition('title', $title)
      ->range(0, 1)
      ->execute();

    $node = $existing ? $nodeStorage->load(reset($existing)) : $nodeStorage->create(['type' => 'daily_digest']);

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $files = [];

    foreach (array_filter([$jsonFilePath, $promptLogPath]) as $path) {
      $uri = 'public://issues-digest/' . basename($path);
      $existing = $fileStorage->loadByProperties(['uri' => $uri]);
      if ($existing) {
        $files[] = ['target_id' => reset($existing)->id()];
      }
      else {
        $file = File::create(['uri' => $uri, 'status' => 1]);
        $file->save();
        $files[] = ['target_id' => $file->id()];
      }
    }

    $node->set('title', $title);
    $node->set('status', 1);
    $node->set('field_data_file', $files);

    if (isset($newsletters['executive'])) {
      $node->set('field_executive_summary', [
        'value' => $newsletters['executive']['newsletter'] ?? '',
        'summary' => $newsletters['executive']['tldr'] ?? '',
        'format' => 'content_format',
      ]);
    }
    if (isset($newsletters['developer'])) {
      $node->set('field_developer_summary', [
        'value' => $newsletters['developer']['newsletter'] ?? '',
        'summary' => $newsletters['developer']['tldr'] ?? '',
        'format' => 'content_format',
      ]);
    }

    $node->save();
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
   *   Target audience: "developer" or "executive".
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
      $assignees = $i['assignees']
        ? implode(', ', array_map(
            fn($name, $user) => $name && $name !== $user ? "$name ($user)" : $user,
            $i['assignee_names'] ?? $i['assignees'],
            $i['assignees'],
          ))
        : 'unassigned';
      $labels = $i['labels'] ? implode(', ', array_slice($i['labels'], 0, 4)) : '';
      $authorDisplay = ($i['author_name'] ?? '') && $i['author_name'] !== $i['author']
        ? "{$i['author_name']} ({$i['author']})"
        : $i['author'];
      $issueLines[] = "- [{$i['title']}]({$i['web_url']}) | {$i['state']} | author: {$authorDisplay} | assigned: {$assignees} | comments: {$i['comment_count']} | {$labels}";
      foreach ($i['comments'] ?? [] as $comment) {
        $date = substr($comment['created_at'], 0, 10);
        $snippet = mb_substr(trim($comment['body']), 0, 300);
        $commentAuthor = ($comment['author_name'] ?? '') && $comment['author_name'] !== $comment['author']
          ? "{$comment['author_name']} ({$comment['author']})"
          : $comment['author'];
        $issueLines[] = "  [{$commentAuthor} {$date}]: {$snippet}";
      }
    }

    $mrLines = [];
    foreach ($mrs as $mr) {
      $merged = $mr['merged_at'] ? 'merged ' . substr($mr['merged_at'], 0, 10) : $mr['state'];
      $diffNote = isset($mr['diff_lines']) && $mr['diff_lines'] > 0 ? " | {$mr['diff_lines']} diff lines" : '';
      $mrAuthor = ($mr['author_name'] ?? '') && $mr['author_name'] !== $mr['author']
        ? "{$mr['author_name']} ({$mr['author']})"
        : $mr['author'];
      $mrLines[] = "- [{$mr['title']}]({$mr['web_url']}) by {$mrAuthor} | {$merged} | branch: {$mr['source_branch']}{$diffNote}";
    }

    $commitLines = [];
    foreach ($commits as $c) {
      $commitLines[] = "- [{$c['short_id']}]({$c['web_url']}) {$c['title']} — {$c['author_name']} ({$c['authored_date']})";
    }

    $issueSection = $issueLines ? implode("\n", $issueLines) : '(none)';
    $mrSection = $mrLines ? implode("\n", $mrLines) : '(none)';
    $commitSection = $commitLines ? implode("\n", $commitLines) : '(none)';

    $formatInstruction = match ($format) {
      'html' => "Format your response as an HTML fragment. Start with <h3>$title</h3> then use <h4>, <p>, <ul>/<li>, and <strong> as needed. Output only the HTML fragment with no surrounding <html>, <body>, or <article> tags.",
      'markdown' => "Format your response as Markdown. Start with the exact heading \"### $title\" then use subsections as needed.",
      default => 'Format your response as plain text with no Markdown.',
    };

    $howToHelpHeading = $format === 'html' ? '<h4>How can I help on this project?</h4>' : '#### How can I help on this project?';

    [$personaInstruction, $howToHelpProjectInstruction] = match ($persona) {
      'executive' => [
        "You are writing for a non-technical executive audience (CEO/leadership level).\nFocus on: business impact, strategic progress, risks, and what is being delivered.\nAvoid technical jargon. Do not mention branch names, function names, or API details.\nExplain what each piece of work means for users or the project's goals.",
        "After the project summary prose, add a single subsection titled \"$howToHelpHeading\" aimed at a non-technical executive. Suggest 2-3 concrete, high-level ways a leader could support or unblock progress (e.g. resourcing, stakeholder alignment, decision-making, funding, advocacy). Keep it under 60 words. Do not add any other 'How can I help' text anywhere else in the section.",
      ],
      default => [
        "You are writing for a technical developer audience.\nFocus on: what was merged or shipped, specific bugs fixed, APIs changed, contributors, and what is blocking progress.\nBe specific — mention function names, module names, and MR references where relevant.",
        "After the project summary prose, add a single subsection titled \"$howToHelpHeading\" aimed at a developer. Suggest 2-3 concrete technical actions a contributor could take right now (e.g. reviewing a specific MR, picking up an unassigned issue, writing a test, or investigating a blocker). Keep it under 60 words. Do not add any other 'How can I help' text anywhere else in the section.",
      ],
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
Do not use emoticons or mdashes. Do not wrap usernames or contributor names in <strong> tags — mention them as plain text.
When mentioning a specific issue or MR, always hyperlink it using the URL provided in the data (e.g. <a href="URL">Issue Title</a> or the Markdown equivalent). Do not reference issues or MRs by number alone — always use their title as the link text.
When mentioning contributors, use the format "Real Name (username)" when a real name is available in the data, otherwise use just the username.
$confidentialNote
$formatInstruction

$howToHelpProjectInstruction

--- ISSUES UPDATED ($period) ---
$issueSection

--- MERGE REQUESTS ($period) ---
$mrSection

--- COMMITS ($period) ---
$commitSection
PROMPT;

    $this->promptLog[] = ['label' => "summariseModule:{$machineName}:{$persona}", 'prompt' => $prompt];
    $output = $this->summariser->complete($prompt, ['newsletter_summarise']);
    return $this->linkifyHtml($output, $module);
  }

  /**
   * Post-processes LLM HTML output to hyperlink issue IDs, MR IDs, commits, and usernames.
   *
   * Patterns replaced:
   *   !NNN         → GitLab MR link (web_url from data)
   *   #NNN         → GitLab issue link (iid) or drupal.org node (7-digit drupal issue number)
   *   aeae410a     → GitLab commit link (8-char hex short_id from data)
   *   @username / bare known username → drupal.org/u/username
   *
   * Replacement only happens for IDs present in the structured data — no guessing.
   */
  private function linkifyHtml(string $html, array $module): string {
    $machineName = $module['machine_name'] ?? '';
    $projectUrl = 'https://git.drupalcode.org/project/' . $machineName;

    // Build MR map: iid → web_url.
    $mrMap = [];
    foreach ($module['merge_requests'] ?? [] as $mr) {
      if (!empty($mr['iid']) && !empty($mr['web_url'])) {
        $mrMap[(int) $mr['iid']] = $mr['web_url'];
      }
    }

    // Build issue map: GitLab iid → web_url, plus drupal issue number → drupal_url.
    $issueMap = [];
    foreach ($module['issues'] ?? [] as $issue) {
      if (!empty($issue['iid']) && !empty($issue['web_url'])) {
        $issueMap[(string) $issue['iid']] = $issue['web_url'];
      }
      if (!empty($issue['drupal_issue_number']) && !empty($issue['drupal_url'])) {
        $issueMap[(string) $issue['drupal_issue_number']] = $issue['drupal_url'];
      }
    }

    // Build commit map: short_id (8-char hex) → web_url.
    $commitMap = [];
    foreach ($module['commits'] ?? [] as $commit) {
      if (!empty($commit['short_id']) && !empty($commit['web_url'])) {
        $commitMap[strtolower($commit['short_id'])] = $commit['web_url'];
      }
    }

    // Collect all known usernames (authors + assignees) and map username → real name.
    $usernames = [];
    $userRealNames = [];
    foreach (array_merge($module['issues'] ?? [], $module['merge_requests'] ?? []) as $item) {
      if (!empty($item['author'])) {
        $usernames[$item['author']] = TRUE;
        if (!empty($item['author_name']) && $item['author_name'] !== $item['author']) {
          $userRealNames[$item['author']] = $item['author_name'];
        }
      }
      foreach ($item['assignees'] ?? [] as $idx => $u) {
        if ($u) {
          $usernames[$u] = TRUE;
          $name = $item['assignee_names'][$idx] ?? '';
          if ($name && $name !== $u) {
            $userRealNames[$u] = $name;
          }
        }
      }
      foreach ($item['comments'] ?? [] as $comment) {
        if (!empty($comment['author'])) {
          $usernames[$comment['author']] = TRUE;
          if (!empty($comment['author_name']) && $comment['author_name'] !== $comment['author']) {
            $userRealNames[$comment['author']] = $comment['author_name'];
          }
        }
      }
    }
    // Commits use author_name (display name), not a username — nothing to link.

    // Replace !NNN (MR references).
    if ($mrMap) {
      $html = preg_replace_callback(
        '/(?<!["\'=\/])!(\d+)(?!["\'])/',
        function (array $m) use ($mrMap, $projectUrl): string {
          $iid = (int) $m[1];
          $url = $mrMap[$iid] ?? ($projectUrl . '/-/merge_requests/' . $iid);
          return '<a href="' . htmlspecialchars($url) . '">!' . $iid . '</a>';
        },
        $html
      );
    }

    // Replace #NNN:
    //   - Known GitLab iid → GitLab issue web_url
    //   - Known drupal issue number → drupal.org node URL
    //   - 7+ digit number (always a drupal.org issue) → drupal.org/node/NNN fallback
    //   - Short unknown number → GitLab issue URL fallback
    $html = preg_replace_callback(
      '/(?<!["\'=\/])#(\d+)(?!["\'])/',
      function (array $m) use ($issueMap, $projectUrl): string {
        $num = $m[1];
        if (isset($issueMap[$num])) {
          $url = $issueMap[$num];
        }
        elseif (strlen($num) >= 7) {
          // Long numbers are always drupal.org issue nodes.
          $url = 'https://www.drupal.org/node/' . $num;
        }
        else {
          $url = $projectUrl . '/-/issues/' . $num;
        }
        return '<a href="' . htmlspecialchars($url) . '">#' . $num . '</a>';
      },
      $html
    );

    // Replace 8-character hex commit short IDs (e.g. aeae410a).
    if ($commitMap) {
      $html = preg_replace_callback(
        '/(?<!["\'=>\/a-f0-9])([0-9a-f]{8})(?![0-9a-f"\'])/',
        function (array $m) use ($commitMap): string {
          $short = strtolower($m[1]);
          if (isset($commitMap[$short])) {
            return '<a href="' . htmlspecialchars($commitMap[$short]) . '">' . $short . '</a>';
          }
          return $m[0];
        },
        $html
      );
    }

    // Replace @username and known bare usernames with drupal.org profile links.
    if ($usernames) {
      // @username pattern.
      $html = preg_replace_callback(
        '/@([A-Za-z0-9_\-.]+)/',
        function (array $m) use ($usernames, $userRealNames): string {
          $u = $m[1];
          if (isset($usernames[$u])) {
            $url = 'https://www.drupal.org/u/' . rawurlencode($u);
            $label = isset($userRealNames[$u])
              ? htmlspecialchars($userRealNames[$u]) . ' (' . htmlspecialchars($u) . ')'
              : '@' . htmlspecialchars($u);
            return '<a href="' . $url . '">' . $label . '</a>';
          }
          return $m[0];
        },
        $html
      );

      // Bare username — only replace known usernames preceded by a space or
      // punctuation so we don't mangle words that happen to match.
      // Always link with just the username as label: the LLM already writes
      // "Real Name (username)" in prose, so prepending the name again here
      // would produce "Real Name (Real Name (username))".
      foreach (array_keys($usernames) as $u) {
        if (strlen($u) < 3) {
          continue;
        }
        $pattern = '/(?<=[\s,(>])(' . preg_quote($u, '/') . ')(?=[\s,.<()\]])(?![^<]*<\/a>)/';
        $url = 'https://www.drupal.org/u/' . rawurlencode($u);
        $link = '<a href="' . $url . '">' . htmlspecialchars($u) . '</a>';
        $html = preg_replace($pattern, $link, $html);
      }
    }

    return $html;
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
   *   Target audience: "developer" or "executive".
   *
   * @return string
   *   LLM-generated TL;DR with Shipped and Ongoing sections.
   */
  public function generateTldr(array $sections, string $period, string $format, string $persona): string {
    $combined = implode("\n\n---\n\n", $sections);

    $personaInstruction = match ($persona) {
      'executive' => 'You are writing for a non-technical executive audience. Focus on business impact, strategic progress, and delivery milestones. Avoid all technical jargon.',
      default => 'You are writing for a technical developer audience. Be specific — name modules, merged features, and critical bugs.',
    };

    $formatInstruction = match ($format) {
      'html' => "Format as two HTML sections. Use exactly this structure (all <li> elements must be inside the <ol>, never outside it):\n<h4>Shipped</h4>\n<ol>\n<li><strong>Title here</strong> &mdash; One sentence explanation.</li>\n<li><strong>Another title</strong> &mdash; One sentence explanation.</li>\n</ol>\n<h4>Ongoing</h4>\n<ol>\n<li><strong>Title here</strong> &mdash; One sentence explanation.</li>\n</ol>\nUp to 5 items per section. Do not output any text, tags, or characters outside these two sections. Output only the HTML fragment, no surrounding tags.",
      'markdown' => "Format as two Markdown sections:\n\n### Shipped\nA numbered list of items that were completed, merged, or released. Each item must start with a bold title on the same line as the number, followed by one sentence of explanation. Example:\n1. **Title here** — Explanation sentence.\n\n### Ongoing\nA numbered list of the most significant in-progress items. Same format — bold title, one sentence.\n\nUse up to 5 items per section. Do not include any other text or headings.",
      default => "Format as two plain text sections:\n\nSHIPPED\nA numbered list of completed or merged items. Each item starts with an ALL-CAPS title, then a dash, then one sentence.\n\nONGOING\nA numbered list of in-progress items. Same format.\n\nUp to 5 items per section. No Markdown.",
    };

    $prompt = <<<PROMPT
You are an editor distilling a Drupal AI project newsletter into its most important highlights.

$personaInstruction

Read all the module summaries below. Separate the highlights into two categories:
- SHIPPED: things that were merged, fixed, released, or completed during this period.
- ONGOING: things that are actively in progress, under review, or blocked.

Be specific — name the module, what happened, and why it matters.
Do not use emoticons or mdashes. Do not include any text outside the two sections.
Do not mention issue numbers (e.g. #12345) or MR IDs (e.g. !42) — they are not linked and are meaningless to readers out of context. Instead, describe what was done in plain language.

$formatInstruction

--- MODULE SUMMARIES ---
$combined
PROMPT;

    $this->promptLog[] = ['label' => "generateTldr:{$persona}", 'prompt' => $prompt];
    $output = $this->summariser->complete($prompt, ['newsletter_tldr']);

    if ($format === 'html') {
      $output = $this->sanitiseTldrHtml($output);
    }

    return $output;
  }

  /**
   * Fixes common LLM structural mistakes in TL;DR HTML output.
   *
   * Repairs <li> items that end up outside their <ol>, and removes stray
   * bare > characters that appear between block elements.
   */
  private function sanitiseTldrHtml(string $html): string {
    // Remove stray bare > or &gt; that appear between closing and opening tags.
    $html = preg_replace('/(<\/(?:ol|li|h4)>)\s*(?:>|&gt;)\s*(<(?:ol|li|h4))/i', '$1$2', $html);
    $html = preg_replace('/(<\/(?:ol|li|h4)>)\s*(?:>|&gt;)\s*$/i', '$1', $html);

    // Remove empty/unclosed <li> elements — bare <li> tags with no content
    // before the next <li>, </ol>, or end-of-string (LLM sometimes emits these).
    $html = preg_replace('/<li\b[^>]*>\s*(?=<li\b|<\/ol>)/si', '', $html);

    // Collect all <li>...</li> blocks that sit outside any <ol>.
    // Strategy: split on <ol>...</ol> blocks, collect orphan <li>s from the
    // gaps, then re-attach them to the preceding <ol>.
    $html = preg_replace_callback(
      '/(<ol[^>]*>)(.*?)(<\/ol>)(.*?)(?=<h4|<ol|$)/si',
      function (array $m): string {
        $open = $m[1];
        $inner = $m[2];
        $close = $m[3];
        $after = $m[4];

        // Pull any orphan <li> items from the text after this </ol>.
        $orphans = '';
        $after = preg_replace_callback(
          '/(<li\b[^>]*>.*?<\/li>)/si',
          function (array $li) use (&$orphans): string {
            $orphans .= $li[1];
            return '';
          },
          $after,
        );

        return $open . $inner . $orphans . $close . $after;
      },
      $html,
    );

    return $html;
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
      return match ($format) {
        'html' => '<p><em>No module activity found for the period.</em></p>',
        'markdown' => "# Drupal AI Newsletter\n\n_No module activity found for the period._",
        default => "Drupal AI Newsletter\n\nNo module activity found for the period.",
      };
    }

    $sinceDate = (new \DateTimeImmutable(substr($since, 0, 10)))->format('j F Y');
    $untilDate = (new \DateTimeImmutable(substr($until, 0, 10)))->format('j F Y');

    if ($format === 'html') {
      $parts = [];
      $first = TRUE;
      foreach ($sections as $machineName => $text) {
        if (!$first) {
          $parts[] = '<hr style="margin: 2em 0;">';
        }
        $first = FALSE;
        $projectUrl = 'https://git.drupalcode.org/project/' . htmlspecialchars($machineName);
        $projectLink = '<p class="digest-project-link"><a href="' . $projectUrl . '">View project on GitLab: ' . htmlspecialchars($machineName) . '</a></p>';
        $anchorId = 'digest-module-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($machineName));
        $parts[] = '<section class="digest-module" id="' . $anchorId . '" style="margin-bottom: 1.5em;">' . $text . $projectLink . '</section>';
      }

      // Footer: period + generated on one line.
      $metaParts = ["Period: {$sinceDate} to {$untilDate}"];
      if ($generatedLine) {
        $metaParts[] = htmlspecialchars($generatedLine);
      }
      $parts[] = '<hr style="margin: 2em 0;">';
      $parts[] = '<p class="digest-meta" style="font-size:0.85em;color:#666;">' . implode(' &nbsp;|&nbsp; ', $metaParts) . '</p>';

      return implode("\n", $parts);
    }

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

      foreach ($sections as $text) {
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
   * @deprecated No longer used — docsify removed.
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
      $anchor = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)), '-');
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
   *   Optional suffix before the extension (e.g. "-executive", "-data").
   *
   * @return string
   *   Absolute filesystem path to the output file.
   */
  /**
   * Returns all prompts collected during this service instance's lifetime.
   */
  public function getPromptLog(): array {
    return $this->promptLog;
  }

  /**
   * Replaces the internal prompt log with the given entries (used by batch).
   */
  public function setPromptLog(array $entries): void {
    $this->promptLog = $entries;
  }

  /**
   * Writes the collected prompt log to a dated file and returns its path.
   *
   * Each entry is separated by a clear header so the file is human-readable.
   * Returns NULL if there are no prompts to write.
   */
  public function writePromptLog(string $period, string $dateStr): ?string {
    if (empty($this->promptLog)) {
      return NULL;
    }

    $lines = ["# Prompt log — {$period} {$dateStr}", ""];
    foreach ($this->promptLog as $i => $entry) {
      $n = $i + 1;
      $lines[] = str_repeat('=', 72);
      $lines[] = "## [{$n}] {$entry['label']}";
      $lines[] = str_repeat('=', 72);
      $lines[] = $entry['prompt'];
      $lines[] = "";
    }

    $path = $this->resolveOutputPath($period, $dateStr, 'txt', '_prompts');
    file_put_contents($path, implode("\n", $lines));
    return $path;
  }

  /**
   * Deletes attached files from digest nodes older than 7 days, and removes
   * orphaned JSON/TXT files from the output directory.
   */
  public function cleanupOldFiles(): void {
    $cutoff = \Drupal::time()->getRequestTime() - (7 * 24 * 60 * 60);
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $fileStorage = $this->entityTypeManager->getStorage('file');

    // Remove attached files from digest nodes older than 7 days.
    $old = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'daily_digest')
      ->condition('created', $cutoff, '<')
      ->execute();

    foreach ($nodeStorage->loadMultiple($old) as $node) {
      foreach ($node->get('field_data_file') as $item) {
        $file = $item->entity;
        if ($file) {
          $realpath = \Drupal::service('file_system')->realpath($file->getFileUri());
          if ($realpath && file_exists($realpath)) {
            @unlink($realpath);
          }
          $file->delete();
        }
      }
      $node->set('field_data_file', []);
      $node->save();
    }

    // Clean up any orphaned files in the output directory.
    $dir = \Drupal::service('file_system')->realpath('public://') . '/issues-digest';
    if (is_dir($dir)) {
      foreach (glob("$dir/*.{json,txt}", GLOB_BRACE) as $file) {
        if (filemtime($file) < $cutoff) {
          @unlink($file);
          $uri = 'public://issues-digest/' . basename($file);
          foreach ($fileStorage->loadByProperties(['uri' => $uri]) as $managed) {
            $managed->delete();
          }
        }
      }
    }
  }

  public function resolveOutputPath(string $period, string $since, string $ext, string $suffix = ''): string {
    $dir = \Drupal::service('file_system')->realpath('public://') . '/issues-digest';
    if (!is_dir($dir)) {
      mkdir($dir, 0775, TRUE);
    }
    $date = substr($since, 0, 10);
    return "$dir/{$period}_{$date}{$suffix}.{$ext}";
  }

}
