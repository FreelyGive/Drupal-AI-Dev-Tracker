# CLAUDE-BRANCH.md

## PURPOSE OF THIS FILE

**IMPORTANT FOR FUTURE AGENTS**: This file documents the current branch's purpose, progress, and implementation plans.

- **DO NOT REMOVE THIS SECTION** - it helps orient future agents to the branch context
- **Current Branch**: `planning/improvements-to-projects2`
- **Branch Goal**: Improve the Planning Page
- **Update the branch name above** when working on a different branch
- **See Also**: `Codex_Branch.md` - Contains feedback from ChatGPT Codex on the work done in this branch

---

## User Goals

https://www.drupal.org/project/3547184/issues/3562683


- The Save order button doesn't seem to be working on the projects page (And not on roadmap presumably or kanban)
- Make the Deliverables simpler like on the roadmap page and use the short title and description not issues title.
- Make the list of issues use short title if evailable otherwise the issue title.
- Meta issues shouldn't appear in the numbers for burndown charts.
- If a deliverable shouldn't appear in roadmap, it shouldn't appear in the list of issues either

More planning needed

- I need to differenciate deliverables on the list of issues visually somehow.


## AI Notes

### Completed Work (This Session)

1. **Fixed JS scoping bug** (`project-issues.js`)
   - `updateCollapsibleStates()` and `updateIssueCounts()` were defined inside nested functions but called from sibling functions
   - Moved to outer scope so they're accessible throughout the behavior
   - This was causing "ReferenceError: updateCollapsibleStates is not defined" on production (due to JS aggregation)

2. **Deliverables section improvements** (`ai-project-issues.html.twig`, `project-issues.css`)
   - Uses short title with fallback to full title
   - Only shows "Done" status badge (removed "Active" status)
   - Removed italics from description
   - Toned down due date styling (plain gray text, no yellow background)
   - Includes additional collaborators in assignees display
   - Added collapsible section with ▼/▶ toggle (persists to localStorage)

3. **Issues table** (`ProjectIssuesController.php`, template)
   - Uses short title with fallback to full title
   - Full title shown in tooltip on hover
   - Added `is_meta` data attribute for filtering

4. **Burndown charts** (`BurndownController.php`)
   - Meta issues are now filtered out before calculating totals

---

### Deferred Work

#### 1. Kanban drag-and-drop ordering
- Currently Kanban reflects project page ordering (which is correct)
- Direct drag-and-drop on Kanban itself deferred for later

#### 2. Visual differentiation for deliverables in issues list
- Left for future consideration

---

### Known Issue: Short Titles Missing on Local (TODO)

**Problem**: Some issues on local don't have `field_short_title` populated, but they work fine on live.

**Root Cause Found**: The `MetadataParserService::isTemplateData()` method is too aggressive.

**Location**: `web/modules/custom/ai_dashboard/src/Service/MetadataParserService.php` lines 238-268

**Details**:
- When parsing metadata, if ANY field contains template placeholder text (e.g., `[One-line status update for stakeholders]`), the ENTIRE metadata block is rejected
- This means valid fields like `short_title: "Content Classification Agent"` are discarded because `update_summary` still has placeholder text

**Example**: Node 270 (issue #3561079) has:
```
Update Summary: [One-line status update for stakeholders]  ← Template text
Short Title: Content Classification Agent                  ← Valid data (but discarded!)
```

**Proposed Fix**:
Change `isTemplateData()` to filter out individual template fields rather than rejecting everything:

```php
// Instead of returning TRUE/FALSE for the whole block,
// filter out template values from individual fields:
protected function filterTemplateData(array $metadata) {
  $template_patterns = [
    '/\[One-line.*?\]/',
    '/\[Simple.*?\]/',
    // ... etc
  ];

  foreach ($metadata as $field => $value) {
    foreach ($template_patterns as $pattern) {
      if (preg_match($pattern, $value)) {
        // Remove just this field, not the whole block
        unset($metadata[$field]);
        break;
      }
    }
  }
  return $metadata;
}
```

**Why it works on live**: Live likely has fresher data from drupal.org where users have filled in the update_summary field, so no template placeholders remain.

**Investigation needed**: Why does local have stale issue summaries? Possible causes:
- Local DB was synced before users updated their issue summaries on drupal.org
- Re-importing from drupal.org should fix it: `ddev drush ai-dashboard:import-all --full-from=2025-01-01`

---

## PR Description

```
## Summary
Improvements to the Project Issues page for better usability and fixes a production JS bug.

### Changes
- **Fix JS scoping bug**: Resolved "updateCollapsibleStates is not defined" error on production caused by JS aggregation
- **Deliverables section**: Use short titles, show only "Done" status, add collapsible toggle, include additional collaborators
- **Issues table**: Use short titles with full title in tooltip
- **Burndown charts**: Exclude meta issues from calculations
- **Styling**: Removed italics from descriptions, toned down due date styling

### Testing
- Verify Save Order button works on production after deployment
- Check deliverables section collapses/expands and persists state
- Confirm short titles display (with fallback to full title)
- Verify burndown chart numbers exclude meta issues

Fixes: https://www.drupal.org/project/3547184/issues/3562683
```

## Merge Commit Message (for squash merge into main)

```
Improve Project Issues page - fix JS bug, add short titles, collapsible deliverables

- Fix JS scoping bug causing save order failure on production
- Use short titles for deliverables and issues (with fallback)
- Add collapsible deliverables section with localStorage persistence
- Include additional collaborators in assignee display
- Exclude meta issues from burndown chart calculations
- Simplify deliverable cards (only show Done status, remove italics)
```

