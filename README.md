# AI Dashboard - Drupal Development Tracker

A Drupal 11 application for tracking AI module development on Drupal.org. Provides calendar views, project management, issue tracking, and progress reporting for the Drupal AI initiative.

## Features

- **Weekly Calendar View**: Visualize developer assignments and issue progress
- **Priority Kanban Board**: Drag-and-drop issue organization by status
- **Deliverables Roadmap**: Track deliverables through Complete, Now, Next, Later stages
- **Project Management**: Organize issues into projects with custom ordering and hierarchies
- **Automated Issue Import**: Sync from drupal.org with tag mapping and metadata extraction
- **Contributor Reports**: Track contributor activity and untracked users

## Requirements

- [DDEV](https://ddev.com/get-started/) - Local development environment
- [Docker](https://www.docker.com/get-started/) - Required by DDEV
- PHP 8.3+
- Drupal 11

## Installation

### Local Development Setup

```bash
# Clone the repository
git clone https://github.com/FreelyGive/Drupal-AI-Dev-Tracker.git
cd Drupal-AI-Dev-Tracker

# Create a new branch for your work
git checkout -b feature/your-feature-name

# Start DDEV and install dependencies
ddev start
ddev composer install

# Install Drupal with existing configuration
ddev drush site:install --existing-config --account-pass=admin

# Launch the site
ddev launch
```

**Login**: Username `admin`, Password `admin`

**Next Steps (in order):**
1. Import contributors from live site: `ddev drush aid-import-contributors`
   - Or manually via CSV upload at `/ai-dashboard/admin/contributor-import`
2. Import issues from drupal.org: `ddev drush ai-dashboard:import-all`
3. Import content from live site: `ddev drush aid-cimport`
   - This imports projects, tag mappings, assignment history, and relationships
   - Must be done AFTER importing issues since it references them

**Note:** Always work in a feature branch, never directly in `main`. Use descriptive branch names like `feature/add-new-report` or `bugfix/calendar-timezone`.

### Production Deployment

This site deploys to Drupal Forge via DevPanel:

1. Push changes to GitHub
2. DevPanel automatically deploys
3. Run post-deployment commands:
   ```bash
   drush updb -y
   drush cim -y
   drush cr
   ```

## Usage

### Key Pages

- **Calendar**: `/ai-dashboard/calendar` - Weekly view of developer assignments
- **Kanban**: `/ai-dashboard/priority-kanban` - Issue management board
- **Projects**: `/ai-dashboard/projects` - Project organization
- **Roadmap**: `/ai-dashboard/roadmap` - Deliverables tracking
- **Reports**: `/ai-dashboard/reports` - Contributor analytics

### Common Commands

```bash
# Import contributors from live site
ddev drush aid-import-contributors

# Import issues from drupal.org
ddev drush ai-dashboard:import-all

# Sync content from live site
ddev drush aid-cimport

# Export content for deployment
ddev drush aid-cexport

# Update configuration
ddev drush cim -y
ddev drush cr
```

### Content Syncing

The `aid-cexport` and `aid-cimport` commands sync configuration and content between environments:

```bash
# On live site (export)
drush aid-cexport

# On local site (import)
git pull
drush aid-cimport
```

**What syncs**: Configuration, tag mappings, projects, assignment history, project-issue relationships, roadmap ordering.

**What doesn't sync**:
- AI Issues - re-import from drupal.org via `aid-import-all`
- Contributors/Companies - import via `aid-import-contributors` or CSV upload

## Development

See [CLAUDE.md](CLAUDE.md) for detailed development documentation including:
- Development workflow
- Configuration management
- Testing procedures
- Command reference

## Documentation

- [Developer Documentation (CLAUDE.md)](CLAUDE.md) - Complete development guide
- [Online Documentation](https://drupalstarforge.ai/ai-dashboard/admin/documentation) - Feature documentation (login required)

## License

GPL-2.0-or-later - See Drupal's [license](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
