# CLAUDE-BRANCH.md

## PURPOSE OF THIS FILE

**IMPORTANT FOR FUTURE AGENTS**: This file documents the current branch's purpose, progress, and implementation plans.

- **DO NOT REMOVE THIS SECTION** - it helps orient future agents to the branch context
- **Current Branch**: `feature/meta-issue-editor`
- **Branch Goal**: Build a TipTap-based editor for managing drupal.org meta-issues
- **Design Document**: `docs/plans/2025-01-20-meta-issue-editor-design.md`

---

## Overview

Building a new Drupal module `meta_issue_editor` that provides a rich editor for drupal.org meta-issues. Meta-issues contain lists of child issues in `[#XXXX]` format. This editor allows reorganizing these issues, viewing metadata, adding internal notes, and exporting back to drupal.org.

## Key Features

1. **Load meta-issues** from drupal.org by issue number
2. **Parse `[#XXXX]` references** into styled issue blocks
3. **Display issue metadata**: status, component, tags, assignment, update summary
4. **Rich editing**: drag-drop reordering, indentation, headings, bullets
5. **Inline notes**: preserved in drafts, stripped from exports
6. **Export**: HTML (for drupal.org) or Markdown (for drafts)
7. **Save drafts**: Drupal nodes with revisions

## Module Structure

```
web/modules/custom/meta_issue_editor/
├── meta_issue_editor.info.yml          # Requires ai_dashboard
├── meta_issue_editor.module
├── meta_issue_editor.routing.yml
├── meta_issue_editor.permissions.yml
├── meta_issue_editor.links.menu.yml
├── meta_issue_editor.libraries.yml     # TipTap, CSS, JS
├── config/install/                     # Content type + fields
├── src/
│   ├── Controller/MetaIssueEditorController.php
│   └── Service/MetaIssueParserService.php
├── js/meta-issue-editor/
│   ├── editor.js
│   ├── issue-block.js
│   └── api.js
├── css/meta-issue-editor.css
└── templates/
    ├── meta-issue-editor.html.twig
    └── meta-issue-export.html.twig
```

## Dependencies

**Reuses from ai_dashboard:**
- `IssueImportService` - fetch issues from drupal.org API
- `MetadataParserService` - parse metadata from issue summaries
- AI Issue nodes - local issue data lookup
- Status color styling from existing CSS

**New in this module:**
- `MetaIssueParserService` - parse `[#XXXX]` references from meta-issue body

## Content Type: Meta Issue Draft

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Auto-generated: "Meta #3512345" |
| `field_source_issue` | integer | Drupal.org issue number (unique) |
| `field_editor_content` | text (long) | JSON blob of TipTap document state |
| `field_issue_cache` | text (long) | Cached issue metadata |

Settings: Revisions enabled, unique constraint on `field_source_issue`

## Routes

| Path | Permission |
|------|------------|
| `/ai-dashboard/meta-issue-editor` | use meta issue editor |
| `/ai-dashboard/meta-issue-editor/export/{format}` | use meta issue editor |
| `/node/{nid}` | standard (view draft in TipTap read-only) |
| `/node/{nid}/edit` | standard (edit draft in TipTap) |

## Permissions

- `use meta issue editor` - View, edit, export (anonymous allowed)
- `save meta issue drafts` - Save drafts to site (authenticated)
- `fetch issues from drupal org` - Pull via API (authenticated)

## Issue Block Features

**Collapsed view:**
- Drag handle (⋮⋮) for reordering
- Title links to drupal.org
- Status badges (colored: green=fixed, blue=active, red=needs work, grey=closed)
- Closed issues get strikethrough

**Expanded view (click Expand):**
- Status, Component, Tags, Assignment
- Update Summary (from starforge metadata)
- Inline notes field (not exported to drupal.org)

**Unknown issues:**
- Warning badge for issues not in local DB
- "Pull from Drupal.org" button fetches all unknowns

## Export Formats

**HTML** (for drupal.org):
```html
<h2>Phase 1: Foundation</h2>
<ul>
  <li>[#3456789] - Implement AI agent memory system</li>
</ul>
```

**Markdown** (for drafts):
```markdown
## Phase 1: Foundation

- [#3456789] Implement AI agent memory system
  <!-- meta: status=active, assigned=@username -->
  <!-- note: Waiting on API review -->
```

---

## Implementation Tasks

### Phase 1: Module Scaffolding - COMPLETE
- [x] Create module directory structure
- [x] `meta_issue_editor.info.yml` with ai_dashboard dependency
- [x] `meta_issue_editor.routing.yml` with routes
- [x] `meta_issue_editor.permissions.yml`
- [x] `meta_issue_editor.links.menu.yml`

### Phase 2: Content Type - COMPLETE
- [x] `node.type.meta_issue_draft.yml`
- [x] `field.storage.node.field_source_issue.yml`
- [x] `field.storage.node.field_editor_content.yml`
- [x] `field.storage.node.field_issue_cache.yml`
- [x] Field instance configs
- [x] Form and view display configs

### Phase 3: Controller & Service - COMPLETE
- [x] `MetaIssueEditorController.php` - landing page + API endpoints
- [x] `MetaIssueParserService.php` - parse `[#XXXX]` from text

### Phase 4: TipTap Integration - COMPLETE
- [x] `meta_issue_editor.libraries.yml` - TipTap CDN
- [x] `editor.js` - TipTap initialization
- [x] `issue-block.js` - custom node for issues
- [x] `api.js` - AJAX for save/load/fetch
- [x] `meta-issue-editor.css` - styling

### Phase 5: API Endpoints - COMPLETE
- [x] POST `/api/meta-issue-editor/fetch-issues`
- [x] GET `/api/meta-issue-editor/local-issues`
- [x] POST `/api/meta-issue-editor/save-draft`

### Phase 6: Export & Templates - COMPLETE
- [x] `meta-issue-editor.html.twig` - editor page
- [x] `meta-issue-export.html.twig` - export display
- [x] Export route handler
- [x] Markdown import parser

### Phase 7: Node Integration - COMPLETE
- [x] Hook into node view for TipTap read-only display
- [x] Add noindex meta tag to content type

### Phase 8: Code Review Fixes - COMPLETE
- [x] CSRF token validation on POST endpoints (fetchIssues, saveDraft)
- [x] XSS protection in export template (Html::escape)
- [x] Document accessCheck(FALSE) usage with comments
- [x] Add return type hints to all controller methods
- [x] Fix JavaScript fetch error handling (response.ok check)
- [x] Add CSRF token to JavaScript POST requests
- [x] Move inline JS from export template to library
- [x] Implement drag-and-drop for issue blocks
- [x] Add drag visual feedback CSS
- [x] Document TipTap integration status

## Remaining Work (Functional Issues)

### Issue 1: HTML Export Produces Escaped HTML - FIXED
**Problem:** Export showed `&lt;p&gt;` instead of `<p>` - the XSS fix incorrectly escaped content destined for a textarea.

**Fixes applied:**
- Added `|raw` filter in twig template (textarea content is safe)
- `generateHtmlExport()` converts `<br>` back to newlines
- `cleanImportedHtml()` transforms API HTML on import

### Issue 2: List Items (Bullets) Don't Drag-Drop
**Problem:** Current drag-drop only works on `.issue-block` elements, not on `<li>` list items.

**Fix needed:**
- Extend drag-drop to handle list items containing issue blocks
- Or restructure so issue blocks are always direct children of the editor (not nested in lists)
- Consider: should the editor manage lists, or just issue blocks in a flat structure?

### Issue 3: Issue Metadata Not Populating
**Problem:** When expanding an issue block, metadata fields (component, tags, assignment, update summary) may not be displaying.

**Fix needed:**
- Verify local issue lookup is returning all fields
- Check that `formatIssueData()` includes all expected fields
- Ensure JavaScript `renderBlockWithData()` handles all fields correctly
- Test with real issue numbers from the local database

### Issue 4: Loading Meta-Issue Body
**Problem:** When loading a meta-issue from drupal.org, need to properly parse the body HTML and extract issue references while preserving structure.

**Fix needed:**
- The fetched body HTML should be cleaned up
- Issue references `[#XXXXXX]` should be converted to issue blocks
- Preserve heading structure (h2, h3) and lists

## Next Steps (Testing & Refinement)

- [x] Enable module locally: `ddev drush en meta_issue_editor`
- [x] Fix HTML export escaping issue
- [x] Fix H2/H3 format buttons (execCommand needed tag value)
- [x] Fix CSRF token missing on node view pages
- [x] Fix draft content key inconsistency (editor_content)
- [x] Add user feedback for anonymous fetch restriction
- [x] Improve JSON parse error handling
- [ ] Test loading a meta-issue from drupal.org
- [ ] Test issue metadata display in expanded view
- [ ] Test saving and loading drafts
- [ ] Decide on drag-drop approach for list items

---

## Reference Files

- **Design**: `docs/plans/2025-01-20-meta-issue-editor-design.md`
- **Issue Import**: `web/modules/custom/ai_dashboard/src/Service/IssueImportService.php`
- **Metadata Parser**: `web/modules/custom/ai_dashboard/src/Service/MetadataParserService.php`
- **Example Controller**: `web/modules/custom/ai_dashboard/src/Controller/CalendarController.php`
- **Example Content Type**: `config/sync/node.type.ai_issue.yml`

---

## Technical Notes

- **Editor approach**: Uses contenteditable with execCommand as MVP; TipTap CDN is loaded but not fully integrated due to ES module compatibility issues with Drupal's JS loading
- **Drag-drop**: Native HTML5 drag-drop API for issue block reordering
- **Security**: CSRF tokens on all POST endpoints, XSS protection via Html::escape
- Inject ai_dashboard services via dependency injection
- Use existing status color CSS from ai_dashboard module
- Add noindex meta tag to prevent search indexing of drafts

### Future TipTap Integration
To fully integrate TipTap (if needed):
1. Add npm build step with webpack/vite
2. Create custom TipTap extensions for issue blocks
3. Replace contenteditable with TipTap editor instance
4. See documentation in `js/meta-issue-editor/editor.js`
