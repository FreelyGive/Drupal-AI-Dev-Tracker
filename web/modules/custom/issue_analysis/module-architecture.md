# Issue Analysis Module — Architecture

## Purpose

The `issue_analysis` module fetches activity from GitLab and drupal.org for every AI-related Drupal module, summarises it using an LLM, and publishes two daily newsletters (developer and CEO) plus a raw data document to `web/issue_analysis/` for docsify rendering.

---

## Service Layer

### `AiSummariserService`

**Service ID:** `issue_analysis.summariser`

Thin wrapper around the Drupal AI provider. All other services that need to call the LLM go through this class.

- `complete(string $prompt, array $tags): string` — sends a prompt to the default chat provider and returns clean text (strips markdown code fences from the response).
- `completeJson(string $prompt, array $tags): array` — like `complete()` but additionally JSON-decodes and validates the response.

**Dependencies:** `@ai.provider` (Drupal AI module's provider plugin manager)

---

### `NewsletterDataFetcherService`

**Service ID:** `issue_analysis.newsletter_fetcher`

Responsible for collecting raw activity data from external APIs. Produces a structured array that all downstream summarisation code consumes.

Key methods:

- `fetchAllModulesData(?string $module, DateTimeImmutable $since, DateTimeImmutable $until): array` — iterates every `ai_module` node (or one specific module) and calls `fetchModuleData()` for each.
- `fetchModuleData(array $module, DateTimeImmutable $since, DateTimeImmutable $until): array` — fetches GitLab issues, MRs, and commits for a single project.
- `fetchGitLabIssues(int $projectId, ...): array` — pages through the GitLab Issues API; also fetches issue comments via the Notes API if a `gitlab_token` is configured in `settings.local.php`.
- `fetchGitLabIssueNotes(int $projectId, int $iid): array` — calls `/api/v4/projects/{id}/issues/{iid}/notes`, filters out system notes, returns `[author, created_at, body]` per comment.
- `fetchDrupalProjectTitle(string $machineName): ?string` — calls the drupal.org API to resolve the human-readable project title from its machine name.
- `gitlabHeaders(): array` — returns the `User-Agent` + `PRIVATE-TOKEN` headers used for all authenticated GitLab requests. Token is read from `Settings::get('gitlab_token', '')`.
- `static periodToDateRange(string $period): array` — converts "24h", "7d", or "30d" to a `[DateTimeImmutable $since, DateTimeImmutable $until]` tuple.
- `getAllModules(): array` — loads all published `ai_module` nodes and returns a flat array with `nid`, `title`, and `machine_name`.

**Dependencies:** `@entity_type.manager`, `@http_client`

---

### `DailyDigestService`

**Service ID:** `issue_analysis.daily_digest`

Orchestrates the full daily digest pipeline. Designed to be callable from three different entry points without code duplication: the Drush command, the admin form (via Batch API), and the cron HTTP endpoint (via Queue API).

Key methods:

- `run(?string $module, ?callable $logger): void` — synchronous full run; used by the `ia-daily` Drush command. Calls fetch → summarise (both personas) → assemble → write all files → record last run timestamp.
- `buildBatch(?string $module): array` — returns a Batch API definition with four operations (fetch, summarise-developer, summarise-ceo, finalise). Called by `DailyDigestForm`.
- `lastRunFormatted(): ?string` — reads the `issue_analysis.daily_digest_last_run` state key and returns it as "YYYY-MM-DD HH:MM GMT", or NULL.

Static batch callbacks (required by Drupal Batch API):

- `batchFetch(?string $module, array &$context)` — runs `NewsletterDataFetcherService::fetchAllModulesData()` and stores results in `$context['results']`.
- `batchSummarisePersona(string $persona, array &$context)` — reads fetched data from `$context['results']`, calls `summariseModule()` and `generateTldr()` for the given persona, stores the sections back in results.
- `batchFinalise(array &$context)` — reads all sections from results, calls `assembleNewsletter()` and `buildDataMarkdown()`, writes every output file, updates the sidebar, records last run.
- `batchFinished(bool $success, ...)` — adds a Drupal messenger status/error message.

Public helper methods (called by static batch callbacks via `\Drupal::service()`):

- `summariseModule(array $module, ...): string` — builds a compact text representation of a module's activity, crafts a persona-specific LLM prompt, and returns the generated section.
- `generateTldr(array $sections, ...): string` — sends all per-module sections to the LLM and asks for a "Shipped / Ongoing" highlights list.
- `assembleNewsletter(array $sections, ...): string` — glues sections into a full document: title, period, generated-at line, TL;DR, navigation index, per-module sections (each with an injected "View issues data" link).
- `buildDataMarkdown(array $results, ...): string` — builds the raw data document with a nav index and full issue/MR/commit listings including inline-quoted GitLab comments.
- `resolveOutputPath(string $period, string $since, string $ext, string $suffix): string` — computes the timestamped file path under `public://issues-digest/`.

**State key:** `issue_analysis.daily_digest_last_run` (ISO 8601 UTC timestamp)

**Output files written on each run:**

| File | Description |
|------|-------------|
| `public://issues-digest/24h_YYYY-MM-DD.json` | Raw structured data |
| `public://issues-digest/24h_YYYY-MM-DD-data.md` | Timestamped data document |
| `public://issues-digest/24h_YYYY-MM-DD-dev.md` | Timestamped developer newsletter |
| `public://issues-digest/24h_YYYY-MM-DD-ceo.md` | Timestamped CEO newsletter |
| `web/issue_analysis/1d-data.md` | Stable symlink-style copy for docsify |
| `web/issue_analysis/1d-summary-dev.md` | Stable copy for docsify |
| `web/issue_analysis/1d-summary-ceo.md` | Stable copy for docsify |
| `web/issue_analysis/_sidebar.md` | docsify navigation sidebar |

**Dependencies:** `@issue_analysis.newsletter_fetcher`, `@issue_analysis.summariser`, `@state`

---

### `IssueAnalysisService`

**Service ID:** `issue_analysis.service`

Original, narrower service that fetches a single drupal.org issue page by number and analyses it via LLM. Used by the `IssueAnalysisCommands` Drush command.

- `analyseIssue(string $issueNumber): array` — fetches the HTML of `drupal.org/node/{id}`, strips it to plain text, sends it to the LLM, and returns a structured result array.

**Dependencies:** `@issue_analysis.summariser`, `@http_client`

---

## Drush Commands

### `NewsletterCommands` (`ia-*`)

Entry point for newsletter generation from the CLI.

| Command | Alias | Purpose |
|---------|-------|---------|
| `issue-analysis:newsletter-fetch` | `ia-nf` | Fetch GitLab/drupal.org data for one or all modules; write JSON + data markdown |
| `issue-analysis:newsletter-summarise` | `ia-ns` | Read a JSON file produced by `ia-nf` and generate a newsletter via LLM |
| `issue-analysis:daily-digest` | `ia-daily` | One-shot: fetch + summarise both personas + write all files |
| `issue-analysis:list-modules` | `ia-lm` | List all `ai_module` nodes |

`ia-daily` delegates entirely to `DailyDigestService::run()`. `ia-nf` and `ia-ns` call `NewsletterDataFetcherService` and `AiSummariserService` directly and contain their own copies of `summariseModule`, `generateTldr`, `assembleNewsletter`, and `buildDataMarkdown` (kept for standalone CLI flexibility).

**Service ID:** `issue_analysis.newsletter_commands`

---

### `IssueAnalysisCommands`

Original Drush commands for one-off issue analysis.

**Service ID:** `issue_analysis.commands`

---

## Web Entry Points

### `DailyDigestForm` — `/admin/config/services/issue-analysis/daily-digest`

Admin form accessible to users with the `generate issue analysis newsletter` permission. Shows the last-run timestamp from `DailyDigestService::lastRunFormatted()` and a "Generate daily digest now" button. On submit calls `DailyDigestService::buildBatch()` and passes the result to `batch_set()`, so generation runs as a Drupal Batch API job with a real-time progress bar.

---

### `DailyDigestCronController` — `GET /issue-analysis/cron?token=...`

HTTP endpoint for triggering generation from an external scheduler (cron job, CI pipeline, etc.). Secured by a shared-secret token compared with `hash_equals()` against `$settings['issue_analysis_cron_token']` from `settings.local.php`. On valid request, enqueues and immediately processes one `issue_analysis_daily_digest` queue item.

---

### `DailyDigestWorker` — Queue Worker plugin

`@QueueWorker(id = "issue_analysis_daily_digest", cron = {"time" = 300})`

Processes queued digest jobs by calling `DailyDigestService::run()`. Used by both the cron endpoint and regular Drupal cron.

---

## Data Flow Diagram

```
                         ┌─────────────────────────────────┐
                         │         Trigger sources          │
                         │  CLI: ia-daily                   │
                         │  Admin form: DailyDigestForm     │
                         │  HTTP: DailyDigestCronController │
                         │  Drupal cron: DailyDigestWorker  │
                         └────────────────┬────────────────┘
                                          │
                                          ▼
                              ┌───────────────────────┐
                              │   DailyDigestService   │
                              │   run() / buildBatch() │
                              └────────────┬──────────┘
                          ┌───────────────┴──────────────────┐
                          ▼                                   ▼
           ┌──────────────────────────┐         ┌──────────────────────┐
           │ NewsletterDataFetcher    │         │  AiSummariserService  │
           │ - GitLab Issues API      │────────▶│  - LLM prompt/response│
           │ - GitLab Notes API       │         │  - via @ai.provider   │
           │ - GitLab MRs/Commits API │         └──────────────────────┘
           │ - drupal.org API (titles)│
           └──────────────────────────┘
                          │
                          ▼
              Raw JSON + per-module arrays
                          │
                          ▼
           ┌──────────────────────────────────────────────┐
           │  assembleNewsletter() / buildDataMarkdown()   │
           │  - navigation index                           │
           │  - "View issues data" links per module        │
           │  - sidebar regeneration                       │
           └──────────────────────────────────────────────┘
                          │
                          ▼
              web/issue_analysis/*.md  (served by docsify)
```

---

## Configuration

Secrets go in `web/sites/default/settings.local.php` (not committed):

```php
// GitLab personal access token — requires read_api scope.
$settings['gitlab_token'] = 'your-token-here';

// Shared secret for the cron HTTP endpoint.
$settings['issue_analysis_cron_token'] = 'change-me-to-a-strong-secret';
```

The module reads both via `Drupal\Core\Site\Settings::get()`.
