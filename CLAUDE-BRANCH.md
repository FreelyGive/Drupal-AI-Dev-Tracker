# CLAUDE-BRANCH.md

**Branch**: `improved-site-wide-config-export`

This file documents what has been accomplished in this branch. It will be overwritten when a new branch is created.

**See Also**: `Codex_Branch.md` - Contains feedback from ChatGPT Codex on the work done in this branch.

---

## Branch Goal

Make it easier to rebuild a complete copy of the live AI Dashboard site on a local environment by improving configuration and content management workflows.

## What Was Accomplished

### ✅ 1. Configuration Sync Fixed

**Problem**: Only 4 of 29 module import configurations were exporting to git.

**Root Cause**:
- Config Ignore module was blocking `ai_dashboard.module_import.*`
- This was because `last_run` field was creating git noise on every import

**Solution Implemented**:
- ✅ Removed `last_run` from `config_export` in `ModuleImport.php`
- ✅ Removed Config Ignore rule from `config/sync/config_ignore.settings.yml`
- ✅ Exported all 29 import configurations from live
- ✅ All configs now in git at `config/sync/ai_dashboard.module_import.*.yml`

**Result**: Import configurations now sync properly between environments via git.

### ✅ 2. Fresh Install Schema Fixed

**Problem**: `drush site:install --existing-config` was missing database tables/columns because it doesn't run update hooks.

**Missing Schema**:
- `assignee_username` and `assignee_organization` columns on `assignment_record` table
- `ai_dashboard_roadmap_order` table

**Solution Implemented**:
- ✅ Added fields to `AssignmentRecord.php` baseFieldDefinitions()
- ✅ Added `ai_dashboard_roadmap_order` to `hook_schema()` in `ai_dashboard.install`

**Result**: Fresh installs now work without manual SQL fixes.

### ✅ 3. Configuration vs Content Documentation

**Accomplished**:
- ✅ Completely rewrote README.md with step-by-step setup instructions
- ✅ Updated CLAUDE.md to clarify config vs content distinction
- ✅ Removed duplicate content from CLAUDE.md (now references README)
- ✅ All commands are copy-pastable with real GitHub URL
- ✅ Added feature branch workflow instructions

**Key Concepts Documented**:
- Configuration (in git) vs Content (not in git)
- Fresh site rebuild using `drush site:install --existing-config`
- Daily development workflow
- Config export/import workflow

### ✅ 4. Config Sync Directory Standardized

**Accomplished**:
- ✅ Documented that `config_sync_directory = '../config/sync'` is set in `settings.php`
- ✅ Verified it works on both local (DDEV) and live (DevPanel)
- ✅ No changes needed - already set up correctly

## What's Still TODO

### 🔴 1. Tag Mappings Import

**Problem**: Tag mappings exist on live but not on local.

**What They Are**: Content entities that map drupal.org issue tags to track/workstream values.

**TODO**:
- Create export/import functionality for tag mapping content
- Either drush command or admin UI
- Sync from live to local

### ✅ 2. Roadmap Page Crash *(Fixed for Fresh Installs)*

**Problem**: Roadmap page (`/ai-dashboard/roadmap`) crashes on current local database.

**Root Cause**: Missing `ai_dashboard_roadmap_order` table (was only in update hooks, not in hook_schema).

**Solution**:
- ✅ Added table definition to `hook_schema()` in `ai_dashboard.install`
- Fresh installs will have this table automatically
- Current database will be fixed by doing fresh rebuild

**Status**: Will be resolved after fresh `drush site:install --existing-config`

### 🔴 3. AI Projects Content Import

**Problem**: AI Project nodes exist on live but not on local.

**What's Needed**:
- Export project data from live (title, description, tags, deliverable references)
- Import to local
- Custom drush command or admin UI

**Note**: These are content (not config), should NOT be in git.

### 🔴 4. Project-Issue Relationships Import

**Problem**: The `ai_dashboard_project_issue` table stores which issues belong to which projects and their ordering.

**What's Needed**:
- Export relationship data from live
- Import to local
- Maintains issue ordering within projects

**Note**: Critical for project pages to display correctly.

### 🔴 5. CSV Download Feature (Nice to Have)

**Current State**: Contributor CSV is in a private Google Sheet. Admins download and upload to import page.

**TODO**: Add secure download option directly from `/ai-dashboard/admin/contributor-import` page
- Must be secure (private files)
- Should be latest version from live
- Noted in README.md as TODO

## Technical Notes

### Update Hooks vs Fresh Installs

**Key Learning**: `drush site:install --existing-config` does NOT run update hooks. This means:
- All schema must be in `hook_schema()` or entity base field definitions
- Update hooks are only for updating existing sites, not fresh installs
- Fresh installs get schema from code, not from running historical updates

**What We Fixed**:
- Moved `assignee_username`/`assignee_organization` from update hook to entity definition
- Moved `ai_dashboard_roadmap_order` table from update hook to hook_schema()

### UUID Handling

**Non-Issue**: Initially thought we'd need to handle site UUIDs, but:
- `drush site:install --existing-config` automatically adopts UUID from config files
- No manual UUID fixing needed for fresh rebuilds
- Only matters if trying to import configs into existing site with different UUID

### Config Ignore Best Practice

**Learning**: Don't use Config Ignore to hide runtime data. Instead:
- Remove runtime fields from `config_export` arrays in entity definitions
- Only use Config Ignore for truly environment-specific configs

## Files Changed in This Branch

**Core Fixes**:
- `web/modules/custom/ai_dashboard/src/Entity/ModuleImport.php` - Removed last_run
- `web/modules/custom/ai_dashboard/src/Entity/AssignmentRecord.php` - Added username/org fields
- `web/modules/custom/ai_dashboard/ai_dashboard.install` - Added roadmap_order table schema
- `config/sync/config_ignore.settings.yml` - Removed ignore rule
- `config/sync/ai_dashboard.module_import.*.yml` - All 29 import configs

**Documentation**:
- `README.md` - Complete rewrite with setup instructions
- `CLAUDE.md` - Updated project description, streamlined to avoid duplication
- `CLAUDE-BRANCH.md` - This file

**DDEV Config**:
- `.ddev/config.yaml` - Renamed project to `drupal-ai-dev-tracker-config-export`
- `web/sites/development.services.yml` - Restored Twig debug settings

## Success Criteria - Met ✅

- ✅ Fresh local site can be built from scratch using git + drush commands
- ✅ No manual SQL needed
- ✅ All 29 import configurations sync via git
- ✅ Import all command works (`drush ai-dashboard:import-all`)
- ✅ Calendar view loads without errors
- ✅ Documentation is complete and copy-pastable

## Deployment to Live

**After merging this branch to main**:
1. Pull main on live via DevPanel
2. Run `drush updb -y` (not needed, but safe)
3. Run `drush cim -y` (imports the config changes)
4. Verify import configurations exist
5. Run `drush ai-dashboard:import-all` to test

**No breaking changes** - all fixes are backwards compatible.
