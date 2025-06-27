# GEMINI.md

This file provides guidance to Gemini when working with code in this repository.

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
ddev drush status

# Clear cache
ddev drush cr

# Update database
ddev drush updb

# Import configuration
ddev drush cim

# Export configuration
ddev drush cex

# Install module
ddev drush en module_name

# Run cron
ddev drush cron
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

## DevPanel Deployment
The `.devpanel` directory contains scripts to deploy this application to DevPanel. The general workflow is:
1. `init.sh` is the main entrypoint for installation.
2. `init.sh` calls `composer_setup.sh` to create the `composer.json` and `composer.lock` files.
3. `init.sh` then installs the site.
4. `init-container.sh` imports a database dump if one exists.
5. `re-config.sh` is run when the container configuration is changed in DevPanel.
6. The `config.yml` file defines git push hooks for different branches.

