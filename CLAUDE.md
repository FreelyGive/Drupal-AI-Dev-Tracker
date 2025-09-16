# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **Drupal CMS** - a ready-to-use platform built on Drupal 11 core with smart defaults and enterprise-grade tools for marketers, designers, and content creators. The project uses a recipe-based architecture for modular content types and features.

## Development Environment

This project uses **DDEV** for local development with deployment to **Drupal Forge** via **DevPanel**:

- **Local Development**: DDEV environment
  - **Project Type**: Drupal 11
  - **PHP Version**: 8.3
  - **Database**: MariaDB 10.11
  - **Webserver**: nginx-fpm
  - **Document Root**: `web/`

- **Deployment Workflow**:
  1. Develop locally with DDEV
  2. Push changes to GitHub
  3. Deploy to Drupal Forge via DevPanel (has its own build scripts)

- **Database Changes Policy**:
  - **Long-term changes**: MUST use database update hooks (`drush updb`) so they can be deployed to production
  - **Debugging scripts**: Can be used for temporary database changes during development
  - **Production updates**: Always use `drush updb` on live site after deployment

- **Production Deployment**:
  - **Dependencies**: Use `composer install --no-dev --optimize-autoloader` to exclude testing dependencies
  - **Testing Dependencies**: All testing packages (phpunit, behat, etc.) are in `require-dev` section
  - **Code Quality Tools**: Development tools like `drupal/coder` are dev-only dependencies
  - **Live Site Sync**: Only production dependencies are installed on live/staging environments

- **Git Commit Policy**:
  - Use developer's name and email for commits
  - Include mention of Claude/AI assistance in commit descriptions when applicable
  - **NEVER stage changes (`git add`) until user has tested and explicitly asked for commit**
  - Always let user verify functionality before staging files

## Common Commands

### DDEV Environment
```bash
# Start the environment
ddev start

# Stop the environment
ddev stop

# SSH into web container
ddev ssh

# Access database
ddev mysql

# View logs
ddev logs
```

### Composer & Dependencies
```bash
# Install dependencies (development environment)
ddev composer install

# Install dependencies (production - no dev dependencies)
ddev composer install --no-dev --optimize-autoloader

# Update dependencies
ddev composer update

# Add new package
ddev composer require drupal/module_name

# Add development-only package (testing, code quality tools, etc.)
ddev composer require --dev drupal/module_name
```

### Drupal & Drush
```bash
# Check site status
drush status

# Clear cache
drush cr

# Update database
drush updb

# Import configuration
drush cim

# Export configuration
drush cex

# Install module
drush en module_name

# Run cron
drush cron
```


### Testing
```bash
# Run PHPUnit tests from web container
./vendor/bin/phpunit -c web/core/phpunit.xml.dist

# Run specific test group
./vendor/bin/phpunit -c web/core/phpunit.xml.dist --group recipe
```

## Architecture Overview

### Recipe-Based System
Drupal CMS uses a **recipe system** for modular functionality:

- **Base Recipe**: `drupal_cms_starter` - foundational setup with admin UI, authentication, and basic features
- **Content Type Recipes**: Each content type (blog, news, events, etc.) is a separate recipe
- **Feature Recipes**: Additional functionality like SEO tools, search, forms, etc.

Key recipe components:
- `recipe.yml` - defines dependencies, modules to install, and configuration
- `config/` - configuration files to be imported
- `content/` - default content (nodes, taxonomy terms, etc.)

### Directory Structure
- `recipes/` - Custom Drupal CMS recipes
- `web/` - Drupal document root
- `web/core/` - Drupal core
- `web/modules/contrib/` - Contributed modules
- `web/themes/contrib/` - Contributed themes
- `web/sites/default/` - Site-specific configuration

### Key Recipes
- `drupal_cms_starter` - Base CMS setup
- `drupal_cms_blog` - Blog content type and listing
- `drupal_cms_page` - Basic page content type
- `drupal_cms_events` - Event content type with dates/locations
- `drupal_cms_news` - News content type
- `drupal_cms_seo_tools` - SEO optimization features
- `drupal_cms_search` - Search functionality

### Content Architecture
- All content types extend from `drupal_cms_content_type_base`
- Common fields: `field_content`, `field_description`, `field_featured_image`, `field_tags`
- Uses Layout Builder for flexible page layouts
- Editorial workflow with content moderation

## Configuration Management
- Configuration is managed via recipes and exported to `config/` directories
- Use `drush cex/cim` for configuration import/export
- Recipe configuration uses YAML actions for programmatic updates

## AI Dashboard Module

This site includes a custom **AI Dashboard** module (`web/modules/custom/ai_dashboard/`) for tracking AI module contributions and development progress.

### Key Features

#### Content Types
- **AI Company**: Organizations contributing to AI modules
  - `field_company_ai_maker` - Boolean indicating AI Sponsor status
  - `field_company_drupal_profile` - Drupal.org profile name for linking
  - `field_company_color`, `field_company_logo`, `field_company_size`, `field_company_website`

- **AI Contributor**: Individual developers
  - `field_drupal_username` - Drupal.org username (unique identifier)
  - `field_contributor_company` - Reference to AI Company
  - `field_tracker_role` - Multi-value list (Developer, Front-end, Management, etc.)
  - `field_gitlab_username` - GitLab username/email
  - `field_contributor_skills`, `field_weekly_commitment`

- **AI Issue**: Drupal.org issues for AI modules
  - `field_issue_number` - Issue number (unique identifier)
  - `field_issue_url`, `field_issue_status`, `field_issue_priority`
  - `field_issue_blocked_by` - Multi-value field tracking blocking dependencies (format: #123456)
  - `field_issue_summary` - Full issue body/summary from drupal.org for metadata parsing
  - **AI Tracker Metadata Fields** (automatically parsed from issue summaries):
    - `field_update_summary` - Status update for stakeholders
    - `field_checkin_date` - When team leads should follow up for progress updates
    - `field_due_date` - When the issue should be fully completed
    - `field_additional_collaborators` - Additional team members working on this issue
  - Automatic import/update from drupal.org API with dependency parsing and AI Tracker metadata extraction

- **AI Resource Allocation**: Weekly time commitments per contributor

#### Calendar Dashboard
- **URL**: `/ai-dashboard/calendar`
- **Sorting**: AI Sponsors first, then alphabetical by company, then by developer name
- **Features**:
  - Weekly view with issue assignments
  - Drag-and-drop issue assignment
  - Backlog drawer with filtering
  - AI Sponsor badges (blue "SPONSOR" for sponsors, strikethrough for non-sponsors)
  - Company links to drupal.org profiles
  - Edit buttons for admins (⚙️ cog icon)
  - Sync buttons to pull drupal.org assignments
  - Developer focus and priorities display with PopperJS tooltips
  - Issue dependency tracking with "Blocked" labels and hover tooltips showing blocking issues

#### CSV Import System
- **URL**: `/ai-dashboard/admin/contributor-import`
- **Column Structure** (must match exact order):
  1. Name - Contributor's full name
  2. Username (d.o) - Drupal.org username (unique identifier)
  3. Organization - Company name
  4. AI Sponsor? - Yes/No for AI Sponsor status
  5. Tracker Role - Comma-separated roles (Developer, Front-end, Management, etc.)
  6. Skills - Comma-separated skills
  7. Commitment (days/week) - Weekly time commitment
  8. Company Drupal Profile - Company unique identifier for drupal.org URLs
  9. Current Focus - Brief description of current focus and priorities
  10. GitLab Username or Email - GitLab contact info

- **Re-import Capability**: Updates existing contributors/companies based on unique identifiers
- **Company Linking**: Uses `Company Drupal Profile` as primary unique identifier
- **Multi-role Support**: Handles comma-separated tracker roles with intelligent mapping
- **UTF-8 Support**: Automatically detects and converts file encoding to handle accented characters (e.g., Gábor Hojtsy)
- **Mac Excel Compatibility**: Handles Mac Roman encoding from Mac Excel CSV exports automatically

#### CSV Export Best Practices
For best results when exporting CSV from Google Sheets:

1. **Google Sheets** (Recommended workflow):
   - File → Download → Comma Separated Values (.csv) 
   - Upload directly to the importer **without opening in Excel**
   - This preserves UTF-8 encoding and handles accented characters perfectly

2. **Important**: **Do not open CSV files in Mac Excel** after downloading from Google Sheets
   - Opening in Mac Excel corrupts the UTF-8 encoding
   - This causes accented characters (á, ý, etc.) to display incorrectly
   - The importer includes automatic encoding fixes for common issues

#### Issue Import System
- **Deduplication**: Uses issue numbers as unique identifiers to prevent duplicates
- **Multi-status Import**: Handles multiple status filters without creating duplicates
- **Auto-update**: Updates existing issues when they change on drupal.org (with API delay)
- **Session Caching**: Prevents duplicates during single import session
- **AI Tracker Metadata Processing**: Automatically extracts structured metadata from issue summaries during import

#### AI Tracker Metadata Format
Issues must include structured metadata in their summaries using this exact format with HTML tags:
```
--- AI TRACKER METADATA ---
<strong>Update Summary: </strong>[One-line status update for stakeholders]
<strong>Check-in Date: </strong>MM/DD/YYYY (US format) [When we should see progress/get an update]
<strong>Due Date:</strong> MM/DD/YYYY (US format) [When the issue should be fully completed]
<strong>Blocked by:</strong> [#XXXXXX] (New issues on new lines)
<strong>Additional Collaborators:</strong> @username1, @username2
AI Tracker found here: <a href="https://www.drupalstarforge.ai/" title="AI Tracker">https://www.drupalstarforge.ai/</a>
--- END METADATA ---
```

- **Automatic Processing**: Metadata is extracted during issue import and stored in dedicated fields
- **Date Formats**: Supports MM/DD/YYYY format, converts to standard Y-m-d format
- **Issue References**: Blocked by field extracts issue numbers from #1234567 format
- **Collaborators**: Extracts usernames from @username format
- **Re-processing**: Use `drush aid-meta` to re-process metadata for all existing issues

### Project Kanban Features

#### Priority Kanban Board
- **URL**: `/ai-dashboard/priority-kanban`
- **Project Filtering**: Filter issues by AI Projects, with support for default project selection
- **Tag Filtering**: Filter issues by tags with "All Tags" option
- **Issue Organization**: Issues organized into columns (Todos, Needs Review, Working On, Blocked, RTBC, Fixed)
- **Project Issue Ordering**: Custom ordering of issues within projects stored in `ai_dashboard_project_issue` table
- **Default Project**: One project can be marked as default for initial kanban view

#### AI Project Management
- **Projects List**: `/ai-dashboard/projects` - View and manage AI projects
- **Project Issues**: `/ai-dashboard/project/{slug}/issues` - View issues for specific project with drag-and-drop ordering
- **Default Project Setting**: Checkbox on project edit form to set as default kanban project
- **Project Tags**: Define tags for each project to filter related issues
- **Open in Kanban**: Button on project issues page to view in kanban with project filter

### Database Update Hooks
- `ai_dashboard_update_9037()` - **Production update**: Adds project kanban features including:
  - Project issue ordering table (`ai_dashboard_project_issue`)
  - Default kanban project field (`field_is_default_kanban_project`)
  - Form and view display configuration for AI Projects

**Note**: Earlier update hooks (8001-9036) were development iterations and are not needed for production deployment.

### Permissions
- **View**: Public access to dashboard views
- **Admin**: `administer ai dashboard content` permission for imports, edits, and configuration

### Technical Documentation
- **Comprehensive Documentation**: Located at `/ai-dashboard/admin/documentation`
- **Project Management Integration Plan**: See documentation section "Processes for handling integration of metadata with drupal.org issues"
- **Implementation Roadmap**: Multi-phase plan for Track/Workstream/Epic system with metadata extraction from issue bodies

## Field Customizations

### Tag Fields
Tag fields across all content types have been customized for clean UX:

- **Field Descriptions**: Removed from recipe configurations to eliminate helper text clutter
  - Files: `recipes/*/config/field.field.node.*.field_tags.yml`
  - Changed: `description: 'Include tags for relevant topics.'` → `description: ''`
  - Also updated: `recipes/drupal_cms_content_type_base/config/taxonomy.vocabulary.tags.yml`

- **Placeholder Text**: Removed from form display configurations to keep input fields clean
  - Files: `config/sync/core.entity_form_display.node.*.default.yml`
  - Changed: Removed `placeholder: ''` setting from all tagify widget configurations
  - Special case: `ai_issue` entity used `string_textfield` widget with placeholder `'e.g. AI Logging, June, Critical (comma separated)'` - also removed

- **Theme Setup**: Site uses **Gin theme** for both frontend and admin (not Olivero)
  - Frontend theme: `gin` 
  - Admin theme: `gin`

## Testing

### AI Dashboard Module Testing
The AI Dashboard module has **complete test coverage** with all 25 tests passing (100% success rate):

- **Unit Tests (7/7)**: API integration, service logic, component filtering
- **Kernel Tests (7/7)**: Entity operations, configuration management, data persistence  
- **Functional Tests (11/11)**: Form validation, user workflows, browser interactions

#### Test Commands
```bash
# Run AI Dashboard tests (from web container or with ddev exec)
SIMPLETEST_BASE_URL=https://drupalcmsaitest1.ddev.site SIMPLETEST_DB=mysql://db:db@db/db ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/ai_dashboard/tests/ --testdox

# Run specific test types
./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/ai_dashboard/tests/src/Unit/ --testdox
SIMPLETEST_BASE_URL=https://drupalcmsaitest1.ddev.site SIMPLETEST_DB=mysql://db:db@db/db ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/ai_dashboard/tests/src/Kernel/ --testdox
SIMPLETEST_BASE_URL=https://drupalcmsaitest1.ddev.site SIMPLETEST_DB=mysql://db:db@db/db ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/ai_dashboard/tests/src/Functional/ --testdox
```

#### Test Infrastructure
- **PHPUnit 11.5+** with Drupal 11 testing framework
- **BrowserTestBase** for functional tests with dependency pre-creation system
- **KernelTestBase** for entity and service testing
- **UnitTestCase** with proper HTTP client mocking
- **Circular dependency resolution** for complex module installation testing

### General Testing
- PHPUnit configuration in `web/core/phpunit.xml.dist`
- Recipe-specific tests in `recipes/*/tests/`
- Use environment variable `SIMPLETEST_BASE_URL` for functional tests
- Test database configured via `SIMPLETEST_DB`

## Reference - AI Dashboard Commands

**IMPORTANT: Do not create new drush commands without explicit permission. Use existing commands where possible.**

### Current AI Dashboard Drush Commands

```bash
# Import all active issue configurations (with automatic metadata + tag processing)
drush ai-dashboard:import-all

# Import single configuration (with automatic metadata + tag processing) 
drush ai-dashboard:import all_open_active_issues
drush ai-dashboard:import openai_provider
drush ai-dashboard:import ai_agents

# Import with date filter (issues changed since specific date)
drush ai-dashboard:import all_open_active_issues --full-from=2025-01-01

# Manual reprocessing of metadata for ALL existing issues (debugging)
drush ai-dashboard:process-metadata

# Manual reapplication of tag mappings to ALL existing issues  
drush ai-dashboard:update-tag-mappings

# Sync drupal.org assignments for current week
drush ai-dashboard:sync-assignments
```

### Command Integration Notes

- **Metadata processing** - Automatic during every import via MetadataParserService
- **Tag mapping** - Automatic during every import via TagMappingService
- **Manual commands** - Use `process-metadata` and `update-tag-mappings` for bulk updates to existing issues
- **Import commands** - Handle metadata and tag processing automatically for new/updated issues
