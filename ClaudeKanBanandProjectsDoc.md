# Claude Docs - AI Dashboard Module

This is now a documentation page made roughly for the Kanban and Projects, Project and explains what has been completed and not done as part of that project. It was the claude planning document but has changed.

### For Users:
1. **Review plans** before giving implementation approval
2. **Provide feedback** on technical approaches and priorities
3. **Request modifications** to scope or implementation strategy
4. **Confirm deletion** of completed plans once features are done

## Completed Work (Session: January 16, 2025)

### Import Configurations Page - COMPLETED
Created a read-only import configurations page at `/ai-dashboard/import-configurations` for anonymous users:
- **Controller**: `ImportConfigurationsController.php` - loads from `module_import` entities
- **Template**: `ai-import-configurations.html.twig` with full styling
- **Features**:
  - Displays all module import configurations
  - Shows status filters, tag filters, component filters
  - Shows last run timestamp for each configuration
  - Copy buttons for drush commands
  - Active/inactive status indicators
- **Access**: Available to anonymous users with `accessCheck(FALSE)`
- **Title**: Changed to "Modules imported" per user request

### Navigation Updates - COMPLETED
- Added "Import Configs" link only to Docs page (not in main navigation)
- Fixed navigation menu styling with `shared_components` library
- Increased nav max-width from 600px to 800px to prevent line wrapping

### Documentation Page Updates - COMPLETED
- Replaced import configurations list with link to dedicated page
- Added styled button linking to `/ai-dashboard/import-configurations`
- Cleaned up `PublicDocsController` to remove unnecessary configuration loading

## Production Readiness Assessment

### Code Review Findings
1. **TODO Comment**: One TODO remains in `PriorityKanbanController.php` line 551 for meta issue detection - USER REQUESTED TO KEEP
2. **Console Statements**: Several console.error statements exist in JS files for legitimate error handling - ACCEPTABLE FOR PRODUCTION
3. **Alert Statements**: Some alerts in admin JS files (tag-mapping.js, csv-import.js) - ACCEPTABLE FOR ADMIN FEATURES
4. **No test/dummy files found**
5. **No backup or temporary files found**

### Database Update Hooks
The module has 39 update hooks (8001-8009, 9001-9037) that handle:
- Field creation and updates
- Entity type installations
- Configuration updates
- Data migrations

**Recommendation**: These are production-ready and should remain as-is for upgrade path support.

### Routes and Controllers
All routes in `ai_dashboard.routing.yml` have corresponding controllers and are complete:
- Public pages (dashboard, calendar, kanban, projects, docs, import-configurations)
- Admin pages (all properly permission-protected)
- API endpoints (with CSRF protection)

## Current Plans

### Remaining Minor Items
None - module is production-ready

**Column Logic (Not 1:1 Status Mapping):**
- **Todos**: `status IN ('active') AND assignee IS NULL` OR `status IN ('needs_work') AND assignee IS NULL`
- **Blocker Issues**: Issues that OTHER issues reference in their `field_issue_blocked_by` (reverse lookup)
- **Needs Review**: `status = 'needs_review'`
- **Past Check-in Date**: `field_checkin_date < TODAY() AND status IN ('active', 'needs_work')`
- **Working On**: `status IN ('active', 'needs_work') AND assignee IS NOT NULL`
- **RTBC**: `status = 'rtbc'`
- **Fixed**: `status = 'fixed'`

**All Columns Filtered By**: Tag dropdown (defaults to `priority`) over `field_issue_tags`.

#### Library Research

**Option 1: Sortable.js**
- **Pros**: Lightweight (45KB), pure JavaScript, excellent drag-drop, mobile support
- **Cons**: Basic styling, requires custom kanban layout
- **Best for**: Simple implementation, full control over styling
- **Integration**: Easy Drupal integration, no framework dependencies

**Option 2: @atlaskit/board (Atlassian)**
- **Pros**: Professional Jira-like interface, comprehensive features, React-based
- **Cons**: Heavy (500KB+), React dependency, complex setup
- **Best for**: Feature-rich experience matching familiar tools
- **Integration**: Would need React build system in Drupal

**Option 3: muuri**
- **Pros**: Animated layouts, responsive grid, excellent performance
- **Cons**: Primarily for card layouts, less kanban-specific
- **Best for**: Smooth animations and responsive design
- **Integration**: Good for custom kanban implementations

**Option 4: dragula**  
- **Pros**: Simple drag-and-drop, framework agnostic, small size (20KB)
- **Cons**: No built-in kanban styling, requires custom layout
- **Best for**: Adding drag-drop to existing HTML structure
- **Integration**: Minimal Drupal integration effort

**Option 5: Custom CSS Grid + Sortable.js**
- **Pros**: Full control, matches existing AI Dashboard styling, lightweight
- **Cons**: More development time, custom maintenance
- **Best for**: Consistent user experience with existing dashboard

**Recommended Approach**: **Sortable.js + Custom CSS Grid**
- Matches existing AI Dashboard design language
- Lightweight and performant
- Easy to integrate with Drupal's render system
- Supports both desktop and mobile
- Can leverage existing AI Dashboard CSS patterns

#### Implementation Plan

**Phase 2 (Remaining)**
1. Blocker Issues column (reverse dependency) + sort by blocker impact.
2. Check‑in Date validation and “upcoming” emphasis state.
3. Optional: drag‑and‑drop reassignment (deferred).

---

## Project Issues Management - COMPLETED ✅

**Implementation Completed**: Clean project issues management system with drag-and-drop reordering and hierarchical organization.

### Features Delivered:
- **Project Management Page** at `/ai-dashboard/projects` with table view of all projects
- **Add Project Form** at `/ai-dashboard/projects/add` to create new projects with name and tags
- **URL-friendly Routes** using project name slugs instead of IDs (e.g., `/ai-dashboard/project/strategic-evolution/issues`)
- **Drag-and-Drop Reordering** with visual feedback and save functionality
- **Unlimited Indentation** for epic/sub-issue hierarchies with indent/outdent buttons
- **Collapsible Epics** with persistent state in localStorage
- **Standard Filters** (tag, priority, status, track, workstream) matching other dashboard pages
- **Permission-based Controls** - only admins can reorder and modify hierarchy
- **Auto-reload on Save** to ensure fresh data after reordering

### Database Structure:
- Uses `ai_dashboard_project_issue` table for storing:
  - Project-issue relationships (`project_nid`, `issue_nid`)
  - Weight/order for drag-drop positioning (`weight`)
  - Indent level for hierarchy (`indent_level`)
  - Parent issue relationships (`parent_issue_nid`)
  - **Note**: All Gantt-specific fields removed for clean deployment

### Technical Implementation:
- **Controller**: `ProjectController.php` for project list and `ProjectIssuesController.php` for issue management
- **Form**: `ProjectForm.php` for creating new projects
- **JavaScript**: Clean drag-drop with visual feedback and automatic parent calculation
- **CSS**: Compact table layout optimized for high information density
- **Database Updates**: Consolidated into single update hook 9031 for clean deployment

### Deployment Notes:
- **Database Update 9031**: Creates all project infrastructure in one atomic operation
- **Removed Gantt Fields**: `planned_start`, `planned_end`, `percent_complete` removed from schema
- **Placeholder Updates**: 9032-9034 are placeholders (functionality consolidated into 9031)

#### Technical Architecture

**Controller**: `src/Controller/PriorityKanbanController.php`
- Main kanban view method
- Column configuration and logic
- Issue querying and grouping
- Permission checking

**Service**: `src/Service/KanbanService.php` 
- Column logic calculations
- Issue filtering and sorting
- Change tracking between weeks
- Group-by/sort-by implementations

**Template**: `templates/priority-kanban.html.twig`
- Responsive kanban grid layout
- Column containers with collapsible sections
- **Shared card components** (identical to calendar view cards)
- Drag-drop zones and indicators

**CSS**: `css/priority-kanban.css`
- Grid-based responsive layout
- Column styling and animations
- Card hover states and transitions
- Collapsible column animations

**JavaScript**: `js/priority-kanban.js`
- Sortable.js integration
- Column collapse/expand functionality
- Drag-drop event handling
- LocalStorage for user preferences

#### Requirements Clarification ✅

1. **Priority Tag Definition**: Use drupal.org "priority" tag to filter issues ✅
2. **Module Scope**: All 25+ modules (priority tag filters which issues appear) ✅
3. **Change Management**: Simple change indicators for last 7 days ✅
4. **Permissions**: Same as calendar (`access ai dashboard`) ✅
5. **Mobile Support**: Full mobile kanban support ✅
6. **Integration**: Additional top-level page ✅
7. **Card Consistency**: Cards must be identical to calendar view cards ✅

#### Change Management Strategy (Simplified)

**Problem**: Tech lead needs to quickly identify which issues have changed since last week for stand-up discussions.

**Simplified Solution**: 
1. **"Changes This Week" Badge**: Issues with `changed_date >= 7 days ago` get a "🆕 Updated" badge
2. **Manual Summary Handling**: Use existing AI Tracker update summary functionality for stand-up notes

**Benefits**: 
- Simple visual indicator of changed issues
- Leverages existing update summary system
- Minimal complexity, maximum value

#### Dependencies

- Existing AI Dashboard module infrastructure
- Sortable.js library (CDN or local installation)
- Current issue import and metadata processing system
- Assignment record system for drag-drop updates

---

**Next Steps**: Get user approval on library choice and implementation approach before proceeding with development.

---

**NOTE**: This file should remain lean and focused. Completed plans should be removed and relevant information integrated into:
- `CLAUDE.md` - For permanent project guidance
- `AI_DASHBOARD_DOCUMENTATION.md` - For user-facing feature documentation
- Code comments - For technical implementation details
