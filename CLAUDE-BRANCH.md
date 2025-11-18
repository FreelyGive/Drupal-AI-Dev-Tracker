# CLAUDE-BRANCH.md

**Branch**: `improved-site-wide-config-export`

This file contains branch-specific guidance and documentation. It supplements the main `CLAUDE.md` file with information specific to this development branch.

---

## Branch Purpose

**Goal**: Solve configuration and content management to make it easier to rebuild a complete copy of the live site on a local environment.

### Problem Statement

Currently, rebuilding a complete copy of the live AI Dev Tracker site on a local environment is challenging because:

1. **Docs Pages**: The live site has documentation pages that need to be preserved and transferred
2. **Project Data**: Specific AI projects with their metadata need to be synced
3. **Issue-Project Relationships**: The ordering and relationships between issues on project pages need to be maintained
4. **Mixed Data Sources**: Different types of data come from different sources and require different sync strategies

### Current State - What Already Works

The following data types are already handled and **do not need changes**:

- **AI Issues from drupal.org**:
  - Imported via existing drush commands (`drush ai-dashboard:import-all`)
  - No git involvement needed
  - Already working reliably

- **Contributors**:
  - Managed via CSV spreadsheet import
  - Import via `/ai-dashboard/admin/contributor-import`
  - No git involvement needed
  - Already working reliably

- **Site Configuration** (partially working):
  - Core Drupal config exports to `config/sync/` via `drush cex`
  - Imported via `drush cim` on local environments
  - Works for most config but not all content

### What Needs to be Solved

This branch focuses on solving the following data synchronization challenges:

#### 1. Import Configurations (PRIORITY 1 - VERIFY FIRST)
**Challenge**: The AI Dashboard uses "Module Import" config entities to define how issues are imported from drupal.org. These configurations must sync between environments so that each environment knows which issues to import.

**What These Are**:
- Config entity type: `module_import`
- Define drupal.org projects to import issues from
- Specify filters (status, tags, date ranges)
- Control which imports are active

**Current Import Configurations** (in `config/sync/`):
1. **all_open_active_issues** - Imports all open issues for AI module (project #3346420)
2. **ai_integration_eca** - Imports issues for AI+ECA integration (project #3346420)
3. **experience_builder** - Imports issues for Experience Builder (project #3437806)
4. **ai_initiative_community_page** - Imports issues from AI Initiative Community page (project #3346420)

**Config File Location**: `config/sync/ai_dashboard.module_import.*.yml`

**Status**: ✅ **ROOT CAUSE FOUND - FIXED**

**The Problem**:
1. **Config Ignore** module was set to ignore `ai_dashboard.module_import.*`
2. **Why it was ignored**: The `last_run` field was in `config_export`, causing git noise every time imports ran
3. **Result**: Only 4 configs in git, but 29 exist on live

**The Fix** (completed in this branch):
1. ✅ Removed `last_run` from `config_export` in `ModuleImport.php` (no more git noise)
2. ⏳ Remove config ignore rule on live (allows configs to export)
3. ⏳ Export all 29 configs from live
4. ⏳ Commit to git

**Why This Fix is Correct**:
- Import configuration **definitions** (project IDs, filters, labels) SHOULD sync via git
- Runtime data (`last_run` timestamps) should NOT create git noise
- Removing `last_run` from export solves both problems

**Why This Is Priority 1**:
- Tests the basic config export/import workflow
- If this doesn't work, nothing else will
- Relatively simple - no custom code needed
- Quick to test and verify
- Validates the entire config sync approach before building custom solutions

#### 2. AI Project Content Type
**Challenge**: The `ai_project` content type is created programmatically via database update hooks, following the AI Dashboard module's pattern.

**Fields** (created by update hooks):
- `title` - Project name
- `field_project_tags` - Tags to filter issues for this project
- `body` - Project description
- `field_project_deliverable` - Reference to primary deliverable issue (added in update 9038)
- `field_is_default_kanban_project` - Boolean for default kanban project (added in update 9037)

**Status**: ✅ **Already Handled via Update Hooks**
- Content type created in `ai_dashboard_update_9031()` (line 3218 in ai_dashboard.install)
- Additional fields added in subsequent updates (9037, 9038, 9039)
- No config export needed - the PHP code is already in git

**How It Works**:
1. Update hook code exists in `web/modules/custom/ai_dashboard/ai_dashboard.install`
2. Code is committed to git (already done)
3. Deploy to live via git pull
4. Run `drush updb` on live → creates content type
5. Run `drush updb` on local → creates content type

**Workflow**: Code in Git → Deploy → `drush updb` creates content type

**Note**: This follows Drupal best practices for custom modules - programmatic content type creation is preferable to config export for module-defined entities.

#### 3. Documentation Pages
**Challenge**: Docs pages exist on the live site and need to be transferred to local environments.

**Questions to Explore**:
- Are these stored as Drupal nodes? What content type?
- How many docs pages exist?
- Do they have special fields or relationships?
- Are they managed by editors or developers?

**Possible Solutions**:
- Export to code (via default content module or similar)
- CSV/JSON export-import functionality
- Database table export/import
- Content staging module

#### 4. AI Project Nodes (Content)
**Challenge**: Individual AI Project nodes created on the live site need to sync to local environments. These are content, not configuration.

**What Needs to Sync**:
- Project basic info (title, description, tags)
- Project deliverable references
- Default kanban project flag
- All custom field values

**Current Status**: Content exists on live, not on local

**Status**: 🔴 **Needs New Solution**
- These are content entities (nodes), not config
- Cannot use standard config export/import
- Should NOT be in git (content, not code)

**Recommended Solution**: Custom Drush Command (Option C)

**Implementation Plan**:
```bash
# Export from live site (SSH into live, or run locally after pulling live DB)
drush aid-export-projects

# Output: projects-export.json
{
  "projects": [
    {
      "title": "Strategic Evolution",
      "tags": "Strategic Evolution, AI",
      "description": "...",
      "deliverable_issue_number": "3462817",
      "is_default_kanban": true
    },
    ...
  ]
}

# Import on local site
drush aid-import-projects projects-export.json
```

**Workflow**: Live → Export JSON → Download/Copy → Local → Import

#### 5. Issue-Project Relationships and Ordering
**Challenge**: The `ai_dashboard_project_issue` table stores manual ordering of issues within projects. This is custom data not handled by standard config export.

**What Needs to Sync**:
- Which issues belong to which projects
- The custom ordering (weight/position) of issues within each project
- Any other relationship metadata

**Current Storage**: Database table `ai_dashboard_project_issue`

**Possible Solutions**:
- Database table export/import (SQL dump/restore)
- Custom drush command to export relationships to YAML/JSON
- Custom drush command to import relationships from YAML/JSON
- UI button to export/import via admin interface

## Approach Considerations

### Option A: Export to Code (Git-based)
**How it works**: Export all content/relationships to code files, commit to git, deploy via git pull.

**Pros**:
- Version controlled
- Can see history of changes
- Standard Drupal workflow

**Cons**:
- **Requires git operations on live site** (not ideal for security/workflow)
- Large commits if content changes frequently
- Merge conflicts if multiple people edit
- Not suitable for frequently-changing content

**When to use**:
- Config entities
- Infrequently-changing content
- Content that should be version controlled

### Option B: Export/Import via Admin UI
**How it works**: Admin clicks "Export Projects" button, downloads file, uploads to local site via "Import Projects" button.

**Pros**:
- No git operations on live site
- Editor-friendly (no command line needed)
- Can be done on-demand
- Clear separation between code and content

**Cons**:
- Requires custom development (export/import controllers)
- Not version controlled
- Manual process (not automated)

**When to use**:
- Frequently-changing content
- Content managed by non-developers
- Large datasets (docs pages, project relationships)

### Option C: Drush Command Export/Import
**How it works**: Run drush command on live to export, copy file to local, run drush command to import.

**Pros**:
- Scriptable/automatable
- Developer-friendly
- Can be integrated into deployment workflow
- No git operations on live site needed

**Cons**:
- Requires SSH/terminal access
- Not editor-friendly
- Requires file transfer between environments

**When to use**:
- Developer-driven content sync
- Scheduled/automated sync processes
- Integration with existing drush-based workflows

## Recommended Strategy

Based on the AI Dashboard's current architecture and workflows:

### For Documentation Pages
**Recommendation**: Option B (Admin UI) or Option C (Drush Command)

**Reasoning**:
- Docs are content, not configuration
- Likely to change frequently
- Editors may need to sync them
- Should not require git on live site

**Implementation Plan**:
1. Identify the content type used for docs pages
2. Create export functionality (drush command and/or admin button)
3. Export format: JSON with all field data and relationships
4. Create import functionality (drush command and/or admin form)
5. Document the process in this file

### For AI Projects
**Recommendation**: Depends on entity type (TBD - need to investigate)

**If config entities**: Use standard `drush cex/cim` (already works)

**If content entities**: Use Option C (Drush Command)
- Export: `drush aid-export-projects` → creates `projects.json`
- Import: `drush aid-import-projects projects.json`

### For Issue-Project Relationships
**Recommendation**: Option C (Drush Command)

**Reasoning**:
- Custom database table data
- Not handled by standard config export
- Developer-driven workflow is appropriate
- Can be automated

**Implementation Plan**:
1. Create `drush aid-export-project-issues` command
   - Exports `ai_dashboard_project_issue` table data to JSON/YAML
   - Includes issue number, project identifier, weight/ordering
2. Create `drush aid-import-project-issues` command
   - Reads JSON/YAML file
   - Imports relationships into `ai_dashboard_project_issue` table
   - Handles conflicts (updates existing or skips)
3. Document usage in main CLAUDE.md

## Comprehensive Implementation Roadmap

### Summary of What Needs to Sync (In Priority Order)

| Priority | Data Type | Storage | Current State | Sync Method | Status |
|----------|-----------|---------|---------------|-------------|--------|
| **1** | **Import Configurations** | Config entities in `config/sync/` | ✅ In git | Standard config export/import (`drush cex/cim`) | 🔄 **VERIFY FIRST** |
| 2 | **AI Project Content Type** | Update hooks in `.install` file | ✅ In git | Update hooks run via `drush updb` | ✅ Done |
| 3 | **AI Project Nodes** | Content (database) | 🔴 Needs solution | Custom drush command export/import | **HIGH** |
| 4 | **Project-Issue Relationships** | Custom table `ai_dashboard_project_issue` | 🔴 Needs solution | Custom drush command export/import | **HIGH** |
| 5 | **Documentation Pages** | Content (database) | ❓ Unknown - needs investigation | TBD after investigation | **MEDIUM** |
| 6 | **Roadmap Ordering** | Custom table `ai_dashboard_roadmap_order` | 🔴 Needs solution | Custom drush command export/import | **MEDIUM** |
| n/a | **AI Issues** | Content (database) | ✅ Working | Existing `drush aid:import-all` from drupal.org | ✅ Done |
| n/a | **Contributors** | Content (database) | ✅ Working | Existing CSV import via UI | ✅ Done |

### Preferred Workflow Philosophy

**Avoid**:
- ❌ Making changes on live site and pushing to git from live
- ❌ Storing content in git (only config and code belong in git)
- ❌ Manual database dumps/imports (not repeatable)

**Prefer**:
- ✅ **Config changes**: Make locally → `drush cex` → commit → push → pull on live → `drush cim`
- ✅ **Content from live**: Export from live → download/copy → import to local
- ✅ **Automated/scriptable**: Drush commands, not manual UI steps
- ✅ **Reproducible**: Anyone can rebuild local from scratch following documented steps

### Development Tasks

#### Phase 1: Fix and Sync Import Configurations (CURRENT PRIORITY)
**Goal**: Get all 29 import configurations from live into git so they sync to all environments.

**Root Cause Found**: Config Ignore was blocking export because `last_run` created git noise.
**Solution**: Remove `last_run` from config export (done), remove ignore rule, export all configs.

**Steps to Complete**:

**Step 1: Deploy Code Fix to Live** ✅ DONE LOCALLY, NEEDS DEPLOYMENT
```bash
# On local branch (already done):
# - Removed last_run from ModuleImport.php config_export
# - Need to commit and deploy to live

# Commit the fix
git add web/modules/custom/ai_dashboard/src/Entity/ModuleImport.php
git commit -m "Remove last_run from ModuleImport config export to prevent git noise"
git push

# Deploy to live via DevPanel
# (Your normal deployment process)
```

**Step 2: On Live - Remove Config Ignore Rule**
```bash
# Remove the ignore pattern for module_import
drush config:set config_ignore.settings ignored_config_entities []

# Verify it's removed
drush config:get config_ignore.settings ignored_config_entities

# Export the updated config_ignore settings
drush cex -y
```

**Step 3: On Live - Verify All 29 Configs Now Export**
```bash
# Check how many module_import configs are now in config/sync
ls -1 config/sync/ai_dashboard.module_import.* | wc -l
# Should show 29, not 4

# Verify last_run is NOT in the exported files
cat config/sync/ai_dashboard.module_import.ai_agents.yml | grep last_run
# Should show nothing (last_run removed from export)
```

**Step 4: On Live - Download All Import Configs**
```bash
# Create zip of all import configurations
cd config/sync
zip import-configs-full.zip ai_dashboard.module_import.*.yml config_ignore.settings.yml

# Download via DevPanel file manager
# Or check the count first
ls -1 ai_dashboard.module_import.*.yml | wc -l
```

**Step 5: On Local - Import All Configs**
```bash
# Extract downloaded zip
cd /Users/jamesabrahams/code/ai/Drupal-AI-Dev-Tracker/improved-site-wide-config-export
unzip ~/Downloads/import-configs-full.zip -d config/sync/

# Verify you got all 29
ls -1 config/sync/ai_dashboard.module_import.*.yml | wc -l

# Import them
ddev drush cim -y

# Verify they imported
ddev drush php:eval "echo count(\Drupal::entityTypeManager()->getStorage('module_import')->loadMultiple());"
# Should show 29

# Visit UI to verify
ddev launch
# Go to /admin/config/ai-dashboard/module-import
# Should see all 29 import configurations
```

**Step 6: Commit All Configs to Git**
```bash
# Add all import configs
git add config/sync/ai_dashboard.module_import.*.yml
git add config/sync/config_ignore.settings.yml

# Check status
git status

# Commit
git commit -m "Add all 29 import configurations from live site

- Fixed ModuleImport entity to exclude last_run from export
- Removed config_ignore rule blocking module_import exports
- All import configuration definitions now sync via git
- Runtime data (last_run) no longer creates git noise"

# Push
git push
```

**Expected Results**:
- ✅ All 29 import configurations in git
- ✅ `last_run` field NOT in exported YAML files
- ✅ Import configs sync between environments via standard `drush cim/cex`
- ✅ No git noise from runtime data
- ✅ Config ignore no longer blocking imports

**Deliverables**:
- [x] Fixed ModuleImport.php locally
- [ ] Deploy fix to live
- [ ] Remove config ignore rule on live
- [ ] Export all 29 configs from live
- [ ] Import all 29 configs to local
- [ ] Commit all to git
- [ ] Verify sync works both directions
- [ ] Document in CLAUDE.md

#### Phase 2: Implementation - Project Content Export/Import
**Goal**: Create drush commands to export/import AI Project nodes (content) between environments.

**Command 1**: `drush aid-export-projects [--output=FILE]`
- Queries all nodes of type `ai_project`
- Exports to JSON format with all field data
- Includes: title, tags, description, deliverable reference (by issue number), default kanban flag
- Default output: `projects-export.json`

**Command 2**: `drush aid-import-projects FILE [--update-existing]`
- Reads JSON file
- Creates new AI Project nodes
- Matches deliverable by issue number (looks up AI Issue node by field_issue_number)
- Optional `--update-existing` flag to update if project with same title exists
- Reports success/failure for each project

**Deliverables**:
- [ ] Create `src/Commands/ProjectExportCommands.php`
- [ ] Create export command with proper error handling
- [ ] Create import command with validation
- [ ] Test export from fresh live DB copy
- [ ] Test import to clean local environment
- [ ] Test round-trip (export → import → verify data matches)
- [ ] Update CLAUDE.md with usage examples

#### Phase 3: Implementation - Project-Issue Relationships
**Goal**: Create drush commands to export/import the `ai_dashboard_project_issue` table data.

**Command 1**: `drush aid-export-project-issues [--output=FILE]`
- Queries `ai_dashboard_project_issue` table
- Exports to JSON with project title (not nid) and issue number (not nid) for portability
- Includes weight/ordering
- Default output: `project-issues-export.json`

**Command 2**: `drush aid-import-project-issues FILE [--replace]`
- Reads JSON file
- Looks up project by title, issue by issue number
- Inserts/updates relationships in `ai_dashboard_project_issue` table
- Optional `--replace` flag to clear existing relationships first
- Validates that projects and issues exist before creating relationships

**Deliverables**:
- [ ] Create export command for project-issue relationships
- [ ] Create import command with validation
- [ ] Handle cases where issue/project doesn't exist (warn vs error)
- [ ] Test with actual live data
- [ ] Update CLAUDE.md with usage examples

#### Phase 4: Investigation - Documentation Pages
**Goal**: Understand what docs pages are and how they're stored.

**Investigation Tasks**:
- [ ] Access live site and identify where docs are
- [ ] Determine content type (is it just `page`? or custom type?)
- [ ] Count how many docs exist
- [ ] Check if they have special fields or taxonomy
- [ ] Check if they're in a menu structure
- [ ] Determine if they're frequently updated

**Decision Point**:
- If < 10 pages and infrequently updated → Consider default content module or even checking into git
- If > 10 pages or frequently updated → Custom export/import like projects
- If they're just the `page` content type → Generic node export/import

**Deliverables**:
- [ ] Document findings in this file
- [ ] Recommend approach (defer implementation until confirmed needed)

#### Phase 5: Implementation - Roadmap Ordering (If Needed)
**Goal**: Export/import roadmap column ordering if that data exists.

**Background**: CLAUDE.md mentions `ai_dashboard_roadmap_order` table for deliverables roadmap drag-drop.

**Commands** (if table has data):
- `drush aid-export-roadmap-order`
- `drush aid-import-roadmap-order`

**Deliverables**:
- [ ] Check if table exists and has data on live
- [ ] If yes: Create export/import commands similar to project-issues
- [ ] If no: Document as not needed

#### Phase 6: Documentation & Testing
**Goal**: Complete documentation and end-to-end testing.

**Tasks**:
- [ ] Write complete "Rebuilding Local from Live" guide in CLAUDE.md
- [ ] Document export workflow (what to run on live, how to get files)
- [ ] Document import workflow (what to run on local, in what order)
- [ ] Create a checklist for syncing environments
- [ ] Test complete rebuild from scratch:
  1. Fresh `ddev start`
  2. `drush site:install --existing-config`
  3. Import issues from drupal.org
  4. Import contributors from CSV
  5. Import projects from JSON
  6. Import project-issue relationships from JSON
  7. Verify everything works

**Deliverables**:
- [ ] Updated CLAUDE.md "Data Synchronization" section
- [ ] Step-by-step rebuild guide
- [ ] Troubleshooting guide for common issues
- [ ] Verification checklist

## Success Criteria

When this branch is complete, a developer should be able to rebuild the live site locally by following this process:

### Initial Setup (One-Time per Developer)
1. Clone the git repository: `git clone <repo> && cd <repo>`
2. Start DDEV: `ddev start`
3. Install Drupal from config: `drush site:install --existing-config -y`

### Data Import (Repeatable Process)
4. Import AI Issues from drupal.org:
   ```bash
   drush aid:import-all
   ```
5. Import Contributors from CSV:
   - Download latest CSV from Google Sheets
   - Visit `/ai-dashboard/admin/contributor-import`
   - Upload CSV file

6. Import AI Projects from JSON:
   ```bash
   # Get projects-export.json from live site
   drush aid-import-projects projects-export.json
   ```

7. Import Project-Issue Relationships:
   ```bash
   # Get project-issues-export.json from live site
   drush aid-import-project-issues project-issues-export.json
   ```

8. (Optional) Import Documentation Pages:
   ```bash
   # If documentation export/import is implemented
   drush aid-import-docs docs-export.json
   ```

### Verification
9. Verify the site works:
   - Visit `/ai-dashboard/calendar` - Check contributors and issues appear
   - Visit `/ai-dashboard/projects` - Check projects appear
   - Visit `/ai-dashboard/priority-kanban` - Check project filtering works
   - Visit `/ai-dashboard/roadmap` - Check deliverables appear

### Result
- **Complete, functional copy** of the live site locally
- All features working as on live
- Data is fresh (from latest exports)
- Reproducible process documented

## Philosophy & Principles

**This branch follows these principles**:

1. **Separation of Concerns**:
   - **Code** (modules, themes) → Git
   - **Configuration** (content types, views, fields) → Git via config export
   - **Content** (nodes, relationships) → Export files, NOT git

2. **No Git Operations on Live**:
   - Avoid making changes on live and pushing to git from there
   - If config changes happen on live, export and pull to local, then push from local

3. **Portable Export Formats**:
   - Use project titles, not node IDs
   - Use issue numbers, not node IDs
   - Exports should work across different databases

4. **Reproducibility**:
   - Anyone should be able to rebuild from scratch
   - Process should be documented and scriptable
   - Each step should be verifiable

5. **Prefer Automation**:
   - Drush commands over manual UI steps
   - Scriptable processes over one-off fixes
   - Clear error messages and validation

## Notes

- This branch does **NOT** aim to automate continuous content sync via git
- Git should only be used for **code and configuration**, never content
- Content (docs, projects, relationships) syncs via **dedicated export/import tools**
- The goal is **reproducibility**, not real-time synchronization
- Export files are **not checked into git** - they're transferred manually/downloaded as needed
