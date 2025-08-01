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

- **Git Commit Policy**:
  - Use developer's name and email for commits
  - Include mention of Claude/AI assistance in commit descriptions when applicable

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
# Install dependencies
ddev composer install

# Update dependencies
ddev composer update

# Add new package
ddev composer require drupal/module_name
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
  - `field_company_ai_maker` - Boolean indicating AI Maker status
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
  - Automatic import/update from drupal.org API

- **AI Resource Allocation**: Weekly time commitments per contributor

#### Calendar Dashboard
- **URL**: `/ai-dashboard/calendar`
- **Sorting**: AI Makers first, then alphabetical by company, then by developer name
- **Features**:
  - Weekly view with issue assignments
  - Drag-and-drop issue assignment
  - Backlog drawer with filtering
  - AI Maker badges (blue "MAKER" for makers, strikethrough for non-makers)
  - Company links to drupal.org profiles
  - Edit buttons for admins (⚙️ cog icon)
  - Sync buttons to pull drupal.org assignments

#### CSV Import System
- **URL**: `/ai-dashboard/admin/contributor-import`
- **Column Structure** (must match exact order):
  1. Name - Contributor's full name
  2. Username (d.o) - Drupal.org username (unique identifier)
  3. Organization - Company name
  4. AI Maker? - Yes/No for AI Maker status
  5. Tracker Role - Comma-separated roles (Developer, Front-end, Management, etc.)
  6. Skills - Comma-separated skills
  7. Commitment (days/week) - Weekly time commitment
  8. Company Drupal Profile - Company unique identifier for drupal.org URLs
  9. GitLab Username or Email - GitLab contact info

- **Re-import Capability**: Updates existing contributors/companies based on unique identifiers
- **Company Linking**: Uses `Company Drupal Profile` as primary unique identifier
- **Multi-role Support**: Handles comma-separated tracker roles with intelligent mapping

#### Issue Import System
- **Deduplication**: Uses issue numbers as unique identifiers to prevent duplicates
- **Multi-status Import**: Handles multiple status filters without creating duplicates
- **Auto-update**: Updates existing issues when they change on drupal.org (with API delay)
- **Session Caching**: Prevents duplicates during single import session

### Database Update Hooks
- `ai_dashboard_update_8001()` - Remove unsupported status filters
- `ai_dashboard_update_8002()` - Add company drupal profile and AI maker fields
- `ai_dashboard_update_8003()` - Update contributors admin view with drupal.org links
- `ai_dashboard_update_8004()` - Fix missing field storage tables
- `ai_dashboard_update_8005()` - Force creation of database tables
- `ai_dashboard_update_8006()` - Add tracker role and GitLab username fields

### Permissions
- **View**: Public access to dashboard views
- **Admin**: `administer ai dashboard content` permission for imports, edits, and configuration

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
- PHPUnit configuration in `web/core/phpunit.xml.dist`
- Recipe-specific tests in `recipes/*/tests/`
- Use environment variable `SIMPLETEST_BASE_URL` for functional tests
- Test database configured via `SIMPLETEST_DB`