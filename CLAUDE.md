# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **Drupal CMS** - a ready-to-use platform built on Drupal 11 core with smart defaults and enterprise-grade tools for marketers, designers, and content creators. The project uses a recipe-based architecture for modular content types and features.

## Development Environment

This project uses **DDEV** for local development:

- **Project Type**: Drupal 11
- **PHP Version**: 8.3
- **Database**: MariaDB 10.11
- **Webserver**: nginx-fpm
- **Document Root**: `web/`

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