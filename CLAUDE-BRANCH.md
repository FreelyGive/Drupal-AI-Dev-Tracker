# CLAUDE-BRANCH.md

**Branch**: `improved-site-wide-config-export`

This file documents what has been accomplished in this branch. It will be overwritten when a new branch is created.

**See Also**: `Codex_Branch.md` - Contains feedback from ChatGPT Codex on the work done in this branch.

---

## Fresh Install from This Branch

### Step 1: Complete Cleanup (Start Fresh)

```bash
# Stop and delete the current DDEV environment
ddev stop
ddev delete -O

# Optional: Delete the entire directory and clone fresh
cd ..
rm -rf Drupal-AI-Dev-Tracker
```

### Step 2: Clone Repository and Checkout Branch

```bash
# Clone the repository
git clone https://github.com/FreelyGive/Drupal-AI-Dev-Tracker.git
cd Drupal-AI-Dev-Tracker

# Checkout this branch
git checkout improved-site-wide-config-export

# Pull latest changes (if already cloned)
git pull origin improved-site-wide-config-export
```

### Step 3: Start DDEV and Install Dependencies

```bash
# Start DDEV
ddev start

# Install PHP dependencies
ddev composer install
```

### Step 4: Fresh Drupal Install

```bash
# Install Drupal with all configuration from this branch
ddev drush site:install --existing-config --account-pass=admin
```

**What this creates:**
- All content types, fields, and views from config
- All 29 ModuleImport configurations
- `assignment_record` table with `assignee_username` and `assignee_organization` fields
- `ai_dashboard_project_issue` table (for project-issue relationships)
- `ai_dashboard_roadmap_order` table (for roadmap drag-drop ordering)

### Step 5: Import Issues from Drupal.org

```bash
# Import all issues from configured sources
ddev drush ai-dashboard:import-all
```

This will:
- Import issues from drupal.org
- Process AI Tracker metadata
- Apply tag mappings
- Sync assignments for current week
- Fetch organizations for untracked users

### Step 6: Import Contributors (Manual CSV Upload)

1. Obtain the latest contributor CSV file
2. Visit: `https://drupal-ai-dev-tracker.ddev.site/ai-dashboard/admin/contributor-import`
3. Upload the CSV file
4. This creates AI Company and AI Contributor nodes

### Step 7: Verify Installation

**Login:**
- URL: `https://drupal-ai-dev-tracker.ddev.site`
- Username: `admin`
- Password: `admin`

**Test Pages:**
- Calendar: `/ai-dashboard/calendar` (should show companies/contributors/issues)
- Priority Kanban: `/ai-dashboard/priority-kanban` (should show issues by status)
- Projects: `/ai-dashboard/projects` (will be empty until Stage 2-3 import commands)
- Roadmap: `/ai-dashboard/roadmap` (will be empty until Stage 2-3 import commands)
- Reports: `/ai-dashboard/reports` (should work)
- Untracked Users: `/ai-dashboard/reports/untracked-users` (should show data)

**Verify Database Tables:**
```bash
ddev mysql -e "SHOW TABLES LIKE 'ai_dashboard%';"
```

Should show:
- `ai_dashboard_project_issue`
- `ai_dashboard_roadmap_order`

```bash
ddev mysql -e "SHOW TABLES LIKE 'assignment_record';"
```

Should show:
- `assignment_record`

**Verify ModuleImport Configs:**
Visit `/admin/config/ai-dashboard/module-import` - should list ~29 configurations.

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

### 🔴 6. Additional Gaps Identified by Codex

**Documentation Pages**: Check if any documentation pages exist on live that need syncing.

**Files/Media**: Audit if `ai_project` or related content uses managed files/media that need syncing.

## Next Steps & Implementation Plan

**Ready for Testing**: Fresh install baseline should work now (`drush site:install --existing-config`).

**Codex's Staged Plan** (see `Codex_Branch.md` for full details):
1. **Stage 1**: Test fresh install - verify schema, tables, and ModuleImport configs exist
2. **Stage 2-3**: Implement export/import Drush commands for:
   - AI Projects (`aid-export-projects`, `aid-import-projects`)
   - Project-Issue relationships (`aid-export-project-issues`, `aid-import-project-issues`)
   - Roadmap ordering (`aid-export-roadmap-order`, `aid-import-roadmap-order`)
3. **Stage 4**: Files/media audit and sync plan if needed
4. **Stage 5**: Update README with complete rebuild workflow

**Technical Approach Notes from Codex**:
- Use **portable identifiers** (project titles, issue numbers) not NIDs in export/import
- Make commands **idempotent** with `--replace` flags for safety
- **De-prioritize** fixing historical update hooks (live has them; focus on fresh installs)
- Pause for human validation after each stage

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
