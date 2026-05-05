# Drupal Issue Analysis

Fetches activity from git.drupalcode.org and summarises it using an LLM for newsletter-style reports.

## Prerequisites

- The `ai` module installed with a default chat provider configured.
- Module enabled: `ddev drush en issue_analysis -y`
- Add the following secrets to the active `settings.php` file:
  ```php
  $settings['gitlab_token'] = 'your-token-here'; // GitLab read_api token
  ```

---

## Commands

### `ia-daily` — Daily digest (fetch + summarise in one go)

Fetches the last 24h, writes all output files to `web/issue_analysis/`.

```bash
ddev drush ia-daily
ddev drush ia-daily --module=ai   # single module for testing
```

**Output files:**
- `web/issue_analysis/1d-summary-dev.md` — developer newsletter
- `web/issue_analysis/1d-summary-executive.md` — executive newsletter
- `web/issue_analysis/1d-data.md` — raw data with navigation index (confidential issues omitted)
- `web/issue_analysis/_sidebar.md` — docsify sidebar
- `sites/default/files/issues-digest/24h_{date}.json` — raw JSON archive (includes confidential flag)
- `sites/default/files/issues-digest/24h_{date}-data.md` — data archive copy

---

### `ia-nf` — Fetch only

Fetches issues, MRs, and commits. Writes JSON + data markdown.

```bash
ddev drush ia-nf 24h
ddev drush ia-nf 7d --module=ai
ddev drush ia-nf 30d
ddev drush ia-nf 7d --since=2026-04-01 --until=2026-04-15 --module=ai
```

**Period:** `24h`, `7d`, or `30d`. Default output: `sites/default/files/issues-digest/{period}_{date}.json`. Pass `--output` to override.

Each issue includes: title, state, author, assignees, labels, drupal.org link, comment count, `confidential` flag, and all comments (author, date, body) when a GitLab token is configured. Relative GitLab image URLs are rewritten to absolute URLs. GitLab dimension attributes (`{width=N height=N}`) are converted to `<img>` tags. Notes from `drupalbot` are excluded.

Each MR includes: title, state, author, merged date, branch, `diff_lines` (line count).

---

### `ia-ns` — Summarise only

Reads a JSON file from `ia-nf` and calls the LLM per module.

```bash
ddev drush ia-ns /var/www/html/web/sites/default/files/issues-digest/7d_2026-04-17.json
ddev drush ia-ns /path/to/file.json --persona=executive
ddev drush ia-ns /path/to/file.json --module=ai --persona=developer
```

**Options:** `--persona=developer|executive`, `--module=<name>`, `--format=markdown|plain`, `--output=<path>`

Confidential issues are excluded from the LLM prompt. If any exist, the generated section will note how many were omitted.

Default output: `sites/default/files/issues-digest/{period}_{date}-dev.md` or `-executive.md`.

---

### `ia-lm` — List modules

```bash
ddev drush ia-lm
```

---

### `ia` — Analyse a single issue

```bash
ddev drush ia 3429851
```

---

## Triggering from external sources

### Admin UI

Visit `/admin/config/services/issue-analysis/daily-digest` (requires the `generate issue analysis newsletter` permission). The form shows when the digest was last generated and provides a button that runs generation via Drupal's Batch API with a real-time progress bar.

---

### Server cron (Drush)

Trigger the daily digest directly via Drush from the server's cron scheduler:

```bash
0 6 * * * cd /var/www/html && /usr/bin/drush ia-daily >> /var/log/drush.log 2>&1
```

---

## Confidential issues

Issues marked `confidential` in GitLab are:
- **Stored** in the raw JSON with `"confidential": true`
- **Excluded** from `1d-data.md` and all data documents; a note is shown if any were omitted
- **Excluded** from LLM prompts; the generated newsletter section will mention the count of omitted confidential issues

---

## Typical workflows

**Daily (recommended):**
```bash
ddev drush ia-daily
```

**Manual step-by-step:**
```bash
ddev drush ia-nf 7d
ddev drush ia-ns /var/www/html/web/sites/default/files/issues-digest/7d_2026-04-17.json
ddev drush ia-ns /var/www/html/web/sites/default/files/issues-digest/7d_2026-04-17.json --persona=executive
```
