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

## Next Steps (Testing & Refinement)

- [ ] Enable module locally: `ddev drush en meta_issue_editor`
- [ ] Test loading a meta-issue from drupal.org
- [ ] Test saving and loading drafts
- [ ] Test export to HTML and Markdown
- [ ] Test fetching unknown issues from drupal.org API
- [ ] Refine TipTap editor behavior (drag-drop, formatting)
- [ ] Add proper TipTap npm build if CDN approach has issues

---

## Reference Files

- **Design**: `docs/plans/2025-01-20-meta-issue-editor-design.md`
- **Issue Import**: `web/modules/custom/ai_dashboard/src/Service/IssueImportService.php`
- **Metadata Parser**: `web/modules/custom/ai_dashboard/src/Service/MetadataParserService.php`
- **Example Controller**: `web/modules/custom/ai_dashboard/src/Controller/CalendarController.php`
- **Example Content Type**: `config/sync/node.type.ai_issue.yml`

---

## Technical Notes

- TipTap loaded via CDN (https://cdn.jsdelivr.net/npm/@tiptap/...)
- Inject ai_dashboard services via dependency injection
- Use existing status color CSS from ai_dashboard module
- Add noindex meta tag to prevent search indexing of drafts
