# AI Dashboard - Drupal Development Tracker

AI Dashboard is a Drupal 11 application for tracking AI module development on Drupal.org. It provides calendar views, project management, issue tracking, and progress reporting for the Drupal AI initiative.

## Features

- **AI Module Calendar**: Weekly calendar view showing developer assignments and issue progress
- **Priority Kanban Board**: Drag-and-drop kanban for organizing issues by priority
- **Deliverables Roadmap**: Track AI deliverables through Complete, Now, Next, Later stages
- **Project Management**: Organize issues into projects with custom ordering
- **Issue Import System**: Automated import from drupal.org with tag mapping and metadata extraction
- **Reports**: Contributor analysis, untracked users, import status

## Prerequisites

- [DDEV](https://ddev.com/get-started/) - Local development environment
- [Docker](https://www.docker.com/get-started/) - Required by DDEV
- [Git](https://git-scm.com/) - Version control
- [Composer](https://getcomposer.org/) - PHP dependency management (included with DDEV)

## Getting Started

### 1. Clone and Start DDEV

```bash
# Clone the repository
git clone https://github.com/FreelyGive/Drupal-AI-Dev-Tracker.git
cd Drupal-AI-Dev-Tracker

# Create a new branch for your work
git checkout -b feature/your-feature-name

# Start DDEV
ddev start

# Install PHP dependencies
ddev composer install
```

**Note:** Always work in a feature branch, never directly in `main`. Use descriptive branch names like `feature/add-csv-download` or `bugfix/calendar-performance`.

### 2. Install Drupal with Existing Configuration

This command installs Drupal using all the configuration already in git:

```bash
ddev drush site:install --existing-config --account-pass=admin
```

This will:
- Create a fresh database
- Import all configuration from `config/sync/`
- Create all content types, fields, and views
- Set admin password to `admin`

### 3. Import Contributor and Company Data

**TODO: Implement secure CSV download from contributor import page**

Currently the contributor CSV is maintained in a private Google Sheet. Once we implement the download feature, users will be able to download the latest CSV securely from the admin interface.

For now, you'll need to obtain the CSV file from the site administrator and import it:

1. Get the latest contributor CSV file
2. Visit: `https://drupal-ai-dev-tracker.ddev.site/ai-dashboard/admin/contributor-import`
3. Upload the CSV file
4. The import will create:
   - AI Company nodes
   - AI Contributor nodes
   - Linking between contributors and companies

**CSV Column Format:**
1. Name
2. Username (d.o)
3. Organization
4. AI Sponsor? (Yes/No)
5. Tracker Role (comma-separated)
6. Skills (comma-separated)
7. Commitment (days/week)
8. Company Drupal Profile
9. Current Focus
10. GitLab Username or Email

### 4. Import Issues from Drupal.org

Run the import command to fetch issues from all configured import sources:

```bash
ddev drush ai-dashboard:import-all
```

This will:
- Import issues from drupal.org based on module import configurations
- Process AI Tracker metadata from issue summaries
- Apply tag mappings to populate track/workstream fields
- Sync issue assignments
- Fetch organization data for untracked users

**Note:** This can take several minutes on first run. The command is safe to stop and restart.

### 5. Access the Site

```bash
# Launch the site in your browser
ddev launch

# Or visit directly
https://drupal-ai-dev-tracker.ddev.site
```

**Login:**
- Username: `admin`
- Password: `admin` (or whatever you set in step 2)

### 6. Verify Everything Works

Visit these pages to confirm the setup:

1. **Calendar**: `https://drupal-ai-dev-tracker.ddev.site/ai-dashboard/calendar`
   - Should show companies and contributors
   - Should show assigned issues for the current week

2. **Priority Kanban**: `/ai-dashboard/priority-kanban`
   - Should show issues organized by status

3. **Projects**: `/ai-dashboard/projects`
   - Should show AI projects

4. **Roadmap**: `/ai-dashboard/roadmap`
   - Should show deliverables by status

## Development Workflow

### Daily Development

```bash
# Start DDEV
ddev start

# Pull latest code
git pull origin main

# Import any config changes
ddev drush cim -y

# Clear cache
ddev drush cr

# Start coding!
```

### Updating Configuration

After making configuration changes in the UI:

```bash
# Export configuration to files
ddev drush cex -y

# Review changes
git status
git diff config/sync/

# Commit changes
git add config/sync/
git commit -m "Description of config changes"
git push
```

### Syncing Content from Live Site

The AI Dashboard includes `aid-cexport` and `aid-cimport` commands to sync content between live and local environments. This is useful when you want a complete copy of the live site on your local environment.

**What Gets Synced:**
- Drupal configuration (content types, views, fields)
- Tag mappings (drupal.org tag → track/workstream mappings)
- AI Projects (project nodes with deliverables)
- Assignment History (historical issue assignment data)
- Project-Issue relationships (which issues belong to which projects, with ordering and hierarchy)
- Roadmap ordering (manual drag-drop ordering on roadmap page)

**Note:** AI Issues are NOT exported (they're re-imported fresh from drupal.org). Contributors and Companies continue to use the CSV import workflow.

#### Syncing Workflow

```bash
# On live site (or ask admin to run)
ddev drush aid-cexport
# Exports everything to public files at:
# https://www.drupalstarforge.ai/sites/default/files/ai-exports/

# On local site
git pull  # Get any config changes first
ddev drush aid-cimport
# Automatically downloads from live site and imports everything
```

**Import Options:**

```bash
# Import from local files only (skip download)
ddev drush aid-cimport --source=local

# Replace existing content instead of skipping
ddev drush aid-cimport --replace

# Import from a different live site
ddev drush aid-cimport --live-url=https://staging-site.com
```

**How It Works:**
- Export creates 5 JSON files with **portable identifiers** (issue numbers, usernames, project titles - NOT database IDs)
- Import automatically resolves these to local database IDs
- This ensures content syncs correctly even though database IDs differ between environments

**Example:** An assignment record on live might reference issue #3492439 as database ID 123, but on local that same issue is database ID 789. The import command automatically resolves the issue number to the correct local ID.

### Running Issue Imports

```bash
# Import issues from all active configurations
ddev drush ai-dashboard:import-all

# Import from specific configuration
ddev drush ai-dashboard:import <config-name>

# Sync drupal.org assignments for current week
ddev drush ai-dashboard:sync-assignments

# Reprocess AI Tracker metadata for all issues
ddev drush ai-dashboard:process-metadata

# Update tag mappings for all issues
ddev drush ai-dashboard:update-tag-mappings
```

### Running Tests

```bash
# Run all AI Dashboard tests
ddev exec 'SIMPLETEST_BASE_URL=https://drupal-ai-dev-tracker.ddev.site SIMPLETEST_DB=mysql://db:db@db/db ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/ai_dashboard/tests/ --testdox'

# Run specific test types
ddev exec './vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/ai_dashboard/tests/src/Unit/ --testdox'
ddev exec 'SIMPLETEST_BASE_URL=https://drupal-ai-dev-tracker.ddev.site SIMPLETEST_DB=mysql://db:db@db/db ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/ai_dashboard/tests/src/Functional/ --testdox'
```

## Troubleshooting

### Calendar shows "Calendar Error"

**Problem:** Missing track/workstream fields on ai_issue content type.

**Solution:**
```bash
ddev drush cim -y
ddev drush cr
```

### No contributors or companies showing

**Problem:** CSV not imported yet.

**Solution:** Follow step 3 above to import contributor data.

### Import command is slow

**Problem:** Drupal.org API rate limiting.

**Solution:** The import includes rate limiting and is designed to be safe to stop/restart. Let it run or import specific configurations one at a time.

### UUID mismatch on config import

**Problem:** Trying to import live configs into existing site with different UUID.

**Solution:** Use `drush site:install --existing-config` for fresh rebuilds instead of manual config import.

## Production Deployment

This site is deployed to Drupal Forge via DevPanel. The deployment workflow is:

1. Develop locally with DDEV
2. Commit changes to git
3. Push to GitHub
4. DevPanel automatically deploys to staging/production
5. Run `drush updb -y` on production for database updates
6. Run `drush cim -y` on production for configuration updates

**Note:** DevPanel uses its own build scripts, not DDEV.

## Documentation

- **[CLAUDE.md](CLAUDE.md)**: Developer documentation for working with Claude Code
- **[CLAUDE-BRANCH.md](CLAUDE-BRANCH.md)**: Branch-specific documentation (if present)
- **[AI Dashboard Documentation](https://drupalstarforge.ai/ai-dashboard/admin/documentation)**: Complete feature documentation (requires login)

## Support

- **Issue Queue**: Report bugs and feature requests in the project issue queue
- **Drupal.org**: https://www.drupal.org/project/ai_dashboard (if published)
- **Local Team**: Contact the AI Dashboard development team

## License

Drupal and all derivative works are licensed under the [GNU General Public License, version 2 or later](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html).
