# CLAUDE-BRANCH.md

## PURPOSE OF THIS FILE

**IMPORTANT FOR FUTURE AGENTS**: This file documents the current branch's purpose, progress, and implementation plans.

- **DO NOT REMOVE THIS SECTION** - it helps orient future agents to the branch context
- **Current Branch**: `main` (hotfix)
- **Branch Goal**: Multiple fixes for metadata parsing, import configuration, and admin UI
- **Update the branch name above** when working on a different branch

---

## COMPLETED FIXES (This Session)

### 1. Metadata Parser - Filter Instead of Reject
**Problem**: `MetadataParserService::isTemplateData()` rejected ALL metadata if ANY field contained template text.

**Fix**: Changed to `filterTemplateFields()` which removes individual template fields while keeping valid ones.

**Files**: `MetadataParserService.php`

### 2. Short Description Truncation
**Problem**: `field_short_description` has max 255 chars, but some metadata exceeded this causing database errors.

**Fix**: Added `mb_substr($value, 0, 255)` truncation in both:
- `IssueImportService.php` (for imports)
- `AiDashboardCommands.php` (for metadata processing)

### 3. Last Run Timestamp - Use State API Consistently
**Problem**: Admin page `/admin/config/ai-dashboard/module-import` showed "Never" for Last Run, but docs page showed correct dates.

**Root Cause**: Two different storage locations:
- Drush command stored in State API (`ai_dashboard:last_import:{id}`)
- Admin list read from entity property (`$entity->getLastRun()`)

**Fix**:
- Changed `ModuleImportListBuilder.php` to read from State API
- Removed dead `last_run` property/methods from `ModuleImport.php` entity
- Removed `last_run` from schema
- Updated `IssueImportService.php` to use State API

### 4. Resolved Project ID Not Saved
**Problem**: When creating import config with machine name, the resolved project ID was shown in a message but not saved to the entity.

**Fix**: In `ModuleImportForm.php`:
- Store resolved ID in form state during validation
- Use resolved ID in save() if no explicit ID provided

### 5. Recipes Don't Resolve
**Problem**: Recipe projects like `ai_recipe_seo_optimizer` failed to resolve because they use `project_general` type.

**Fix**: Added `project_general` to the list of project types in `IssueImportService::resolveProjectIdFromMachineName()`

---

## DEPLOYMENT PLAN

### Step 1: First Commit (Code Fixes)
- [x] Commit all code fixes to main
- [ ] Push to origin
- [ ] Deploy to live site
- [ ] Clear caches on live

### Step 2: Verify on Live
- [ ] Check `/admin/config/ai-dashboard/module-import` shows Last Run dates
- [ ] Test creating new import config for a recipe (e.g., `ai_recipe_seo_optimizer`)
- [ ] Verify resolved project ID is saved when editing the config
- [ ] Run `drush ai-dashboard:import-all` and verify it works for recipes

### Step 3: Export/Import Config
- [ ] On live: `drush aid-cexport` (export new import configurations)
- [ ] On local: `drush aid-cimport` (import the new configurations)
- [ ] Review changes
- [ ] Commit new configuration files

---

## FILES CHANGED

```
web/modules/custom/ai_dashboard/src/Service/MetadataParserService.php
web/modules/custom/ai_dashboard/src/Service/IssueImportService.php
web/modules/custom/ai_dashboard/src/Drush/Commands/AiDashboardCommands.php
web/modules/custom/ai_dashboard/src/ModuleImportListBuilder.php
web/modules/custom/ai_dashboard/src/Entity/ModuleImport.php
web/modules/custom/ai_dashboard/src/Form/ModuleImportForm.php
web/modules/custom/ai_dashboard/config/schema/ai_dashboard.schema.yml
config/sync/ai_dashboard.module_import.ai_initiative_community_page.yml
```
