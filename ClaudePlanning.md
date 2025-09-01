# Claude Planning - AI Dashboard Module

This file serves as a **temporary planning workspace** for Claude Code when developing new functionality for the AI Dashboard module. Plans documented here should be **deleted once completed** and relevant information moved to permanent documentation.

## Purpose

- **Planning Workspace**: Document feature plans, technical approaches, and implementation strategies
- **Temporary Storage**: Keep work-in-progress plans that aren't ready for permanent documentation
- **Collaboration Tool**: Allow user review of plans before implementation begins
- **Clean Documentation**: Prevent permanent docs from being cluttered with incomplete or experimental ideas

## Usage Guidelines

### For Claude Code:
1. **Create detailed plans** for new features or significant changes
2. **Document technical approaches** with file paths, methods, and implementation details
3. **List prerequisites** and dependencies for complex features
4. **Get user approval** before proceeding with implementation
5. **Delete completed plans** and move relevant content to permanent documentation

### For Users:
1. **Review plans** before giving implementation approval
2. **Provide feedback** on technical approaches and priorities
3. **Request modifications** to scope or implementation strategy
4. **Confirm deletion** of completed plans once features are done

## Current Plans

### Status: Kanban Board (Phase 1) — Implemented

Delivered `/ai-dashboard/priority-kanban` with calendar‑style cards, tag filtering (defaults to `priority`, persists across sessions), per‑column toggles with persistence, counts, Updated badge, and assignee profile links. Column logic implemented for Todos, Needs Review, Past Check‑in Date, Working On, Blocked, RTBC, Fixed.

---

### Priority Kanban Board for Weekly Stand-ups

**Requested**: A Priority Kanban Board page for weekly stand-ups focusing on issues marked with priority tags across all tracked modules.

#### Core Requirements
- **Target Audience**: Weekly stand-ups (primary), maintainers (secondary)
- **Scope**: Priority-tagged issues from all tracked AI modules
- **Layout**: Main kanban board with collapsible additional columns
- **Purpose**: Unblock issues by activating/redirecting people

#### Remaining Column Work
1. Blocker Issues (reverse dependency grouping) — NOT STARTED
2. Check‑in Date (upcoming emphasis and validation) — NOT VALIDATED

#### Technical Considerations

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

**Phase 4: Enhancement** (Optional - Future Considerations)
1. Performance optimization for large datasets
2. Mobile gesture improvements

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
