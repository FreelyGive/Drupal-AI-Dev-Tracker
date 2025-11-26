# CLAUDE-BRANCH.md

## PURPOSE OF THIS FILE

**⚠️ IMPORTANT FOR FUTURE AGENTS**: This file documents the current branch's purpose, progress, and implementation plans.

- **DO NOT REMOVE THIS SECTION** - it helps orient future agents to the branch context
- **Current Branch**: `improved-roadmap`
- **Branch Goal**: Make the Roadmap simpler and more stakeholder-focused
- **Update the branch name above** when working on a different branch
- **See Also**: `Codex_Branch.md` - Contains feedback from ChatGPT Codex on the work done in this branch

---

### USER PLAN ###
(Claude can put its planning in Claude Plan)

Changes:

- Make the Roadmap Visually simpler:
- Create a new Field "Short Title" with the Helper Text "Simple Issue title for stakeholders without Drupalisms" on Issues.
(We need this because the issue titles on Drupal.org are usually written to look good on Drupal.org with things like [Meta] in the name. This is a place where we can write a shorter title for stakeholders to see.)
- Add the below to the advanced tracker template in the docs.
- Add the new field to the sync command that runs during normal import and the command that reruns all syncing so that the data from the tracker will sync with the new field.
- Change the Roadmap so it only Short Title and Short Description on the roadmap. There should be a button to click it and it will take you to the issue on Drupal.org or if you have logged in with Admin permissions the edit page of the deliverable.
- Create filters near the top for filtering by track or workstream (but for now don't show that information on the issues themselves).
- Make sure we handle configuration management. We need to push these changes to live from local but also do what we need to do so that this functionality will work when the whole site is rebuilt locally.
- Do correct drupal code review, clean up and documentation.
- Remember that Codex will review code.
- 


[Tracker]
<strong>Update Summary: </strong>[One-line status update for stakeholders]
<strong>Short Title: </strong>[Simple Issue title for stakeholders without Drupalisms]
<strong>Short Description: </strong>[One-line issue summary for stakeholders]
<strong>Check-in Date: </strong>MM/DD/YYYY
<strong>Due Date:</strong> MM/DD/YYYY
<strong>Blocked by:</strong> [#XXXXXX] (New issues on new lines)
<strong>Additional Collaborators:</strong> @username1, @username2
<em>Metadata is used by the <a href="https://www.drupalstarforge.ai/" title="AI Tracker">AI Tracker.</a> Docs and additional fields <a href="https://www.drupalstarforge.ai/ai-dashboard/docs" title="AI Issue Tracker Documentation">here</a>.</em>
[/Tracker]


### CLAUDE PLAN ###

## Implementation Plan: Improved Roadmap

### Overview

This plan implements a cleaner, stakeholder-focused roadmap that removes Drupal jargon and provides better filtering capabilities.

**Key Changes:**
1. New "Short Title" field for stakeholder-friendly titles
2. Simplified roadmap cards (Short Title + Short Description only)
3. Track/Workstream filters at the top
4. Better click behavior (admin vs public)

---

## Phase 1: Add Short Title Field & Sync [USER TESTABLE]

**Goal:** Create the new field and enable syncing from drupal.org metadata.

### Tasks:

1. **Create `field_short_title` field on AI Issue content type**
   - Type: string (plain text), max 100 characters
   - Label: "Short Title"
   - Helper text: "Simple issue title for stakeholders without Drupalisms"
   - Add via database update hook (9044)

2. **Update MetadataParserService** to parse Short Title
   - File: `src/Service/MetadataParserService.php`
   - Add pattern: `'short_title' => '/Short Title:\s*(.+?)(?=<br|\\n|$)/i'`
   - Already handles template detection

3. **Update IssueImportService** to store Short Title
   - File: `src/Service/IssueImportService.php`
   - Add `short_title` to mapDrupalOrgIssue() return array
   - Add field setting in createIssue() and updateIssue()

4. **Update documentation template** in CLAUDE-BRANCH.md
   - Template already includes `<strong>Short Title: </strong>` - just needs to be documented

5. **Export configuration**
   - Run `drush cex` to export field configs

### Testing:
- Run `drush updb` to create field
- Find an issue on drupal.org with the [Tracker] metadata block
- Run `drush ai-dashboard:import-all` or `drush aid-meta` to reprocess
- Verify field populates

---

## Phase 2: Simplify Roadmap Display [USER TESTABLE]

**Goal:** Cleaner cards showing only Short Title and Short Description.

### Tasks:

1. **Update ai-roadmap.html.twig**
   - Display Short Title if available, fallback to regular title
   - Show only Short Title + Short Description (remove project links, progress bars from cards)
   - Keep due date visible (important for stakeholders)
   - Different click behavior based on user role:
     - **Admin:** Click goes to edit page (`/node/{nid}/edit`)
     - **Public:** Click goes to drupal.org issue

2. **Update roadmap.css**
   - Simplify card styling
   - Ensure cards look clickable
   - Keep column colors and responsive layout

3. **Update roadmap.js**
   - Modify click handler for admin vs public behavior
   - Keep drag-drop for admin users

### Testing:
- View roadmap as anonymous user - clicking should go to drupal.org
- View roadmap as admin - clicking should go to edit page
- Verify Short Title displays when available
- Verify fallback to regular title works

---

## Phase 3: Add Track/Workstream Filters [USER TESTABLE]

**Goal:** Filter deliverables by Track or Workstream at the top of the page.

### Pre-work TODO:
- [ ] **Review existing filter implementations** before proceeding:
  - Look at `/ai-dashboard/priority-kanban` filtering (ProjectKanbanController)
  - Look at `/ai-dashboard/calendar` filtering (CalendarController)
  - Understand the pattern used (URL params, AJAX, client-side JS)
  - Use a similar approach for consistency

### Tasks:

1. **Update RoadmapController**
   - File: `src/Controller/RoadmapController.php`
   - Add method to get unique Tracks and Workstreams from deliverables
   - Accept filter parameters from query string
   - Filter deliverables before display

2. **Update ai-roadmap.html.twig**
   - Add filter bar below navigation, above columns
   - Dropdown/buttons for Track filter
   - Dropdown/buttons for Workstream filter
   - "All" option to clear filters

3. **Update roadmap.js**
   - Handle filter selection
   - Update URL with query parameters
   - Match pattern used in kanban/calendar

4. **Update roadmap.css**
   - Style filter bar (match existing filter styling)
   - Active filter state styling

### Testing:
- Select a Track - only matching deliverables show
- Select a Workstream - only matching deliverables show
- Click "All" - all deliverables show
- Filters persist in URL (shareable links)
- Empty columns show empty state (not hidden)

---

## Phase 4: Configuration Management & Cleanup [CLAUDE TESTABLE]

**Goal:** Ensure changes deploy cleanly and codebase is production-ready.

### Tasks:

1. **Export all configuration**
   - Field storage and field instance for short_title
   - Form display updates
   - View display updates

2. **Test fresh install scenario**
   - Update hooks run in correct order
   - No errors on `drush updb`

3. **Code review and cleanup**
   - PHPDoc comments on new methods
   - Remove any debug code
   - Ensure Drupal coding standards
   - Add inline comments where logic is complex

4. **Update documentation.md**
   - Document new Short Title field
   - Document filter functionality
   - Update deployment checklist

### Testing (Claude can run):
- `ddev drush updb` - no errors
- `ddev drush cex` - no unexpected changes
- PHP CodeSniffer/linting if available

---

## Design Decisions (Confirmed)

1. **Project display** - ✅ REMOVE from roadmap cards
2. **Due Date** - ✅ REMOVE from roadmap cards
3. **Progress bars** - ✅ REMOVE from roadmap cards
4. **Column logic** - ✅ KEEP current assignee-based logic
5. **Empty state** - ✅ Show empty columns (not hidden)

**Important:** These simplifications apply ONLY to the Roadmap page. Project issue pages retain full deliverable information (progress bars, burndown charts, etc.)

---

## Files to Modify

| File | Phase | Changes |
|------|-------|---------|
| `ai_dashboard.install` | 1 | Add update hook 9044 for field_short_title |
| `MetadataParserService.php` | 1 | Add short_title pattern |
| `IssueImportService.php` | 1 | Map and store short_title field |
| `ai-roadmap.html.twig` | 2, 3 | Simplify cards, add filters |
| `roadmap.css` | 2, 3 | Simplify card styles, add filter styles |
| `roadmap.js` | 2, 3 | Click behavior, filter handling |
| `RoadmapController.php` | 3 | Filter logic, get unique tracks/workstreams |
| `documentation.md` | 4 | Update docs |
| `config/sync/*.yml` | 1, 4 | Field configs |

---

## Execution Status

1. ✅ **Phase 1** - Short Title field and sync - COMPLETE
2. ✅ **Phase 2** - Simplified roadmap display - COMPLETE
3. ✅ **Phase 3** - Track/Workstream filters - COMPLETE
4. ✅ **Phase 4** - Cleanup and documentation - COMPLETE

## Completed Changes Summary

### Phase 1: Short Title Field
- Added `field_short_title` (100 chars) to AI Issue content type via update hook 9044
- Updated MetadataParserService to parse `Short Title:` from `[Tracker]` metadata blocks
- Updated IssueImportService to store short_title on create/update
- Added template detection for placeholder text (`[Simple...]`, `[...Drupalisms...]`)

### Phase 2: Simplified Roadmap Display
- Cards now show only: Short Title (or fallback to regular title), Short Description
- Title is clickable and links to drupal.org issue (opens in new tab)
- Icons in top-right corner matching calendar view style:
  - Blue arrow (↗) links to drupal.org
  - Cog icon (⚙) links to edit page (admin only)
- Removed from cards: Project links, progress bars, due dates, assignees, status notes
- Simplified CSS (~240 lines, reduced from ~480 lines)
- Simplified JS (removed project click handling, kept drag-drop for admins)

### Phase 3: Track/Workstream Filters
- Updated RoadmapController to:
  - Accept Request parameter and read `track` and `workstream` query params
  - Collect unique tracks/workstreams from all deliverables for filter options
  - Filter deliverables server-side before grouping into columns
  - Pass filter_options, selected_track, selected_workstream to template
  - Add cache contexts for URL query args
- Updated ai-roadmap.html.twig with filter dropdowns:
  - Track dropdown (only shows if tracks exist in data)
  - Workstream dropdown (only shows if workstreams exist in data)
  - "Clear Filters" link when filters are active
- Updated roadmap.css with filter bar styling matching kanban pattern
- Updated roadmap.js with auto-submit on filter change
- Updated ai_dashboard.module theme hook with new variables
- Updated docs page with Short Title in Advanced Format template

### Column Header Tooltips
- Added info icon (ⓘ) to each column header
- PopperJS tooltips show on hover explaining column rules:
  - **Complete**: Issues with status Fixed, Closed (Fixed), Closed (Duplicate), or Closed (Works as designed)
  - **Now**: Issues with an assignee AND linked to a project (priority work)
  - **Next**: Issues with an assignee but NOT linked to a project (being worked on but not priority)
  - **Later**: Issues with no assignee (not yet being worked on)
- Extended focus-tooltips.js to handle `.column-title` class
- Added PopperJS and focus-tooltips to roadmap library

---

## Pull Request Description

**Title:** Simplify roadmap with stakeholder-friendly display and Track/Workstream filters

**Body:**

## Summary

- **Simplified roadmap cards** to show only Short Title and Short Description, removing Drupal jargon for stakeholder readability
- **Added new Short Title field** (100 chars) that syncs from `[Tracker]` metadata blocks on drupal.org issues
- **Added Track/Workstream filters** to help stakeholders find relevant deliverables quickly
- **Added column header tooltips** explaining the rules for each status column

## Changes

### New Short Title Field
- Created `field_short_title` on AI Issue content type (update hook 9044)
- Updated MetadataParserService to parse `Short Title:` from issue summaries
- Updated IssueImportService to store short_title during import/sync
- Added to Advanced Format template on docs page

### Simplified Roadmap Display
- Cards now show only: Short Title (with fallback to regular title) and Short Description
- Removed from cards: Project links, progress bars, due dates, assignees
- Clickable title links to drupal.org issue
- Icons in top-right corner: blue arrow (↗) to drupal.org, cog (⚙) to edit (admin only)
- Styling matches calendar view for consistency

### Track/Workstream Filters
- Dropdown filters in header below navigation
- Auto-submit on selection (no button needed)
- "Clear Filters" link when filters are active
- Filters persist in URL for shareable links
- Empty columns show "No deliverables" state

### Column Header Tooltips
- Info icon (ⓘ) on each column header with hover tooltip
- Uses PopperJS via existing focus-tooltips library
- Explains the status rules for each column

## Test Plan

- [ ] Visit `/ai-dashboard/roadmap` and verify simplified card display
- [ ] Click card title - should open drupal.org issue in new tab
- [ ] Click arrow icon (↗) - should open drupal.org issue in new tab
- [ ] As admin, click cog icon (⚙) - should go to local edit page
- [ ] Select a Track filter - only matching deliverables should show
- [ ] Select a Workstream filter - only matching deliverables should show
- [ ] Click "Clear Filters" - all deliverables should show
- [ ] Verify URL updates with filter params (shareable links)
- [ ] Hover over column header info icon - tooltip should appear explaining column rules
- [ ] Run `drush updb` on fresh install - should create field_short_title
- [ ] Import an issue with Short Title in [Tracker] block - should populate field

## Screenshots

_(Add screenshots of the new roadmap view with filters)_