<?php

namespace Drupal\issue_analysis\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Fetches and summarises a Drupal.org issue page using an LLM.
 */
class IssueAnalysisService {

  public function __construct(
    protected AiSummariserService $summariser,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Analyse a drupal.org issue by its issue number.
   *
   * @param string $issueNumber
   *   The drupal.org issue number.
   *
   * @return array<string, mixed>
   *   Structured result containing issue_id, last_modified, and all fields
   *   extracted by the LLM (title, project, type, version, status, discussion,
   *   followers, created, duration, diff, changes, summary, url).
   *
   * @throws \RuntimeException
   *   When the page cannot be fetched or no AI provider is configured.
   */
  public function analyseIssue(string $issueNumber): array {
    [$pageContent, $lastModified] = $this->fetchIssuePage($issueNumber);
    $data = $this->summarise($pageContent, $issueNumber);

    return array_merge(
      ['issue_id' => $issueNumber, 'last_modified' => $lastModified],
      $data,
    );
  }

  /**
   * Fetches the plain-text content of a drupal.org issue page.
   *
   * @return array{string, string|null}
   *   Tuple of [plain-text page content with diffs appended, last modified].
   *
   * @throws \RuntimeException
   */
  private function fetchIssuePage(string $issueNumber): array {
    $url = "https://www.drupal.org/node/$issueNumber";

    try {
      $response = $this->httpClient->get($url, [
        'headers' => ['Accept' => 'text/html'],
        'timeout' => 30,
      ]);
    }
    catch (RequestException $e) {
      throw new \RuntimeException("Failed to fetch issue $issueNumber: " . $e->getMessage());
    }

    $lastModifiedHeader = $response->getHeaderLine('Last-Modified');
    $lastModified = $lastModifiedHeader
      ? (new \DateTime($lastModifiedHeader))->format(\DateTime::ATOM)
      : NULL;

    $html = (string) $response->getBody();

    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    $project = $this->parseProjectFromHtml($html);
    if ($project) {
      $diffs = $this->fetchGitLabMrDiffs($project, $issueNumber);
      if ($diffs) {
        $text .= "\n\n" . implode("\n\n", $diffs);
      }
    }

    return [$text, $lastModified];
  }

  private function parseProjectFromHtml(string $html): ?string {
    if (preg_match('/<link rel="canonical" href="https:\/\/www\.drupal\.org\/project\/([^\/]+)\/issues\//', $html, $m)) {
      return $m[1];
    }
    return NULL;
  }

  private function fetchGitLabMrDiffs(string $project, string $issueNumber): array {
    $apiUrl = sprintf(
      'https://git.drupalcode.org/api/v4/projects/project%%2F%s/merge_requests?search=%s&per_page=20',
      urlencode($project),
      urlencode($issueNumber),
    );

    try {
      $response = $this->httpClient->get($apiUrl, ['timeout' => 30]);
      $mrs = json_decode((string) $response->getBody(), TRUE);
    }
    catch (RequestException) {
      return [];
    }

    if (!is_array($mrs)) {
      return [];
    }

    $diffs = [];
    foreach ($mrs as $mr) {
      $iid = $mr['iid'] ?? NULL;
      $mrUrl = $mr['web_url'] ?? NULL;
      if (!$iid || !$mrUrl) {
        continue;
      }

      $diffUrl = sprintf(
        'https://git.drupalcode.org/project/%s/-/merge_requests/%s.diff',
        $project,
        $iid,
      );

      try {
        $diffResponse = $this->httpClient->get($diffUrl, ['timeout' => 30]);
        $diffs[] = "--- MR !$iid ($mrUrl) ---\n" . trim((string) $diffResponse->getBody());
      }
      catch (RequestException) {
        // Skip diffs that fail rather than aborting.
      }
    }

    return $diffs;
  }

  /**
   * Calls the LLM to extract structured data from issue page content.
   *
   * @return array<string, mixed>
   */
  private function summarise(string $pageContent, string $issueNumber): array {
    $prompt = <<<PROMPT
You are an expert Drupal developer analysing Drupal.org issue #$issueNumber.
Extract the information from the page content below and return ONLY a valid JSON
object with exactly these fields (use null for any field you cannot find):

{
  "title": "full issue title without the issue number",
  "project": "project name",
  "type": "issue type (e.g. Bug report, Feature request, Task)",
  "version": "version string",
  "status": "current status",
  "contributors": integer number of distinct contributors,
  "comments": integer number of comments,
  "followers": integer number of followers,
  "created": "creation date as YYYY-MM-DD",
  "duration": "human-readable time since creation (e.g. 3 months)",
  "reporter": "username of the reporter",
  "assigned": "username of the assignee or null",
  "commenters": ["list", "of", "commenter", "usernames"],
  "diff": "URL of the most recent MR diff or null",
  "changes": "summary of changed files and line counts or null",
  "summary": "concise paragraph covering the problem, current status, blockers and next steps. use clear and concise sentences, avoid buzzwords.",
  "url": "https://www.drupal.org/node/$issueNumber"
}

Do not use emoticons or mdashes. Do not include any text outside the JSON object.

Page content:
$pageContent
PROMPT;

    return $this->summariser->completeJson($prompt, ['issue_analysis']);
  }

}
