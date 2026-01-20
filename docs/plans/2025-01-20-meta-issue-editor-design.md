# Meta-Issue-Editor Design

**Date**: 2025-01-20
**Status**: Approved

## Overview

A TipTap-based editor for managing drupal.org meta-issues. Users load a meta-issue by number, edit the structure with rich formatting and issue references, then export to HTML (for drupal.org) or Markdown (for drafts).

Meta-issues on drupal.org contain lists of child issues in the format `[#XXXX]`. This editor provides a rich editing experience for reorganizing these issues, adding internal notes, and viewing issue metadata without leaving the page.

## Module Structure

**New Module**: `meta_issue_editor`

```
web/modules/custom/meta_issue_editor/
├── meta_issue_editor.info.yml
├── meta_issue_editor.module
├── meta_issue_editor.routing.yml
├── meta_issue_editor.permissions.yml
├── meta_issue_editor.links.menu.yml
├── meta_issue_editor.libraries.yml
├── config/
│   └── install/
│       ├── node.type.meta_issue_draft.yml
│       ├── field.storage.node.field_source_issue.yml
│       ├── field.storage.node.field_editor_content.yml
│       ├── field.storage.node.field_issue_cache.yml
│       └── field.field.node.meta_issue_draft.*.yml
├── src/
│   ├── Controller/
│   │   └── MetaIssueEditorController.php
│   └── Service/
│       └── MetaIssueParserService.php
├── js/
│   └── meta-issue-editor/
│       ├── editor.js
│       ├── issue-block.js
│       └── api.js
├── css/
│   └── meta-issue-editor.css
└── templates/
    ├── meta-issue-editor.html.twig
    └── meta-issue-export.html.twig
```

**Dependencies**:
- `ai_dashboard:ai_dashboard` (required)
- `drupal:node`

**Reused from ai_dashboard**:
- `IssueImportService` - fetch single issues from drupal.org API
- `MetadataParserService` - parse metadata from issue summaries
- AI Issue nodes - local issue data lookup
- Status color styling from existing CSS

## Content Type: Meta Issue Draft

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Auto-generated: "Meta #3512345" |
| `field_source_issue` | integer | Drupal.org issue number (unique constraint) |
| `field_editor_content` | text (long) | JSON blob of TipTap document state |
| `field_issue_cache` | text (long) | Cached issue metadata for offline rendering |

**Settings**:
- Revisions enabled
- Unique constraint on `field_source_issue`
- No publish/unpublish workflow needed

## Routes

| Path | Permission | Description |
|------|------------|-------------|
| `/ai-dashboard/meta-issue-editor` | use meta issue editor | Landing page - enter issue # to create/load |
| `/ai-dashboard/meta-issue-editor/export/{format}` | use meta issue editor | Export display page |
| `/node/{nid}` | (standard) | View draft (TipTap read-only mode) |
| `/node/{nid}/edit` | (standard) | Edit draft (TipTap editor) |
| `/node/{nid}/revisions` | (standard) | Revision history |

## Permissions

```yaml
use meta issue editor:
  title: 'Use Meta-Issue-Editor'
  description: 'View, edit, and export meta-issues'
  # Anonymous allowed

save meta issue drafts:
  title: 'Save Meta-Issue-Editor drafts'
  description: 'Save and manage draft documents'
  restrict access: true

fetch issues from drupal org:
  title: 'Fetch issues from drupal.org'
  description: 'Pull unknown issue data via drupal.org API'
  restrict access: true
```

## Page Layout

```
┌─────────────────────────────────────────────────────┐
│ [Issue #_____] [Load]     [Import MD] [Export ▼]   │
├─────────────────────────────────────────────────────┤
│                                                     │
│  TipTap Editor Area                                 │
│  - Headings, bullets, bold, italic                  │
│  - Issue blocks with expandable metadata            │
│  - Drag handles for reordering                      │
│                                                     │
├─────────────────────────────────────────────────────┤
│ [Pull X Unknown Issues from Drupal.org]             │
└─────────────────────────────────────────────────────┘
```

## Issue Block Rendering

**Collapsed View** (mimics drupal.org styling):
```
┌──────────────────────────────────────────────────────┐
│ ⋮⋮  #3456789: Implement AI agent memory system       │
│     [Active] [Normal]                    [▶ Expand]  │
└──────────────────────────────────────────────────────┘
```

- `⋮⋮` = drag handle for reordering
- Title links to drupal.org
- Status badge colored (green=fixed, blue=active, red=needs work, grey=closed)
- Closed issues get strikethrough on title

**Expanded View**:
```
┌──────────────────────────────────────────────────────┐
│ ⋮⋮  #3456789: Implement AI agent memory system       │
│     [Active] [Normal]                   [▼ Collapse] │
│     ─────────────────────────────────────────────────│
│     Status: Active          Component: AI Agents     │
│     Assigned: @username     Module: ai_agents        │
│     Tags: AI Core, Memory                            │
│     Update Summary: Ready for review                 │
│     ─────────────────────────────────────────────────│
│     📝 Notes: (internal, not exported to drupal.org) │
│     ┌─────────────────────────────────────────────┐  │
│     │ Waiting on API review before this can move  │  │
│     └─────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────┘
```

**Unknown Issues** (not in local DB):
```
┌──────────────────────────────────────────────────────┐
│ ⋮⋮  #9999999                              [⚠ Unknown]│
└──────────────────────────────────────────────────────┘
```

## Data Flow

### Loading a Meta-Issue

1. User enters issue number, clicks Load
2. Check if draft exists for this issue number
   - **Exists**: Load from `field_editor_content` JSON
   - **New**: Fetch issue body from drupal.org API
3. Parse `[#XXXX]` references from the description
4. For each issue reference:
   - Check local AI Issue nodes first
   - Mark as "unknown" if not found locally
5. Render as editable TipTap document

### Saving a Draft

1. User clicks "Save Draft" (requires permission)
2. Serialize TipTap document state to JSON
3. Check if draft exists for source issue number
   - **Exists**: Update node, create revision
   - **New**: Create new node
4. Cache current issue metadata in `field_issue_cache`

### Fetching Unknown Issues

1. User clicks "Pull X Unknown Issues from Drupal.org"
2. Collect all issue numbers marked as unknown
3. Use `ai_dashboard.issue_import_service` to fetch each (with rate limiting)
4. Update editor display as issues are fetched
5. Optionally create AI Issue nodes for fetched issues

## Export Formats

### Export to HTML (for drupal.org)

Shows a page with rendered HTML for copy-paste. Notes stripped out.

```html
<h2>Phase 1: Foundation</h2>
<ul>
  <li>[#3456789] - Implement AI agent memory system</li>
  <li>[#3456790] - Add configuration UI</li>
</ul>
```

### Export to Markdown (for drafts)

Shows a page with rendered Markdown for copy-paste. Includes notes and metadata.

```markdown
## Phase 1: Foundation

- [#3456789] Implement AI agent memory system
  <!-- meta: status=active, assigned=@username -->
  <!-- note: Waiting on API review before this can move -->
- [#3456790] Add configuration UI
  <!-- meta: status=needs_review, update_summary=Ready for final review -->
```

### Import from Markdown

Parses markdown including `<!-- meta: -->` and `<!-- note: -->` comments to rebuild editor state.

## SEO Considerations

Add `noindex` meta tag to meta_issue_draft content type view mode to prevent indexing of draft content.

## Menu Integration

```yaml
meta_issue_editor.editor:
  title: 'Meta-Issue-Editor'
  route_name: meta_issue_editor.editor
  parent: ai_dashboard.main
  weight: 50
```

## Technical Dependencies

- **TipTap**: Loaded via CDN or npm/compiled asset
- **ai_dashboard services**: Injected via dependency injection
  - `ai_dashboard.issue_import_service`
  - `ai_dashboard.metadata_parser_service`

## API Endpoints

| Endpoint | Method | Permission | Description |
|----------|--------|------------|-------------|
| `/api/meta-issue-editor/fetch-issues` | POST | fetch issues from drupal org | Fetch unknown issues from drupal.org |
| `/api/meta-issue-editor/local-issues` | GET | use meta issue editor | Get cached issue data from local DB |
| `/api/meta-issue-editor/save-draft` | POST | save meta issue drafts | Save/update draft |

## Future Considerations

- **"Save to Site" permission**: Placeholder for future feature to save directly to drupal.org
- **Collaborative editing**: Could add real-time collaboration later
- **Issue templates**: Pre-built structures for common meta-issue patterns
