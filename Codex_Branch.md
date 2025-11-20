# Codex_Branch.md

- Branch: `improved-site-wide-config-export`

- Purpose: Branch-specific notes and feedback from Codex. If this file appears on `main`, it can be ignored. If you are on a different branch than the one named above, this document may be overwritten with notes relevant to that branch.

- Coordination with Claude: Claude maintains a similar branch note file. In this repo, an existing file was detected: `CLAUDE-BRANCH.md`. Refer to that file for Claude’s branch plan and status; this Codex file complements it.

- Status: This document is informational only; no code or configuration changes were performed while adding/updating it.

## Summary: Branch vs Main (Code Review)
- Config: 29 `ai_dashboard.module_import.*` configs added and now exported/imported (removed `config_ignore` exclusion).
- New content type: `ai_project` with fields `field_project_tags`, `field_project_deliverable`, `field_is_default_kanban_project`, plus displays.
- AI Issue fields expanded: `field_due_date` (replacing `field_issue_deadline`), `field_checkin_date`, `field_additional_collaborators`, `field_issue_summary`, `field_update_summary`, `field_short_description`, `field_track`, `field_workstream`, `field_is_meta_issue`, etc.
- Views/admin changes: removed `ai_issues_admin` and `ai_tag_mappings_admin`; updated `ai_contributors_admin`.
- `ai_dashboard.ignored_tags.yml` added (e.g., `meetup`).
- Code: `ModuleImport` no longer exports `last_run`; `AssignmentRecord` added `assignee_username` and `assignee_organization` fields.
- Schema: `ai_dashboard_roadmap_order` table added; `ai_dashboard_project_issue` confirmed in schema and update hooks.

## Install vs Update Hooks: Focus
- Priority is a clean rebuild from `main` using `--existing-config` to reproduce live.
- Update hooks correctness is lower priority (live has already applied them). Fresh installs should rely on:
  - Config in `config/sync/` (content types/fields/views/settings).
  - `hook_schema()` for custom tables: `ai_dashboard_project_issue` and `ai_dashboard_roadmap_order` (present).
  - Entity schema install for `assignment_record` (core installs base tables from entity definitions on module install).

## What Works Today for Rebuild
- `ddev drush site:install --existing-config` will create the site with all config from this branch.
- `ddev drush ai-dashboard:import-all` will import issues as ModuleImport configs now sync.
- Contributor import is manual via CSV (per README) and acceptable for now.

## Gaps To Match Live Exactly
- Missing content sync for:
  - AI Projects (nodes of type `ai_project`).
  - Project–Issue relationships (DB table `ai_dashboard_project_issue`).
  - Roadmap ordering (DB table `ai_dashboard_roadmap_order`).
  - Documentation pages (if used). 
- Files/media sync if any nodes reference files (TBD after inspection).

## Claude: Proposed Plan With Human Checkpoints

Stage 1 — Confirm Clean Install Baseline (no code changes)
- Goal: Verify a fresh install from `main` with current `config/sync/` provisions creates required schema and config.
- Claude tasks:
  - None (informational stage).
- Human checks (stop here and test):
  - Run: `ddev start && ddev composer install`.
  - Run: `ddev drush site:install --existing-config --account-pass=admin`.
  - Verify: site installs, admin login works, `ai_dashboard` enabled, and visiting `/ai-dashboard/*` pages renders without fatal errors.
  - Verify DB tables: `ai_dashboard_project_issue`, `ai_dashboard_roadmap_order` exist; `assignment_record` entity tables exist.
  - Verify ModuleImport configurations visible at `/admin/config/ai-dashboard/module-import` (should list ~29).

Stage 2 — Implement Content Export Commands (code changes)
- Goal: Export live content required to reproduce live locally.
- Claude tasks:
  - Add Drush commands under `src/Drush/Commands/`:
    - `aid-export-projects [--output=FILE]` → Export nodes of type `ai_project` with fields: title, `field_project_tags`, `field_project_deliverable` (by issue number), `field_is_default_kanban_project`.
    - `aid-export-project-issues [--output=FILE]` → Export `ai_dashboard_project_issue` rows using project title and issue number (portable, not nids) plus `weight`, `indent_level`, `parent_issue_nid` (if used).
    - `aid-export-roadmap-order [--output=FILE]` → Export `ai_dashboard_roadmap_order` using issue number and column/weight.
  - Use JSON output and stable keys; avoid environment-specific IDs.
- Human checks (stop here and test on live or a DB-copy environment):
  - Run export commands and obtain JSON files.
  - Spot-check counts vs live: number of projects; a couple of project→issue mappings; roadmap entries.
  - Save the files for next stage.

Stage 3 — Implement Content Import Commands (code changes)
- Goal: Import exported JSON into a fresh local built from `main`.
- Claude tasks:
  - Add Drush commands:
    - `aid-import-projects FILE [--update-existing]` → Create/update `ai_project` nodes; resolve deliverables by issue number.
    - `aid-import-project-issues FILE [--replace]` → Resolve projects by title and issues by number; insert/update rows in `ai_dashboard_project_issue`.
    - `aid-import-roadmap-order FILE [--replace]` → Insert/update rows in `ai_dashboard_roadmap_order`.
  - Robust validation + dry errors; no hard failures on single bad row.
- Human checks (stop here and test on a clean local):
  - After Stage 1 install + `ddev drush ai-dashboard:import-all`, run imports with JSON files.
  - Verify: `/ai-dashboard/projects`, `/ai-dashboard/priority-kanban`, `/ai-dashboard/roadmap` reflect live structure (projects present; issues appear in expected projects/columns/order).
  - Optional: re-run imports with `--replace` to confirm idempotency.

Stage 4 — Files/Media Audit (no code changes unless needed)
- Goal: Ensure no missing file/media references.
- Claude tasks:
  - Inspect if `ai_project` or related content uses managed files/media.
  - If yes, propose minimal file sync plan (e.g., bundle exported files, or scripted rsync from live).
- Human checks (stop here and test):
  - Click through project pages; confirm no broken media.
  - If broken, decide acceptable approach (DB dump, file bundle, or scripted pull) and approve Claude to implement a basic sync command or doc steps.

Stage 5 — Documentation & Readme Updates (code changes)
- Goal: Document a reliable, repeatable from-main rebuild process.
- Claude tasks:
  - Add a “Rebuild From Live” section in `README.md` with the exact order:
    1) `ddev start && ddev composer install`, 2) `drush site:install --existing-config`, 3) `drush ai-dashboard:import-all`, 4) `aid-import-projects`, 5) `aid-import-project-issues`, 6) `aid-import-roadmap-order`, 7) contributor CSV import.
  - Include notes about obtaining JSONs from live (Stage 2) and any file sync steps (Stage 4), and list verification URLs.
- Human checks (final review):
  - Follow the README steps on a new local to ensure success end-to-end.
  - Report any discrepancies to refine commands/docs.

## Notes to Claude
- De-prioritize fixing historical update hooks; focus on install-time completeness + export/import commands.
- Use project titles and issue numbers (not NIDs) in exports/imports to keep portability across environments.
- Keep commands idempotent and safe: `--replace` for destructive changes; otherwise merge/update.
- After implementing each stage, pause for human validation before proceeding.

## Open Questions
- Do any nodes include managed files/media that must be synced for a faithful copy of live?
- Are the removed admin views intentionally deprecated, or do we need replacements?
