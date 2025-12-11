<?php

// phpcs:ignoreFile

/**
 * @file
 * Drupal site-specific configuration file.
 */

/**
 * Load services definition file.
 */
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

/**
 * The default list of directories that will be ignored by Drupal's file API.
 *
 * By default ignore node_modules and bower_components folders to avoid issues
 * with common frontend tools and recursive scanning of directories looking for
 * extensions.
 *
 * @see \Drupal\Core\File\FileSystemInterface::scanDirectory()
 * @see \Drupal\Core\Extension\ExtensionDiscovery::scanDirectory()
 */
$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

/**
 * Location of the site configuration files.
 */
$settings['config_sync_directory'] = '../config/sync';

/**
 * Include DDEV settings.
 */
if (getenv('IS_DDEV_PROJECT') == 'true' && file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}

/**
 * Load DevPanel override configuration, if available.
 */
if (getenv('DP_APP_ID') !== FALSE && file_exists(dirname($app_root) . '/.devpanel/settings.devpanel.php')) {
  include dirname($app_root) . '/.devpanel/settings.devpanel.php';
}

/**
 * Load local development override configuration, if available.
 */
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
