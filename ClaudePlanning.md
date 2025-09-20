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

---

# AI Dashboard Deliverables Feature - Implementation Plan

## Executive Summary for Stakeholders

### What We're Building
We're creating a **Deliverables Management System** to track and report on major AI module development milestones. Issues tagged with "AI Deliverable" from drupal.org will be displayed in a professional roadmap view with progress tracking and burndown analytics.

### Key Business Value
1. **Roadmap Visualization** - Clean board showing deliverables in Complete/Now/Next/Later columns
2. **Automatic Progress Tracking** - Completion percentages calculated from indented sub-issues
3. **Burndown Analytics** - Charts showing actual vs. expected progress over time
4. **Project Integration** - Enhanced project views showing deliverable progress
5. **Hierarchical Support** - Two-level hierarchy (deliverables with sub-deliverables)

### User Experience

#### 1. Roadmap View
- **New Menu Item** between Calendar and Kanban in top navigation
- **Card Layout** matching reference design with color-coded columns
- **Progress Bars** showing completion based on sub-issue status
- **Click-through** to detailed project issue view

#### 2. Project Enhancement
- **Deliverable Badges** on project issues that are tagged
- **Progress Summary** dashboard within project view
- **Top-Level Setting** to designate primary deliverable per project

#### 3. Analytics
- **Burndown Charts** using existing Chart.js library
- **Historical Tracking** via daily cron snapshots
- **Velocity Metrics** for sprint planning

### Implementation Phases

#### Phase 1: Foundation
- Recognize "AI Deliverable" tag from imports
- Create roadmap view with status columns
- Add menu item to navigation

#### Phase 2: Progress Tracking
- Calculate progress from existing indent hierarchy
- Add progress bars to deliverable cards
- Integrate with project views

#### Phase 3: Analytics
- Implement burndown data collection
- Create chart views
- Add historical tracking

#### Phase 4: Polish
- Performance optimization
- UI refinement based on testing
- Cache optimization

---

## Phase-by-Phase Testable Deliverables

### Phase 1: Foundation - What You Can Test
**Deliverables:**
1. ✅ New "Roadmap" link appears in navigation between Calendar and Kanban
2. ✅ `/ai-dashboard/roadmap` page loads showing 4 columns (Complete/Now/Next/Later)
3. ✅ All issues with "AI Deliverable" tag appear in correct columns based on status
4. ✅ Project edit form has new "Primary Deliverable" field (optional dropdown)
5. ✅ Database has new `field_project_deliverable` field and `ai_dashboard_roadmap_order` table

**Testing:**
- Visit roadmap page, verify deliverables show in right columns
- Edit a project, see deliverable picker field
- Check that fixed issues → Complete, assignee+project → Now, assignee only → Next, no assignee → Later

### Phase 2: Project Integration - What You Can Test
**Deliverables:**
1. ✅ Deliverables section appears at TOP of project issues page
2. ✅ Each deliverable shows progress bar (X/Y complete)
3. ✅ "Hide completed issues" checkbox works
4. ✅ "Show only deliverables" checkbox filters correctly
5. ✅ Clicking deliverable navigates to its drupal.org page

**Testing:**
- Go to any project with deliverables
- See them prominently at top with progress
- Toggle filters to hide/show completed and deliverables
- Verify progress calculation matches indented sub-issues

### Phase 3: Analytics - What You Can Test
**Deliverables:**
1. ✅ Mini burndown charts appear next to deliverables on project page
2. ✅ Charts show last 2 weeks of progress
3. ✅ Hovering shows exact numbers
4. ✅ Charts update daily (after cron runs)

**Testing:**
- View project page with deliverables
- See mini charts showing trend lines
- Run cron, verify data updates next day
- Charts should show if work is accelerating or slowing

### Phase 4: Polish & Ordering - What You Can Test
**Deliverables:**
1. ✅ Admins see drag handles and can reorder deliverables within columns
2. ✅ Non-admins see clean cards without drag handles (no confusion)
3. ✅ Order persists on page reload WITHOUT requiring `drush cr`
4. ✅ All navigation links updated across 7 template files
5. ✅ Responsive design works on mobile
6. ✅ Documentation added to admin docs (simplified from this planning doc)

**Testing:**
- As admin, drag deliverables to reorder
- Refresh page, order maintained WITHOUT cache clear
- As non-admin, verify no drag handles visible
- Test on mobile, columns should stack vertically
- Check all dashboard pages have Roadmap link
- Verify docs at `/ai-dashboard/admin/documentation` include roadmap section

---

## Technical Implementation Plan (Final Revision)

### Architecture Reality Check

After deeper analysis:
- **"AI Deliverable" tag already imports** from drupal.org - no new tagging needed
- **No deliverables table needed** - everything derives from existing data
- **Project hierarchy exists** in `ai_dashboard_project_issue` table (per-project)
- **Projects need ONE new field** to link to their deliverable issue

### Phase 1: Foundation Components

#### 1.1 Database Changes - MINIMAL
**Purpose**: Add one field to link projects to deliverables, and one table for manual ordering

```php
// Just add ONE field to AI Project content type
// field_project_deliverable - Entity reference to ai_issue

// Update hook to add field storage
function ai_dashboard_update_9038() {
  // Create field storage for project deliverable reference
  $field_storage = FieldStorageConfig::create([
    'field_name' => 'field_project_deliverable',
    'entity_type' => 'node',
    'type' => 'entity_reference',
    'settings' => [
      'target_type' => 'node',
    ],
  ]);
  $field_storage->save();

  // Create field instance on ai_project
  $field = FieldConfig::create([
    'field_storage' => $field_storage,
    'bundle' => 'ai_project',
    'label' => 'Primary Deliverable',
    'description' => 'The main deliverable issue this project is working toward',
    'settings' => [
      'handler' => 'default:node',
      'handler_settings' => [
        'target_bundles' => ['ai_issue' => 'ai_issue'],
        'filter' => [
          'type' => 'tag',
          'tag' => 'AI Deliverable', // Only show issues tagged as deliverables
        ],
      ],
    ],
  ]);
  $field->save();
}
```

#### 1.2 Minimal Ordering Storage
**Purpose**: Store manual drag-drop ordering for deliverables in the roadmap view

```sql
-- Just store roadmap-specific ordering
CREATE TABLE ai_dashboard_roadmap_order (
  deliverable_nid INT UNSIGNED NOT NULL PRIMARY KEY,
  weight INT DEFAULT 0,
  FOREIGN KEY (deliverable_nid) REFERENCES node (nid) ON DELETE CASCADE,
  INDEX (weight)
);
```

#### 1.3 Roadmap Status Derivation
**Purpose**: Derive which column (Now/Next/Later/Complete) based on assignee and project linkage

```php
// Derive roadmap column - NO storage needed for status
function getDeliverableStatus($issue, $linked_project = NULL) {
  $status = $issue->field_issue_status->value;

  // Complete column
  if (in_array($status, ['fixed', 'closed_fixed', 'closed_duplicate', 'closed_works'])) {
    return 'complete';
  }

  // Check assignee
  if ($issue->field_assignee->isEmpty()) {
    return 'later'; // No assignee = Later
  }

  // Has assignee - check if in a tracker project
  if ($linked_project !== NULL) {
    return 'now'; // Has assignee AND in a project = Now (priority)
  }

  return 'next'; // Has assignee but NOT in project = Next (being worked on but not priority)
}
```

#### 1.4 Roadmap Controller with Ordering
**Purpose**: Main controller for the /ai-dashboard/roadmap page showing all deliverables

```php
// src/Controller/RoadmapController.php
class RoadmapController extends ControllerBase {
  public function view() {
    // 1. Get all issues with "AI Deliverable" tag
    $deliverables = $nodeStorage->getQuery()
      ->condition('type', 'ai_issue')
      ->condition('field_issue_tags', 'AI Deliverable', 'CONTAINS')
      ->execute();

    // 2. Load ordering data
    $ordering = $this->database->select('ai_dashboard_roadmap_order', 'ro')
      ->fields('ro', ['deliverable_nid', 'weight'])
      ->execute()
      ->fetchAllKeyed();

    // 3. Group by derived status
    $columns = ['complete' => [], 'now' => [], 'next' => [], 'later' => []];
    foreach ($deliverables as $nid) {
      $issue = Node::load($nid);

      // Find if any project links to this deliverable
      $linked_project = $this->findLinkedProject($nid);

      // Derive status based on assignee and project
      $status = $this->getDeliverableStatus($issue, $linked_project);

      // Calculate progress if project exists
      $progress = null;
      if ($linked_project) {
        $progress = $this->calculateProgressFromProject($nid, $linked_project->id());
      }

      $columns[$status][] = [
        'issue' => $issue,
        'progress' => $progress,
        'project' => $linked_project,
        'weight' => $ordering[$nid] ?? 0,
      ];
    }

    // 4. Sort within columns by weight, then by changed date
    foreach ($columns as &$column) {
      usort($column, function($a, $b) {
        // First sort by manual weight
        if ($a['weight'] != $b['weight']) {
          return $a['weight'] <=> $b['weight'];
        }
        // Then by changed date
        return $b['issue']->changed->value <=> $a['issue']->changed->value;
      });
    }

    return [
      '#theme' => 'ai_roadmap',
      '#columns' => $columns,
      '#user_has_admin' => $this->currentUser()->hasPermission('administer ai dashboard content'),
    ];
  }

  public function saveOrder(Request $request) {
    // AJAX endpoint for saving drag-drop order
    if (!$this->currentUser()->hasPermission('administer ai dashboard content')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    foreach ($data['items'] as $item) {
      $this->database->merge('ai_dashboard_roadmap_order')
        ->keys(['deliverable_nid' => $item['nid']])
        ->fields(['weight' => $item['weight']])
        ->execute();
    }

    // CRITICAL: Proper cache invalidation to avoid needing drush cr
    Cache::invalidateTags([
      'ai_dashboard:roadmap',
      'node_list:ai_issue', // Also invalidate issue lists
    ]);

    // Clear render cache for the roadmap specifically
    \Drupal::cache('render')->deleteAll();

    return new JsonResponse(['success' => true]);
  }

  private function findLinkedProject($deliverable_nid) {
    // Find project that references this deliverable
    $projects = $nodeStorage->getQuery()
      ->condition('type', 'ai_project')
      ->condition('field_project_deliverable', $deliverable_nid)
      ->execute();

    return !empty($projects) ? Node::load(reset($projects)) : null;
  }

  private function calculateProgressFromProject($deliverable_nid, $project_nid) {
    // Use existing ai_dashboard_project_issue hierarchy
    $query = $this->database->select('ai_dashboard_project_issue', 'pi')
      ->fields('pi', ['issue_nid'])
      ->condition('project_nid', $project_nid)
      ->condition('parent_issue_nid', $deliverable_nid);

    $sub_issues = $query->execute()->fetchCol();

    if (empty($sub_issues)) {
      return null; // No sub-issues, no progress to show
    }

    $completed = 0;
    foreach ($sub_issues as $issue_nid) {
      $issue = Node::load($issue_nid);
      if (in_array($issue->field_issue_status->value, ['fixed', 'closed_fixed'])) {
        $completed++;
      }
    }

    return [
      'percentage' => ($completed / count($sub_issues)) * 100,
      'completed' => $completed,
      'total' => count($sub_issues),
    ];
  }
}

### Phase 2: Project Integration

#### 2.1 Update ProjectForm for Deliverable Field
**Purpose**: Allow projects to optionally link to a main deliverable they're working toward

```php
// src/Form/ProjectForm.php - Add deliverable field
public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
  // ... existing fields ...

  // Add deliverable reference field
  $form['deliverable'] = [
    '#type' => 'entity_autocomplete',
    '#title' => $this->t('Primary Deliverable (Optional)'),
    '#target_type' => 'node',
    '#selection_settings' => [
      'target_bundles' => ['ai_issue'],
      // Only show issues with AI Deliverable tag
      'filter' => ['tags' => 'AI Deliverable'],
    ],
    '#description' => $this->t('Link this project to a deliverable issue. Progress will be calculated from issues indented under the deliverable in this project.'),
    '#default_value' => $node ? $node->field_project_deliverable->entity : NULL,
  ];
}
```

#### 2.2 Enhanced Project Issues Page
**Purpose**: Show deliverables at top of project page with progress, add filtering options

```php
// src/Controller/ProjectIssuesController.php - Enhanced with deliverables
public function manage(NodeInterface $node, Request $request) {
  // ... existing code ...

  // Get filters including new ones
  $filters = [
    'tag' => $request->query->get('tag', ''),
    'status' => $request->query->get('status', ''),
    'hide_closed' => $request->query->get('hide_closed', '0'), // NEW: Hide fixed/closed
    'deliverables_only' => $request->query->get('deliverables_only', '0'), // NEW: Show only deliverables
    // ... other existing filters ...
  ];

  // Load ALL deliverables in this project (not just the main one)
  $deliverables = $this->loadProjectDeliverables($node->id(), $filters['hide_closed']);

  // Load regular issues
  $issues = $this->loadProjectIssues($node->id(), $project_tags, $filters);

  $build = [
    '#theme' => 'ai_project_issues',
    '#project' => $node,
    '#deliverables' => $deliverables, // Show at top with progress bars
    '#issues' => $issues,
    '#filters' => $filters,
    // ... rest of build array ...
  ];
}

private function loadProjectDeliverables($project_id, $hide_closed = false) {
  // Find all issues in this project that have "AI Deliverable" tag
  $query = $this->entityTypeManager()->getStorage('node')->getQuery()
    ->condition('type', 'ai_issue')
    ->condition('field_issue_tags', 'AI Deliverable', 'CONTAINS');

  if ($hide_closed) {
    $query->condition('field_issue_status', ['fixed', 'closed_fixed'], 'NOT IN');
  }

  $deliverable_nids = $query->execute();
  $deliverables = [];

  foreach ($deliverable_nids as $nid) {
    // Check if this deliverable is in THIS project
    $in_project = $this->database->select('ai_dashboard_project_issue', 'pi')
      ->fields('pi', ['issue_nid'])
      ->condition('project_nid', $project_id)
      ->condition('issue_nid', $nid)
      ->execute()->fetchField();

    if ($in_project) {
      $issue = Node::load($nid);
      $progress = $this->calculateProgressFromProject($nid, $project_id);

      $deliverables[] = [
        'issue' => $issue,
        'progress' => $progress,
        'burndown_data' => $this->getBurndownData($nid), // For mini-chart
      ];
    }
  }

  return $deliverables;
}
```

#### 2.3 Project Template Updates
**Purpose**: Display deliverables prominently at top of project issues page

```twig
{# templates/ai-project-issues.html.twig #}

{# Deliverables Section at Top #}
{% if deliverables %}
<div class="project-deliverables-section">
  <h2>Deliverables in This Project</h2>
  <div class="deliverables-grid">
    {% for deliverable in deliverables %}
      <div class="deliverable-summary-card">
        <h3>{{ deliverable.issue.title }}</h3>
        <div class="progress-bar-container">
          <progress value="{{ deliverable.progress.percentage }}" max="100">
            {{ deliverable.progress.percentage }}%
          </progress>
          <span>{{ deliverable.progress.completed }}/{{ deliverable.progress.total }} complete</span>
        </div>
        {# Mini burndown chart #}
        <canvas class="mini-burndown" data-nid="{{ deliverable.issue.id }}"></canvas>
      </div>
    {% endfor %}
  </div>
</div>
{% endif %}

{# Filter toggles #}
<div class="project-filters">
  <label>
    <input type="checkbox" name="hide_closed" {% if filters.hide_closed %}checked{% endif %}>
    Hide completed issues
  </label>
  <label>
    <input type="checkbox" name="deliverables_only" {% if filters.deliverables_only %}checked{% endif %}>
    Show only deliverables
  </label>
  {# ... existing filters ... #}
</div>

{# Regular issues table below #}
```

#### 2.4 Simple Caching
```php
// Cache tags - reuse existing patterns
$build['#cache'] = [
  'tags' => [
    'node_list:ai_issue',
    'node_list:ai_project',
  ],
  'contexts' => ['url.path'],
];
```

### Phase 3: Analytics (Simplified for MVP)

#### 3.1 Basic Progress History Table
**Purpose**: Store daily snapshots of deliverable progress to create burndown charts

```sql
-- Simple progress snapshots
CREATE TABLE ai_dashboard_deliverable_history (
  deliverable_nid INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  completed INT DEFAULT 0,
  total INT DEFAULT 0,
  PRIMARY KEY (deliverable_nid, date),
  FOREIGN KEY (deliverable_nid) REFERENCES node (nid) ON DELETE CASCADE
);
```

#### 3.2 Consider: Use Issue History Instead of Snapshots?
**Question**: Issues don't store status change history in fields (just current status)
**Answer**: We need snapshots because:
- Sub-issue count changes over time (issues added/removed from project)
- Need point-in-time totals for accurate burndown
- drupal.org API doesn't provide historical data

```php
// ai_dashboard.module - Simple daily snapshot
function ai_dashboard_cron() {
  // Runs once daily to capture current state
  // Without this, we can't show progress over time

  $deliverables = \Drupal::entityQuery('node')
    ->condition('type', 'ai_issue')
    ->condition('field_issue_tags', 'AI Deliverable', 'CONTAINS')
    ->execute();

  foreach ($deliverables as $nid) {
    // Find projects containing this deliverable
    $projects = $this->database->select('ai_dashboard_project_issue', 'pi')
      ->fields('pi', ['project_nid'])
      ->condition('issue_nid', $nid)
      ->execute()->fetchCol();

    foreach ($projects as $project_nid) {
      $progress = calculateProgressFromProject($nid, $project_nid);

      // One row per day per deliverable
      \Drupal::database()->merge('ai_dashboard_deliverable_history')
        ->keys([
          'deliverable_nid' => $nid,
          'date' => date('Y-m-d'),
        ])
        ->fields([
          'completed' => $progress['completed'],
          'total' => $progress['total'],
        ])
        ->execute();
    }
  }
}
```

#### 3.3 Simple Burndown Display
**Purpose**: Show progress trends on project pages so teams can see if they're on track

```javascript
// js/project-issues.js - Add mini burndown charts to project page
function renderMiniBurndowns() {
  // Purpose: Display small burndown charts next to each deliverable
  // on the project issues page

  $('.mini-burndown').each(function() {
    const canvas = this;
    const nid = $(canvas).data('nid');

    // Fetch history via AJAX
    $.get('/ai-dashboard/api/burndown/' + nid, function(data) {
      new Chart(canvas, {
        type: 'line',
        data: {
          labels: data.dates.slice(-14), // Last 2 weeks
          datasets: [{
            label: 'Remaining',
            data: data.remaining.slice(-14),
            borderColor: 'rgb(255, 99, 132)',
            tension: 0.1,
            pointRadius: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false }
          },
          scales: {
            x: { display: false },
            y: { beginAtZero: true }
          }
        }
      });
    });
  });
}

### Phase 4: UI/UX Implementation Details

#### 4.1 Navigation Integration
**Purpose**: Add Roadmap link to all dashboard pages' navigation

```twig
{# templates/ai-roadmap.html.twig #}
<div class="dashboard-navigation">
  <div class="nav-links">
    <a href="/ai-dashboard" class="nav-link">Dashboard</a>
    <a href="/ai-dashboard/calendar" class="nav-link">Calendar View</a>
    <a href="/ai-dashboard/calendar/organizational" class="nav-link">Organizational View</a>
    <a href="/ai-dashboard/roadmap" class="nav-link active">Roadmap</a> {# NEW #}
    <a href="/ai-dashboard/priority-kanban" class="nav-link">Kanban</a>
    <a href="/ai-dashboard/projects" class="nav-link">Projects</a>
    <a href="/ai-dashboard/docs" class="nav-link">Docs</a>
  </div>
</div>
```

**Navigation CSS** (`shared-components.css`):
```css
.dashboard-navigation {
  background: linear-gradient(135deg, #0073aa, #005a87);
  padding: 1rem;
  margin-bottom: 0;
}

.nav-links {
  display: flex;
  gap: 0;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 8px;
  padding: 4px;
  max-width: 800px; /* Increased from 600px to prevent wrapping */
}
```

#### 4.2 Roadmap Card Design
**Purpose**: Style deliverable cards to match reference design with clear status colors

```css
/* css/roadmap.css - Match reference design colors */
.roadmap-columns {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
}

.roadmap-column.complete { background: #4a7c59; }
.roadmap-column.now { background: #2c3e50; }
.roadmap-column.next { background: #f39c12; }
.roadmap-column.later { background: #e74c3c; }

.deliverable-card {
  background: white;
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 0.5rem;
  cursor: pointer;
  transition: transform 0.2s;
}

.deliverable-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
```

#### 4.3 JavaScript Components with Ordering
```javascript
// js/roadmap.js
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.aiRoadmap = {
    attach: function (context, settings) {
      // Initialize Popper.js tooltips (NOT Tippy.js)
      $('.deliverable-card', context).once('roadmap-tooltips').each(function() {
        if (typeof Popper !== 'undefined') {
          // Use existing Popper.js pattern from focus-tooltips.js
        }
      });

      // Only show drag handles and enable sorting for admins
      if (settings.aiDashboard.userHasAdmin) {
        // Add drag handles to cards (only visible to admins)
        $('.deliverable-card', context).addClass('draggable').prepend('<div class="drag-handle">⋮⋮</div>');

        $('.roadmap-column', context).once('roadmap-sortable').each(function() {
          const column = this;
          Sortable.create(column, {
            animation: 150,
            handle: '.drag-handle',
            onEnd: function(evt) {
              // Collect new order
              const items = [];
              $(column).find('.deliverable-card').each(function(index) {
                items.push({
                  nid: $(this).data('nid'),
                  weight: index
                });
              });

              // Save via AJAX
              $.ajax({
                url: '/ai-dashboard/roadmap/save-order',
                method: 'POST',
                data: JSON.stringify({ items: items }),
                contentType: 'application/json',
                headers: { 'X-CSRF-Token': drupalSettings.csrf_token },
                success: function() {
                  // Show success briefly
                  $(column).addClass('save-success');
                  setTimeout(() => $(column).removeClass('save-success'), 1000);
                }
              });
            }
          });
        });
      }
    }
  };
})(jQuery, Drupal);
```

#### 4.4 Library Definitions
```yaml
# ai_dashboard.libraries.yml
roadmap:
  css:
    theme:
      css/roadmap.css: {}
      css/shared-components.css: {} # Reuse existing navigation styles
  js:
    js/roadmap.js: {}
  dependencies:
    - core/jquery
    - core/drupal
    - core/once
    - ai_dashboard/sortable # Reuse from project_issues
```

### Technical Considerations & Gotchas

#### Specific Implementation Requirements
1. **Tooltip Library**: Use **Popper.js** (already in codebase) - NOT Tippy.js
2. **Navigation**: Add to hardcoded template nav-links, ensure max-width prevents wrapping
3. **CSS Libraries**: Must attach `shared-components` library for navigation styling
4. **Cache Invalidation CRITICAL**:
   - Use multiple cache tags: `ai_dashboard:roadmap`, `node_list:ai_issue`
   - Clear render cache after order changes: `\Drupal::cache('render')->deleteAll()`
   - Without proper invalidation, changes only appear after `drush cr`
5. **Drag Handles**: Only show for admins - non-admins should see clean interface
6. **Progress Calculation**: Query `ai_dashboard_project_issue` table for hierarchy
7. **Status Detection**: Check `field_issue_status` for 'fixed' or 'closed_fixed'
8. **Tag Query**: Use `field_issue_tags` with CONTAINS operator for "AI Deliverable"
9. **Documentation**: Move simplified version of this plan to admin docs in Phase 4

#### Performance Optimization
- **Batch Processing**: Calculate progress in batches for large deliverables
- **Lazy Loading**: Load burndown data on-demand, not on page load
- **Database Indexes**: Proper indexes on frequently queried columns
- **Query Optimization**: Use single queries with JOINs vs. multiple queries

#### Security Considerations
- **Access Control**: Reuse existing `access ai dashboard` permission
- **CSRF Protection**: For AJAX endpoints updating deliverable status
- **XSS Prevention**: Sanitize all user input in deliverable descriptions

### Future Enhancements (Post-MVP)

#### Gantt Chart Integration (Deferred)
- Challenge: Aligning issue metadata with Gantt library constraints
- Previous attempt had sync issues between left panel and chart
- Consider alternative: Timeline view with simpler implementation

#### Advanced Features
- Email notifications for deliverable milestones
- Slack/Teams integration for status updates
- Custom fields for deliverable metadata (budget, resources)
- Multi-level deliverable hierarchy (sub-sub-deliverables)
- Deliverable templates for common project types

### Testing Strategy

#### Unit Tests
- `DeliverableProgressServiceTest` - Progress calculation logic
- `RoadmapControllerTest` - Data loading and grouping

#### Functional Tests
- Roadmap page rendering
- Deliverable creation and tagging
- Progress bar accuracy
- Burndown chart data points

#### Manual Testing Checklist
- [ ] Roadmap loads with correct status columns
- [ ] Deliverables show accurate progress percentages
- [ ] Clicking deliverable navigates to detail view
- [ ] Burndown charts render with historical data
- [ ] Menu appears correctly on all pages
- [ ] Responsive design works on mobile
- [ ] Caching works without requiring `drush cr`
- [ ] Tooltips appear using Tippy.js
- [ ] Admin-only features properly restricted

### Deployment Plan

1. **Database Updates**: Run `drush updb` for schema changes
2. **Cache Clear**: One-time `drush cr` after deployment
3. **Cron Setup**: Enable burndown history collection
4. **Documentation**: Update user guides with new features
5. **Training**: Quick demo for stakeholders

---

## Documentation to Add to AI_DASHBOARD_DOCUMENTATION.md

### Roadmap Feature

#### Purpose
The Roadmap provides a high-level view of all AI Deliverables across the project, organized by status to show what's complete, being worked on now, planned next, and future items. It helps stakeholders understand priorities and progress at a glance.

#### How It Works
1. **Automatic Discovery**: Any issue tagged "AI Deliverable" on drupal.org appears on the roadmap
2. **Status Columns**: Issues automatically sort into columns based on:
   - **Complete**: Fixed or closed issues
   - **Now**: Has assignee AND is in a tracker project (priority work)
   - **Next**: Has assignee but NOT in a project (being worked on elsewhere)
   - **Later**: No assignee yet
3. **Progress Tracking**: When a deliverable is in a project, shows % complete based on sub-issues
4. **Manual Ordering**: Admins can drag-drop to reorder within columns

#### Project Integration
- Projects can optionally link to a primary deliverable
- All deliverables in a project show at the top of the project page
- Progress bars show completion based on indented sub-issues
- Mini burndown charts show 2-week trends

#### Managing Deliverables
- Add "AI Deliverable" tag on drupal.org to make something appear
- Remove tag to hide from roadmap
- Assign to move from Later → Next
- Add to project to move from Next → Now
- Mark fixed/closed to move to Complete

---

## Summary - FINAL Approach with Correct Status Logic

### What We Need
1. **ONE new field** - Add `field_project_deliverable` to AI Project content type
2. **ONE small table** - Just for roadmap ordering (`ai_dashboard_roadmap_order`)
3. **Status derived from assignee + project** - No manual status management

### Status Column Logic
- **Complete**: fixed/closed issues
- **Now**: Has assignee AND linked to a tracker project (priority work)
- **Next**: Has assignee but NOT in a project (being worked on, not priority)
- **Later**: No assignee

*Note: If something isn't active, just remove "AI Deliverable" tag on drupal.org*

### How It Works
1. **Projects optionally link to deliverables**: Entity reference field on project form
2. **Roadmap finds deliverables**: Query `field_issue_tags CONTAINS 'AI Deliverable'`
3. **Progress from project hierarchy**: Use project's `ai_dashboard_project_issue` data
4. **Manual ordering**: Admin drag-drop with weights stored in minimal table

### Implementation Phases
- **Phase 1**: Add field & ordering table, create roadmap view
- **Phase 2**: Update project forms with deliverable picker
- **Phase 3**: Add drag-drop ordering for admins
- **Phase 4**: Polish UI with existing patterns

### Why This Approach Works
- **Minimal storage** - Just ordering weights, everything else derived
- **Project-centric** - Each project defines its own issue hierarchy
- **Clear priorities** - "Now" = in a project, "Next" = being worked on elsewhere
- **Flexible** - Projects can have deliverables or be ongoing

### Technical Specifics
- Use **Popper.js** for tooltips (existing library)
- Add navigation to 7 template files with hardcoded nav-links
- Apply `shared-components.css` for consistent styling
- Sortable.js for admin drag-drop (already in project_issues)
- Cache tags: `node_list:ai_issue`, `ai_dashboard:roadmap`