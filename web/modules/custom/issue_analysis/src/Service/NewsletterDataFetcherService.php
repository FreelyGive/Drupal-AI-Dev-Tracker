<?php

namespace Drupal\issue_analysis\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Fetches drupal.org and GitLab activity for a project module over a date range.
 *
 * Issues are sourced from GitLab (reliable date filtering). MRs and commits
 * also come from GitLab. The drupal.org API is used only to resolve the
 * project NID for supplementary lookups.
 *
 * Intended for newsletter-style summaries. Returns raw structured data that
 * can be passed to an LLM for summarisation.
 */
class NewsletterDataFetcherService {

  const USER_AGENT = 'Drupal Issue Analysis/1.0';

  const GITLAB_BASE = 'https://git.drupalcode.org/api/v4/projects/project%%2F%s';

  // All project types a drupal.org project may use (mirrors IssueImportService).
  const PROJECT_TYPES = [
    'project_module',
    'project_drupalorg',
    'project_theme',
    'project_distribution',
    'project_core',
    'project_profile',
    'project_general',
    'project_theme_engine',
    'project_translation',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Returns all published ai_module nodes with their machine names.
   *
   * @return array<int, array{nid: int, title: string, machine_name: string}>
   */
  public function getAllModules(): array {
    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->condition('type', 'ai_module')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!$nids) {
      return [];
    }

    $modules = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $machineName = trim($node->get('field_module_machine_name')->value ?? '');
      if (!$machineName) {
        continue;
      }
      $modules[] = [
        'nid' => (int) $node->id(),
        'title' => trim($node->label() ?? '') ?: $machineName,
        'machine_name' => $machineName,
      ];
    }

    return $modules;
  }

  /**
   * Fetches all activity data for a single module within the given date range.
   *
   * @param string $machineName
   *   The drupal.org / GitLab project machine name (e.g. "ai").
   * @param \DateTimeImmutable $since
   *   Fetch activity updated at or after this datetime.
   * @param \DateTimeImmutable $until
   *   Fetch activity updated before or at this datetime.
   *
   * @return array{
   *   machine_name: string,
   *   since: string,
   *   until: string,
   *   issues: array,
   *   merge_requests: array,
   *   commits: array,
   *   errors: string[]
   * }
   */
  public function fetchModuleData(string $machineName, \DateTimeImmutable $since, \DateTimeImmutable $until, string $title = ''): array {
    $result = [
      'machine_name' => $machineName,
      'title' => $title ?: $machineName,
      'since' => $since->format(\DateTime::ATOM),
      'until' => $until->format(\DateTime::ATOM),
      'issues' => [],
      'merge_requests' => [],
      'commits' => [],
      'errors' => [],
    ];

    // GitLab issues have reliable date-based filtering and richer metadata
    // than the drupal.org API for the purpose of newsletter summaries.
    $result['issues'] = $this->fetchGitLabIssues($machineName, $since, $until, $result['errors']);
    $result['merge_requests'] = $this->fetchGitLabMergeRequests($machineName, $since, $until, $result['errors']);
    $result['commits'] = $this->fetchGitLabCommits($machineName, $since, $until, $result['errors']);

    return $result;
  }

  /**
   * Fetches data for all ai_module nodes, optionally limited to one.
   *
   * @param string|null $machineName
   *   If provided, only fetch for this module. Otherwise fetch all.
   * @param \DateTimeImmutable $since
   *   Start of the date range.
   * @param \DateTimeImmutable $until
   *   End of the date range.
   *
   * @return array<int, array>
   *   One entry per module with the same shape as fetchModuleData().
   */
  public function fetchAllModulesData(?string $machineName, \DateTimeImmutable $since, \DateTimeImmutable $until): array {
    if ($machineName) {
      $title = $this->fetchDrupalProjectTitle($machineName) ?? $machineName;
      return [$this->fetchModuleData($machineName, $since, $until, $title)];
    }

    $results = [];
    foreach ($this->getAllModules() as $module) {
      $title = $this->fetchDrupalProjectTitle($module['machine_name']) ?? $module['title'];
      $results[] = $this->fetchModuleData($module['machine_name'], $since, $until, $title);
      // Rate-limit between modules to be a good API citizen.
      usleep(500000);
    }

    return $results;
  }

  /**
   * Builds a DateTimeImmutable pair for a named newsletter period.
   *
   * @param string $period
   *   One of: "24h", "7d", "30d".
   *
   * @return array{\DateTimeImmutable, \DateTimeImmutable}
   *   Tuple of [since, until].
   *
   * @throws \InvalidArgumentException
   */
  public static function periodToDateRange(string $period): array {
    $until = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $since = match ($period) {
      '24h' => $until->modify('-24 hours'),
      '7d'  => $until->modify('-7 days'),
      '30d' => $until->modify('-30 days'),
      default => throw new \InvalidArgumentException("Unknown period '$period'. Use: 24h, 7d, 30d."),
    };

    return [$since, $until];
  }

  /**
   * Fetches the human-readable project title from the drupal.org API.
   *
   * Tries each known project type until a result is found. Returns NULL if the
   * project cannot be resolved or the request fails.
   */
  private function fetchDrupalProjectTitle(string $machineName): ?string {
    foreach (self::PROJECT_TYPES as $type) {
      try {
        $response = $this->httpClient->get('https://www.drupal.org/api-d7/node.json', [
          'query' => [
            'field_project_machine_name' => $machineName,
            'type' => $type,
          ],
          'timeout' => 10,
          'headers' => $this->gitlabHeaders(),
        ]);
        $data = json_decode((string) $response->getBody(), TRUE);
        if (!empty($data['list'][0]['title'])) {
          return $data['list'][0]['title'];
        }
      }
      catch (RequestException) {
        // Try next type.
      }
    }
    return NULL;
  }

  // ---------------------------------------------------------------------------
  // GitLab API helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns HTTP headers for GitLab API requests, including auth if configured.
   */
  private function gitlabHeaders(): array {
    $headers = ['User-Agent' => self::USER_AGENT];
    $token = Settings::get('gitlab_token', '');
    if ($token) {
      $headers['PRIVATE-TOKEN'] = $token;
    }
    return $headers;
  }

  /**
   * Fetches GitLab work items (issues) updated within the date range.
   *
   * GitLab's `updated_after` / `updated_before` parameters are reliable,
   * unlike the drupal.org `changed` filter. Issue descriptions contain the
   * full drupal.org issue body including AI Tracker metadata.
   *
   * @return array<int, array{
   *   iid: int,
   *   title: string,
   *   state: string,
   *   author: string,
   *   assignees: string[],
   *   created_at: string,
   *   updated_at: string,
   *   closed_at: string|null,
   *   labels: string[],
   *   web_url: string,
   *   drupal_issue_number: string|null,
   *   description: string,
   *   comment_count: int,
   *   mr_count: int
   * }>
   */
  private function fetchGitLabIssues(string $project, \DateTimeImmutable $since, \DateTimeImmutable $until, array &$errors): array {
    $issues = [];
    $page = 1;

    $url = sprintf(self::GITLAB_BASE . '/issues', $project);

    while (TRUE) {
      try {
        $response = $this->httpClient->get($url, [
          'query' => [
            'updated_after' => $since->format(\DateTime::ATOM),
            'updated_before' => $until->format(\DateTime::ATOM),
            'per_page' => 50,
            'page' => $page,
            'order_by' => 'updated_at',
            'sort' => 'desc',
          ],
          'timeout' => 30,
          'headers' => $this->gitlabHeaders(),
        ]);
        $data = json_decode((string) $response->getBody(), TRUE);
      }
      catch (RequestException $e) {
        $errors[] = "GitLab issues fetch failed for '$project' (page $page): " . $e->getMessage();
        break;
      }

      if (!is_array($data) || empty($data)) {
        break;
      }

      foreach ($data as $issue) {
        $assignees = array_map(
          fn($a) => $a['username'] ?? '',
          $issue['assignees'] ?? ($issue['assignee'] ? [$issue['assignee']] : []),
        );

        $drupalIssueNumber = $this->extractDrupalIssueNumber($issue['description'] ?? '');
        $projectId = (int) ($issue['project_id'] ?? 0);
        $iid = (int) $issue['iid'];

        $issues[] = [
          'iid' => $iid,
          'confidential' => (bool) ($issue['confidential'] ?? FALSE),
          'title' => $issue['title'] ?? '',
          'state' => $issue['state'] ?? '',
          'author' => $issue['author']['username'] ?? '',
          'assignees' => array_values(array_filter($assignees)),
          'created_at' => $issue['created_at'] ?? '',
          'updated_at' => $issue['updated_at'] ?? '',
          'closed_at' => $issue['closed_at'] ?? NULL,
          'labels' => $issue['labels'] ?? [],
          'web_url' => $issue['web_url'] ?? '',
          'drupal_issue_number' => $drupalIssueNumber,
          'drupal_url' => $drupalIssueNumber
            ? 'https://www.drupal.org/node/' . $drupalIssueNumber
            : NULL,
          'description' => $this->absolutifyGitLabUrls($issue['description'] ?? '', $issue['web_url'] ?? '', $projectId),
          'comment_count' => (int) ($issue['user_notes_count'] ?? 0),
          'mr_count' => (int) ($issue['merge_requests_count'] ?? 0),
          'comments' => array_map(
            fn($c) => array_merge($c, ['body' => $this->absolutifyGitLabUrls($c['body'], $issue['web_url'] ?? '', $projectId)]),
            $projectId ? $this->fetchGitLabIssueNotes($projectId, $iid) : [],
          ),
        ];
      }

      $nextPage = $response->getHeaderLine('x-next-page');
      if ($nextPage === '' || count($data) < 50) {
        break;
      }

      $page++;
      usleep(300000);
    }

    return $issues;
  }

  /**
   * Fetches MRs updated within the date range, including their unified diffs.
   *
   * @return array<int, array{
   *   iid: int,
   *   title: string,
   *   state: string,
   *   author: string,
   *   assignees: string[],
   *   created_at: string,
   *   updated_at: string,
   *   merged_at: string|null,
   *   source_branch: string,
   *   web_url: string,
   *   labels: string[],
   *   description: string,
   *   diff: string
   * }>
   */
  private function fetchGitLabMergeRequests(string $project, \DateTimeImmutable $since, \DateTimeImmutable $until, array &$errors): array {
    $mrs = [];
    $page = 1;

    $url = sprintf(self::GITLAB_BASE . '/merge_requests', $project);

    while (TRUE) {
      try {
        $response = $this->httpClient->get($url, [
          'query' => [
            'updated_after' => $since->format(\DateTime::ATOM),
            'updated_before' => $until->format(\DateTime::ATOM),
            'per_page' => 50,
            'page' => $page,
            'order_by' => 'updated_at',
            'sort' => 'desc',
          ],
          'timeout' => 30,
          'headers' => $this->gitlabHeaders(),
        ]);
        $data = json_decode((string) $response->getBody(), TRUE);
      }
      catch (RequestException $e) {
        $errors[] = "GitLab MR fetch failed for '$project': " . $e->getMessage();
        break;
      }

      if (!is_array($data) || empty($data)) {
        break;
      }

      foreach ($data as $mr) {
        $assignees = array_map(
          fn($a) => $a['username'] ?? '',
          $mr['assignees'] ?? ($mr['assignee'] ? [$mr['assignee']] : []),
        );

        $diffLines = $this->fetchGitLabMrDiffLineCount($project, (int) $mr['iid']);
        $mrs[] = [
          'iid' => (int) $mr['iid'],
          'title' => $mr['title'] ?? '',
          'state' => $mr['state'] ?? '',
          'author' => $mr['author']['username'] ?? '',
          'assignees' => array_values(array_filter($assignees)),
          'created_at' => $mr['created_at'] ?? '',
          'updated_at' => $mr['updated_at'] ?? '',
          'merged_at' => $mr['merged_at'] ?? NULL,
          'source_branch' => $mr['source_branch'] ?? '',
          'web_url' => $mr['web_url'] ?? '',
          'labels' => $mr['labels'] ?? [],
          'description' => $mr['description'] ?? '',
          'diff_lines' => $diffLines,
        ];
      }

      $nextPage = $response->getHeaderLine('x-next-page');
      if ($nextPage === '' || count($data) < 50) {
        break;
      }

      $page++;
      usleep(300000);
    }

    return $mrs;
  }

  /**
   * Fetches commits authored within the date range.
   *
   * @return array<int, array{
   *   id: string,
   *   short_id: string,
   *   title: string,
   *   author_name: string,
   *   authored_date: string,
   *   committed_date: string,
   *   message: string,
   *   web_url: string
   * }>
   */
  private function fetchGitLabCommits(string $project, \DateTimeImmutable $since, \DateTimeImmutable $until, array &$errors): array {
    $commits = [];
    $page = 1;

    $url = sprintf(self::GITLAB_BASE . '/repository/commits', $project);

    while (TRUE) {
      try {
        $response = $this->httpClient->get($url, [
          'query' => [
            'since' => $since->format(\DateTime::ATOM),
            'until' => $until->format(\DateTime::ATOM),
            'per_page' => 50,
            'page' => $page,
          ],
          'timeout' => 30,
          'headers' => $this->gitlabHeaders(),
        ]);
        $data = json_decode((string) $response->getBody(), TRUE);
      }
      catch (RequestException $e) {
        $errors[] = "GitLab commits fetch failed for '$project': " . $e->getMessage();
        break;
      }

      if (!is_array($data) || empty($data)) {
        break;
      }

      foreach ($data as $commit) {
        $commits[] = [
          'id' => $commit['id'] ?? '',
          'short_id' => $commit['short_id'] ?? '',
          'title' => $commit['title'] ?? '',
          'author_name' => $commit['author_name'] ?? '',
          'authored_date' => $commit['authored_date'] ?? '',
          'committed_date' => $commit['committed_date'] ?? '',
          'message' => $commit['message'] ?? '',
          'web_url' => $commit['web_url'] ?? '',
        ];
      }

      $nextPage = $response->getHeaderLine('x-next-page');
      if ($nextPage === '' || count($data) < 50) {
        break;
      }

      $page++;
      usleep(300000);
    }

    return $commits;
  }

  /**
   * Fetches the unified diff for a single GitLab MR and returns its line count.
   *
   * Returns 0 on failure so a missing diff never blocks the run.
   */
  private function fetchGitLabMrDiffLineCount(string $project, int $iid): int {
    $url = sprintf(
      'https://git.drupalcode.org/project/%s/-/merge_requests/%d.diff',
      $project,
      $iid,
    );

    try {
      $response = $this->httpClient->get($url, [
        'timeout' => 30,
        'headers' => ['User-Agent' => self::USER_AGENT],
      ]);
      return substr_count((string) $response->getBody(), "\n");
    }
    catch (RequestException) {
      return 0;
    }
  }

  /**
   * Fetches non-system notes (comments) for a GitLab issue.
   *
   * Skips system notes (e.g. "mentioned in commit ...") — only returns
   * human-authored comments. Returns an empty array if the token is missing
   * or the request fails.
   *
   * @return array<int, array{author: string, created_at: string, body: string}>
   */
  private function fetchGitLabIssueNotes(int $projectId, int $iid): array {
    if (!Settings::get('gitlab_token', '')) {
      return [];
    }

    $url = "https://git.drupalcode.org/api/v4/projects/{$projectId}/issues/{$iid}/notes";
    $notes = [];
    $page = 1;

    while (TRUE) {
      try {
        $response = $this->httpClient->get($url, [
          'query' => [
            'per_page' => 50,
            'page' => $page,
            'sort' => 'asc',
          ],
          'timeout' => 30,
          'headers' => $this->gitlabHeaders(),
        ]);
        $data = json_decode((string) $response->getBody(), TRUE);
      }
      catch (RequestException) {
        break;
      }

      if (!is_array($data) || empty($data)) {
        break;
      }

      foreach ($data as $note) {
        if ($note['system'] ?? FALSE) {
          continue;
        }
        if (($note['author']['username'] ?? '') === 'drupalbot') {
          continue;
        }
        $body = $note['body'] ?? '';
        $notes[] = [
          'author' => $note['author']['username'] ?? '',
          'created_at' => $note['created_at'] ?? '',
          'body' => $body,
        ];
      }

      $nextPage = $response->getHeaderLine('x-next-page');
      if ($nextPage === '' || count($data) < 50) {
        break;
      }

      $page++;
      usleep(200000);
    }

    return $notes;
  }

  // ---------------------------------------------------------------------------
  // Misc helpers
  // ---------------------------------------------------------------------------

  /**
   * Extracts the original drupal.org issue number from a migrated GitLab issue.
   *
   * GitLab issues migrated from drupal.org include a comment like:
   *   <!-- Migrated from issue #3585690. -->
   */
  /**
   * Rewrites relative GitLab upload URLs in markdown to absolute URLs.
   *
   * GitLab stores uploaded images as /uploads/... relative to the project root.
   * When rendered outside GitLab these appear broken. This replaces them with
   * fully-qualified URLs using the project's web base.
   *
   * @param string $text
   *   Markdown text potentially containing relative image/attachment links.
   * @param string $projectWebUrl
   *   A GitLab issue or MR URL from the same project, e.g.
   *   https://git.drupalcode.org/project/ai/-/issues/1
   *
   * @return string
   *   Text with relative /uploads/... URLs replaced by absolute ones.
   */
  private function absolutifyGitLabUrls(string $text, string $projectWebUrl, int $projectId = 0): string {
    if (!$text) {
      return $text;
    }
    // GitLab has two upload URL formats:
    //   Project-scoped:  /uploads/HASH/file  (relative to project root)
    //   System-scoped:   /-/project/ID/uploads/HASH/file
    // The raw markdown only ever contains /uploads/... (relative). We rewrite
    // to the system-scoped absolute form when we have a project ID, which is
    // always correct. The project-path form (git.drupalcode.org/project/NAME)
    // produces a different hash and returns a broken URL.
    if ($projectId) {
      $base = 'https://git.drupalcode.org/-/project/' . $projectId;
    }
    else {
      $base = preg_replace('#/-/.*$#', '', $projectWebUrl);
    }
    if (!$base) {
      return $text;
    }
    $text = preg_replace('#(\(|")(/uploads/)#', '$1' . $base . '$2', $text);
    // Convert GitLab markdown images with dimension attributes to HTML <img> tags.
    // Pattern: ![alt](url){width=N height=N}
    return preg_replace_callback(
      '/!\[([^\]]*)\]\(([^)]+)\)\{width=(\d+)\s+height=(\d+)\}/',
      fn($m) => '<img src="' . $m[2] . '" alt="' . htmlspecialchars($m[1], ENT_QUOTES) . '" width="' . $m[3] . '" height="' . $m[4] . '">',
      $text,
    );
  }

  private function extractDrupalIssueNumber(string $description): ?string {
    if (preg_match('/Migrated from issue #(\d+)/', $description, $m)) {
      return $m[1];
    }
    return NULL;
  }

}
