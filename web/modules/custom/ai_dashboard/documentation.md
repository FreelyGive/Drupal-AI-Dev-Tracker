# AI Dashboard Deliverables Feature Documentation

## Overview
The Deliverables feature provides comprehensive tracking and visualization of AI Deliverable tagged issues from drupal.org. It includes a 4-column roadmap view, project-specific issue pages with burndown charts, and administrative drag-and-drop ordering capabilities.

## Phase 1: Core Deliverables System (Completed)

### Database Schema
- **Table: `ai_dashboard_roadmap_order`**
  - `nid` (int): Issue node ID
  - `column` (varchar): Status column (complete/now/next/later)
  - `weight` (int): Order within column
  - `changed` (int): Last modified timestamp

### Field Additions
- **AI Issue Content Type**:
  - `field_short_description` (string, 255 chars): Brief summary for roadmap display (update hook 9039)
  - `field_short_title` (string, 100 chars): Stakeholder-friendly title without Drupalisms (update hook 9044)

### Controllers
- **RoadmapController** (`/ai-dashboard/roadmap`):
  - Displays 4-column roadmap view
  - Filters issues by "AI Deliverable" tag
  - Groups by status: Complete, Now, Next, Later
  - Supports admin drag-and-drop ordering with AJAX save
  - Uses both tag matching and database table for project membership

- **BurndownController** (`/ai-dashboard/deliverable/{nid}/burndown-data`):
  - Returns JSON data for burndown charts
  - Calculates ideal vs actual progress
  - Tracks completion velocity and projections
  - Based on historical issue status changes

### Templates
- **ai-roadmap.html.twig**:
  - Clean 4-column layout (Complete, Now, Next, Later)
  - Simplified cards showing Short Title (or fallback) and Short Description
  - Clickable title links to drupal.org issue
  - Icons in top-right: arrow (↗) to drupal.org, cog (⚙) to edit (admin only)
  - Admin-only save button for drag-drop ordering

- **ai-project-issues.html.twig**:
  - Primary deliverable as subtitle
  - Deliverables section with detailed cards
  - Progress tracking and burndown chart integration

### JavaScript Libraries
- **roadmap.js**:
  - Sortable.js integration for drag-drop
  - AJAX save for order persistence
  - Card click navigation to project pages
  - Admin-only features detection

- **burndown.js**:
  - Chart.js integration
  - Expandable burndown charts on deliverable cards
  - Modal/maximize functionality for better visibility
  - Lazy loading on user interaction
  - Project page specific targeting

### CSS Styling
- **roadmap.css**:
  - 4-column responsive layout
  - Status-specific column colors
  - Drag-drop visual feedback
  - Modal styles for burndown charts

## Phase 2: Enhanced Features (Completed)

### Progress Calculation
- Automatically calculates completion percentage based on child issues
- Uses parent_issue_nid relationships to find children
- Displays progress bars on roadmap and project pages
- Format: "X/Y complete" with percentage bar

### Drag-and-Drop Ordering
- Admin users can reorder cards between columns
- Order persists in database
- Visual feedback during drag operations
- Save button appears when changes made

### Burndown Charts
- Available on project issue pages only (not roadmap)
- Toggle button to show/hide chart
- Maximize feature for full-screen view
- Shows:
  - Ideal vs actual burndown lines
  - Total issues, completed, remaining
  - Weekly velocity
  - Projected completion date
  - Due date if set

## Database Update Hooks

### Production-Ready Hooks
- **9039**: Adds field_short_description to AI Issues
- **9040**: Creates ai_dashboard_roadmap_order table
- **9041**: Fixes table structure with correct column names
- **9044**: Adds field_short_title to AI Issues

### Cleanup Notes
- Hooks 9039-9041, 9044 are required for production
- Earlier hooks (8001-9038) were development iterations
- All hooks use proper Drupal database API
- No test/dummy data in update hooks

## API Endpoints

### Public Endpoints
- `/ai-dashboard/roadmap` - Main roadmap view
- `/ai-dashboard/project/{slug}/issues` - Project issue view

### AJAX Endpoints (Admin Only)
- `/ai-dashboard/roadmap/save-order` - Save drag-drop ordering
- `/ai-dashboard/deliverable/{nid}/burndown-data` - Burndown chart data

## Permissions
- **View**: Public access to roadmap and project pages
- **Admin**: Required for drag-drop ordering and save functionality
- Permission: `administer ai dashboard content`

## Integration Points

### Project Membership Detection
The system uses dual detection for project membership:
1. **Tag-based**: Checks if issue tags match project tags
2. **Database-based**: Uses ai_dashboard_project_issue table

This ensures issues appear correctly regardless of how they were added.

### Issue Hierarchy
- Deliverables identified by "AI Deliverable" tag
- Child issues linked via parent_issue_nid field
- Progress calculated from child issue statuses
- Status mapping: fixed/closed/rtbc = complete

### Drupal.org Integration
- Issue data imported via existing import system
- Metadata extraction from issue summaries using `[Tracker]...[/Tracker]` blocks
- Short Title and Short Description auto-populated from metadata during import
- Issue numbers link directly to drupal.org

## Performance Considerations

### Caching
- Roadmap data cached with tag-based invalidation
- Burndown data calculated on-demand (not cached)
- JavaScript charts rendered client-side

### Optimization
- Drag-drop operations use lightweight AJAX
- Progress calculation done in single query
- Burndown charts loaded only when requested
- Modal charts destroyed when closed to free memory

## User Experience

### Roadmap View
- Clean, stakeholder-focused interface
- Cards show Short Title and Short Description only
- Click title or arrow icon to view issue on drupal.org
- Admin users see cog icon to edit deliverable locally
- Drag-drop ordering between columns (admin only)

### Project Issue Pages
- Primary deliverable as prominent subtitle
- Detailed deliverable cards with all information
- Expandable burndown charts for analysis
- Maintains existing issue list functionality

### Admin Features
- Drag-drop only visible to admins
- Save button appears on change
- Feedback messages for save operations
- Seamless integration with existing UI

## Technical Dependencies

### External Libraries
- **Sortable.js**: Drag-and-drop functionality
- **Chart.js**: Burndown chart visualization
- **jQuery**: DOM manipulation and AJAX

### Drupal Dependencies
- Core entity system
- Views for issue listing
- User permissions system
- Database API

## Future Considerations

### Potential Enhancements
- Burndown chart data caching for performance
- Historical ordering snapshots
- Bulk operations for status changes
- Export functionality for roadmap data

### Maintenance Notes
- Update hooks are idempotent and production-ready
- No hardcoded test data in codebase
- All features use Drupal best practices
- Clean separation of concerns in architecture

## Deployment Checklist

1. ✅ Run update hooks (9039, 9040, 9041, 9044) via `drush updb`
2. ✅ Clear cache after deployment
3. ✅ Verify permissions for admin users
4. ✅ Test drag-drop on production environment
5. ✅ Confirm burndown charts load correctly on project pages
6. ✅ Check responsive layout on mobile devices
7. ✅ Verify roadmap cards display Short Title when available

## Summary

The Deliverables feature successfully implements a clean, focused roadmap view with administrative controls and detailed project pages. It maintains simplicity for end users while providing powerful tools for administrators to track and organize AI development efforts. The implementation follows Drupal best practices and is ready for production deployment.