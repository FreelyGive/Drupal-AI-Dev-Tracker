# CLAUDE-BRANCH.md

## PURPOSE OF THIS FILE

**⚠️ IMPORTANT FOR FUTURE AGENTS**: This file documents the current branch's purpose, progress, and implementation plans.

- **DO NOT REMOVE THIS SECTION** - it helps orient future agents to the branch context
- **Current Branch**: `improved-site-wide-config-export`
- **Branch Goal**: Make it easier to rebuild a complete copy of the live AI Dashboard site on a local environment by improving configuration and content management workflows
- **Update the branch name above** when working on a different branch
- **See Also**: `Codex_Branch.md` - Contains feedback from ChatGPT Codex on the work done in this branch

---

## CURRENT STATUS & NEXT STEPS

### ✅ Completed (Stage 1)
- Fresh install baseline working with proper schema
- Config sync fixed (all 29 ModuleImport configs in git)
- `settings.php` config sync directory configured
- Documentation updated (README, CLAUDE.md)

### ✅ Completed (Stage 2)
**Content Export/Import System**: `aid-cexport` and `aid-cimport` commands **FULLY IMPLEMENTED AND TESTED**

**Implemented:**
1. ✅ `aid-cexport` command - exports config + all content to public files
2. ✅ `aid-cimport` command - downloads from live site + imports with NID resolution
3. ✅ All 5 content types exported/imported with portable identifiers
4. ✅ Critical NID resolution working (issue numbers → local NIDs, usernames → local contributor NIDs)
5. ✅ Tested locally with real data - 61 project-issue relationships, 9 roadmap orderings imported successfully
6. ✅ Error handling: graceful warnings for missing references, continues processing
7. ✅ Export directory added to `.gitignore`

**Commands Ready for Production:**
```bash
# On live site
ddev drush aid-cexport

# On local site
git pull  # Get config changes
ddev drush aid-cimport  # Auto-downloads from live
```

### 📋 Todo (Stage 3)
- ✅ Update documentation (README.md, CLAUDE.md) with new workflow
- Test full round-trip from live to local
- Optional: Execute remaining test plan scenarios
- Optional: Handle edge cases (files/media if projects use them)

---

## CONTENT EXPORT/IMPORT SYSTEM (Current Work)

### Overview

Two unified commands to sync complete site from live to local:

```bash
# On live site
ddev drush aid-cexport

# On local site
git pull  # Get config changes
ddev drush aid-cimport  # Auto-downloads content from live
```

### What Gets Exported/Imported

**Content Entities (nodes)**:
1. **Tag Mappings** - `ai_tag_mapping` nodes
2. **AI Projects** - `ai_project` nodes

**Content Entities (custom tables)**:
3. **Assignment History** - `assignment_record` table (historical issue assignments)

**Relationship/Metadata (custom tables)**:
4. **Project-Issue Relationships** - `ai_dashboard_project_issue` table (ordering + hierarchy)
5. **Roadmap Ordering** - `ai_dashboard_roadmap_order` table

### Export Files

**Location**: `sites/default/files/ai-exports/` (public, NOT in git)

**Files** (all use portable identifiers, not NIDs):
- `tag-mappings.json` - {source_tag, mapping_type, mapped_value, title}
- `projects.json` - {title, body, field_project_tags, field_project_deliverable_issue_number}
- `assignment-history.json` - {issue_number, assignee_username, assignee_organization, week_id, week_date, issue_status_at_assignment, assigned_date, source}
- `project-issues.json` - {project_title, issue_number, weight, indent_level, parent_issue_number}
- `roadmap-order.json` - {issue_number, column, weight}

### Key Design Decisions

✅ **Unified commands**: Just 2 commands handle both config and content
✅ **Public files**: No authentication needed (all data is public anyway)
✅ **Auto-download**: Import fetches from live site URL automatically
✅ **Portable identifiers**: Issue numbers, usernames, project titles (never NIDs)
✅ **NID resolution**: Always resolves to local NIDs during import
⚠️ **Contributors/Companies**: Keep separate CSV upload workflow
⚠️ **AI Issues**: NOT exported (re-import from drupal.org via `ai-dashboard:import-all`)

### Implementation Plan

#### Phase 1: `aid-cexport` Command

**File**: `web/modules/custom/ai_dashboard/src/Drush/Commands/AiDashboardCommands.php`

**Command**: `ai-dashboard:content-export` (alias: `aid-cexport`)

**Steps**:
1. Create export directory: `sites/default/files/ai-exports/`
2. Export configuration: Run `drush cex` via shell
3. Export tag mappings → `tag-mappings.json`
4. Export AI Projects (resolve deliverable NID → issue number) → `projects.json`
5. Export Assignment History (resolve issue/assignee NIDs → numbers/usernames) → `assignment-history.json`
6. Export Project-Issue relationships (resolve all NIDs → portable identifiers) → `project-issues.json`
7. Export Roadmap ordering (resolve issue NID → issue number) → `roadmap-order.json`
8. Output success message with public URLs

**Error handling**: Continue on partial failures, log errors

#### Phase 2: `aid-cimport` Command

**Command**: `ai-dashboard:content-import` (alias: `aid-cimport`)
**Options**: `--replace`, `--source=live|local`, `--live-url=https://...`

**Steps**:
1. **Download** (if `--source=live`):
   - Get live site URL from config (default: https://www.drupalstarforge.ai)
   - Override with `--live-url` if provided
   - Download all 5 JSON files
   - Fallback to local files if download fails, else error

2. **Import tag mappings**: Create/update nodes, clear cache

3. **Import AI Projects**:
   - Resolve deliverable_issue_number → local issue NID
   - Create/update nodes

4. **Import Assignment History** (**CRITICAL NID RESOLUTION**):
   - `issue_number` → Query local `ai_issue` by `field_issue_number` to get local NID
   - `assignee_username` → Query local `ai_contributor` by `field_drupal_username` to get local NID
   - Create/update `assignment_record` entities using **local NIDs**
   - **Never assume NIDs match between live and local**

5. **Import Project-Issue relationships**:
   - Resolve project_title → local NID, issue_number → local NID, parent_issue_number → local NID
   - Insert into `ai_dashboard_project_issue` table with hierarchy

6. **Import Roadmap ordering**:
   - Resolve issue_number → local NID
   - Insert into `ai_dashboard_roadmap_order` table

7. **Import configuration**: Run `drush cim` via shell

8. **Clear caches** and output summary

**Error handling**: Validate JSON, handle missing references gracefully, log warnings, continue processing

#### Phase 3: Configuration

**Add to `.gitignore`**:
```
web/sites/default/files/ai-exports/
```

**Create config setting**: `ai_dashboard.settings.live_site_url`

### Test Plan (11 Tests)

1. **Fresh Export** - Verify files created with valid JSON
2. **Local Import** - Restore from local files
3. **Replace Flag** - Test update vs skip behavior
4. **Export Relationships** - Verify portable identifiers used
5. **Import Relationships** - Restore table data correctly
6. **Download from Live** - Fetch files via HTTP
7. **Download Fallback** - Graceful handling when download fails
8. **Missing References** - Handle missing deliverable issues
9. **Config Integration** - Test `drush cim` runs automatically
10. **NID Resolution** - Verify live NIDs ≠ local NIDs, assignment history correct
11. **Full Round-Trip** - Complete live → local workflow

### Success Criteria

- [ ] All 11 tests pass
- [ ] Commands work with no manual intervention
- [ ] NID resolution works correctly (live NIDs ≠ local NIDs)
- [ ] Assignment history displays on issue edit pages after import
- [ ] Workflow documented in README.md
- [ ] `.gitignore` updated

---

## NOTES: FRESH INSTALL FROM THIS BRANCH

These instructions help you rebuild a local copy of the AI Dashboard from scratch.

### Step 1: Complete Cleanup
```bash
ddev stop
ddev delete -O

# Optional: Delete entire directory and clone fresh
cd ..
rm -rf Drupal-AI-Dev-Tracker
```

### Step 2: Clone Repository
```bash
git clone https://github.com/FreelyGive/Drupal-AI-Dev-Tracker.git
cd Drupal-AI-Dev-Tracker
git checkout improved-site-wide-config-export
git pull origin improved-site-wide-config-export
```

### Step 3: Start DDEV
```bash
ddev start
ddev composer install
```

### Step 4: Fresh Drupal Install
```bash
ddev drush site:install --existing-config --account-pass=admin
```

**This creates:**
- All content types, fields, views from config
- All 29 ModuleImport configurations
- `assignment_record` table with `assignee_username` and `assignee_organization` fields
- `ai_dashboard_project_issue` table
- `ai_dashboard_roadmap_order` table

### Step 5: Import Issues from Drupal.org
```bash
ddev drush ai-dashboard:import-all
```

This imports issues, processes AI Tracker metadata, applies tag mappings, syncs assignments, fetches organizations.

### Step 6: Import Contributors
1. Obtain latest contributor CSV file
2. Visit: `https://drupal-ai-dev-tracker.ddev.site/ai-dashboard/admin/contributor-import`
3. Upload CSV file

### Step 7: Verify Installation

**Login:**
- URL: `https://drupal-ai-dev-tracker.ddev.site`
- Username: `admin`
- Password: `admin`

**Test Pages:**
- `/ai-dashboard/calendar`
- `/ai-dashboard/priority-kanban`
- `/ai-dashboard/projects`
- `/ai-dashboard/roadmap`
- `/ai-dashboard/reports`

**Verify Database:**
```bash
ddev mysql -e "SHOW TABLES LIKE 'ai_dashboard%';"
ddev mysql -e "SHOW TABLES LIKE 'assignment_record';"
```

**Verify Configs:**
Visit `/admin/config/ai-dashboard/module-import` - should list ~29 configurations.

---

## TECHNICAL REFERENCE

### Update Hooks vs Fresh Installs

**Key Learning**: `drush site:install --existing-config` does NOT run update hooks.

- All schema must be in `hook_schema()` or entity base field definitions
- Update hooks only for updating existing sites, not fresh installs
- Fresh installs get schema from code, not from running historical updates

**Our Fixes:**
- Moved `assignee_username`/`assignee_organization` from update hook to entity definition
- Moved `ai_dashboard_roadmap_order` table from update hook to `hook_schema()`

### UUID Handling

**Non-Issue**: `drush site:install --existing-config` automatically adopts UUID from config files. No manual UUID fixing needed.

### Config Ignore Best Practice

Don't use Config Ignore to hide runtime data. Instead:
- Remove runtime fields from `config_export` arrays in entity definitions
- Only use Config Ignore for truly environment-specific configs

### NID Resolution Requirement

**Critical**: NIDs differ between live and local environments.

**Always resolve during import:**
- Issue numbers → local issue NIDs via `field_issue_number`
- Project titles → local project NIDs via node title
- Contributor usernames → local contributor NIDs via `field_drupal_username`
- Parent issue numbers → local parent issue NIDs via `field_issue_number`

**Never assume NIDs match** - always query by portable identifier.

---

## WHAT WAS ACCOMPLISHED (History)

### 1. Configuration Sync Fixed

**Problem**: Only 4 of 29 module import configurations were exporting to git.

**Root Cause**: Config Ignore module was blocking `ai_dashboard.module_import.*` because `last_run` field created git noise.

**Solution**:
- Removed `last_run` from `config_export` in `ModuleImport.php`
- Removed Config Ignore rule from `config/sync/config_ignore.settings.yml`
- Exported all 29 import configurations from live

**Result**: Import configurations now sync properly via git.

### 2. Fresh Install Schema Fixed

**Problem**: `drush site:install --existing-config` was missing database tables/columns.

**Missing Schema**:
- `assignee_username` and `assignee_organization` columns on `assignment_record` table
- `ai_dashboard_roadmap_order` table

**Solution**:
- Added fields to `AssignmentRecord.php` baseFieldDefinitions()
- Added `ai_dashboard_roadmap_order` to `hook_schema()` in `ai_dashboard.install`

**Result**: Fresh installs work without manual SQL fixes.

### 3. Configuration vs Content Documentation

**Accomplished**:
- Rewrote README.md with step-by-step setup instructions
- Updated CLAUDE.md to clarify config vs content distinction
- Removed duplicate content from CLAUDE.md
- All commands copy-pastable
- Added feature branch workflow instructions

**Key Concepts Documented**:
- Configuration (in git) vs Content (not in git)
- Fresh site rebuild using `drush site:install --existing-config`
- Daily development workflow
- Config export/import workflow

### 4. Config Sync Directory Standardized

**Accomplished**:
- Fixed `config_sync_directory = '../config/sync'` in `settings.php`
- Verified it works on both local (DDEV) and live (DevPanel)

### 5. Roadmap Page Crash Fixed

**Problem**: Roadmap page crashed on local database.

**Root Cause**: Missing `ai_dashboard_roadmap_order` table (was only in update hooks, not in hook_schema).

**Solution**: Added table definition to `hook_schema()` in `ai_dashboard.install`

**Result**: Fresh installs have table automatically.

---

## FILES CHANGED IN THIS BRANCH

**Core Fixes**:
- `web/modules/custom/ai_dashboard/src/Entity/ModuleImport.php` - Removed last_run
- `web/modules/custom/ai_dashboard/src/Entity/AssignmentRecord.php` - Added username/org fields
- `web/modules/custom/ai_dashboard/ai_dashboard.install` - Added roadmap_order table schema
- `web/sites/default/settings.php` - Fixed config_sync_directory setting
- `config/sync/config_ignore.settings.yml` - Removed ignore rule
- `config/sync/ai_dashboard.module_import.*.yml` - All 29 import configs

**Documentation**:
- `README.md` - Complete rewrite with setup instructions
- `CLAUDE.md` - Updated project description, streamlined
- `CLAUDE-BRANCH.md` - This file

**DDEV Config**:
- `.ddev/config.yaml` - Renamed project to `drupal-ai-dev-tracker-config-export`
- `web/sites/development.services.yml` - Restored Twig debug settings

---

## DEPLOYMENT TO LIVE

**After merging this branch to main**:
1. Pull main on live via DevPanel
2. Run `drush updb -y` (not needed, but safe)
3. Run `drush cim -y` (imports the config changes)
4. Verify import configurations exist
5. Run `drush ai-dashboard:import-all` to test

**No breaking changes** - all fixes are backwards compatible.
