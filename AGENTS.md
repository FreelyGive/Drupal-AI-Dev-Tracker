# AGENTS.md — Codex Operating Guide

Purpose
- Define how ChatGPT Codex operates in this repo.
- Ensure safe, repeatable starts; keep work focused and auditable.

Scope & Permissions
- Allowed edits: `AGENTS.md` and `Codex_Branch.md` only (unless the user explicitly requests code changes).
- Primary role: Review Claude’s changes, provide concise, high‑impact feedback, and capture context needed to resume work later.

Quick Reinit (Fresh Start)
- Environment:
  - `ddev start`
  - `ddev composer install`
- Install (clean DB only):
  - `ddev drush site:install --existing-config --account-pass=admin`
- Populate data:
  - Import issues: `ddev drush ai-dashboard:import-all`
  - Content sync (live→local):
    - On live: `ddev drush aid-export`
    - On local: `ddev drush aid-import` (or `--source=local`, `--replace`, `--live-url=...`)
- Dev hygiene:
  - Cache: `ddev drush cr`
  - Lint: `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom`
  - Tests: `ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist`

Review Workflow
- Read context files first: `CLAUDE.md`, `CLAUDE-BRANCH.md`, `README.md`.
- Inspect changes since last review: `git status`, `git diff`, and targeted diffs for `web/modules/custom/ai_dashboard/...` and `config/sync/`.
- Record findings in `Codex_Branch.md` under a “Concise Code Review” section.
- Only propose changes that materially improve correctness, safety, or maintainability.

Focus Areas (for Claude’s work)
- Drush commands: unique names, consistent PHP 8 Attributes, clear options/aliases.
- File handling: use Drupal File API (`prepareDirectory`, `file_save_data`) for `public://` paths.
- Avoid calling Drush from Drush: prefer `\Drush\Drush::process()` or service APIs for config import/export.
- Entity references: set via associative arrays (`['target_id' => $nid]`).
- Dependency Injection: prefer injected services over `\Drupal::service()`.
- Local DX: keep Twig debug/auto-reload in `development.services.yml` when appropriate.

Safety Rules
- Do not modify application code unless explicitly asked.
- Never commit secrets; prefer DDEV env files for local overrides.
- Use `ddev drush` for site ops; prefer config sync (`cex/cim`) over manual config edits.

Repository Quick Reference
- Structure: web root `web/`; custom code `web/modules/custom/`, `web/themes/custom/`; contrib under `web/modules/contrib/`, `web/themes/contrib/`; config in `config/`.
- Testing: PHPUnit with tests under each module at `tests/src/{Unit,Kernel,Functional}`; run via the core phpunit config.
- Coding style: Drupal/DrupalPractice standards; PSR‑4 under module `src/`; DI-friendly, small functions, avoid globals.

Hand-off Notes
- All Codex feedback lives in `Codex_Branch.md` for the active branch.
- If commands or behaviors appear to conflict (e.g., duplicate Drush command names), flag in `Codex_Branch.md` as “Must Fix” and wait for user approval before patching.

Drupal Code Analysis Prompts

Use these preset prompts to quickly audit Drupal codebases for quality, security, maintainability, and alignment with Drupal best practices. Tailor the file paths/module names as needed.

1) Repository Triage
- Prompt: “Scan this repo and list all custom modules, themes, services, plugins, Drush commands, routes, entities, schema files, and config schema files. For each, note file paths and primary responsibilities.”
- Goal: Build an index of moving parts before deep-diving.

2) Coding Standards & Style
- Prompt: “Assess adherence to Drupal Coding Standards and DrupalPractice: PSR‑4 autoloading, two‑space indentation, naming conventions, docblocks, and hook implementations. Flag violations and suggest fixes that align with drupal/coder.”
- Commands: `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom`

3) Architecture & DI
- Prompt: “Evaluate architecture: controllers thin with business logic in services; dependency injection over \Drupal::static calls; use of plugins instead of switch/if trees; appropriate use of config vs content entities; queues/batch for long tasks. Provide targeted refactors only where the gain is clear.”

4) Security Review
- Prompt: “Perform a security audit: access checks on routes/controllers; correct permission requirements; CSRF protection on forms and non‑idempotent routes; XSS protection (no unsafe #markup, use placeholders and sanitization); parameterized DB queries; safe file uploads (managed files, extensions, private scheme if sensitive); avoid storing secrets in config. Report only high‑signal issues.”

5) Cacheability & Performance
- Prompt: “Review render cache metadata: ensure cache tags/contexts/max‑age are set or bubbled; avoid cache poisoning; Views caching configured; lazy builders where appropriate; avoid heavy queries in loops; verify route/controller caching suitability. Suggest concrete improvements with rationale.”

6) Database & Schema
- Prompt: “Inspect custom tables and entity storage: schema defined in hook_schema(); update hooks present and idempotent; entity schema for content/config entities; use of EntityQuery/TypedData instead of raw SQL where possible; safe migrations/updates. Highlight risky patterns and safer alternatives.”

7) Configuration Management
- Prompt: “Validate CMI usage: default config in config/install; config schema YAML for custom config; don’t store environment‑specific values; proper use of ConfigFactory/ImmutableConfig; respect config_ignore; export/import flows documented. Point out missing schema or default config.”

8) Drush Commands
- Prompt: “Audit Drush commands for unique names/aliases, PHP 8 Attributes usage, DI of services, avoidance of calling Drush from Drush (prefer Drush::process or services), progress/output hygiene, error handling, and batch/queue usage for long operations. Note collisions or brittle exec calls.”

9) Testing Strategy
- Prompt: “Assess test coverage and appropriateness: Unit tests for pure logic; Kernel tests for entity/DB interactions; Functional Browser tests for routes/forms/permissions; fixtures and mocking for external services; deterministic assertions. Recommend a minimal set of high‑value tests to add.”
- Commands: `ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist` and `--filter <TestName>`

10) Access & Permissions
- Prompt: “Verify each route/controller exposes the correct permissions and enforces access checks at code level; confirm custom permissions strings are documented and used consistently; validate entity access handlers and node/grant logic if present.”

11) Frontend & Twig
- Prompt: “Check Twig safety: autoescape intact; avoid raw filters; pass only sanitized strings to #markup; attach libraries via *.libraries.yml; proper asset aggregation; optional Twig debug for local only.”

12) Observability & DX
- Prompt: “Evaluate logging and error handling: use of \Drupal::logger with channels; clear messages for admins; exceptions vs. soft‑fail decisions; helpful Drush output. Suggest improvements that aid support without noise.”

Combined Audit Prompt (Copy/Paste)
- Prompt: “Perform a comprehensive Drupal audit on this repository. Cover: coding standards, architecture/DI, security, cacheability/performance, database/schema, configuration management, Drush commands, testing, permissions/access, Twig safety, and observability. Cite specific files/lines, explain impact and likelihood, and propose concise, high‑ROI fixes. Limit to issues that materially affect correctness, security, or maintainability.”

Common Anti‑Patterns To Flag
- Business logic in controllers instead of services.
- Broad use of \Drupal::service() inside methods instead of DI.
- Raw SQL with concatenated parameters; lack of access checks.
- Unsafe #markup or printing user input without sanitization.
- Missing cache metadata on render arrays; Views with no caching.
- Drush commands invoking `exec('drush ...')` rather than APIs.
- Storing environment‑specific data or secrets in configuration.
- Missing config schema for custom config or YAML structures.

Reference Commands (Quick)
- Standards: `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom`
- Tests: `ddev exec vendor/bin/phpunit -c web/core/phpunit.xml.dist`
- Cache: `ddev drush cr`
- Config: `ddev drush cex -y` / `ddev drush cim -y`
