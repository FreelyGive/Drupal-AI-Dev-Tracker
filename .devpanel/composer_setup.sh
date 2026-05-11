#!/usr/bin/env bash
set -eu -o pipefail
cd $APP_ROOT

# Create required composer.json and composer.lock files
#composer create-project -n --no-plugins --no-install drupal/recommended-project
#cp -r recommended-project/* ./
#rm -rf recommended-project patches.lock.json

# Add Drush and Composer Patches.
composer require -n --no-plugins --no-update drush/drush cweagans/composer-patches:^2@beta

# Programmatically fix Composer 2.2 allow-plugins to avoid errors
composer config --no-plugins allow-plugins.cweagans/composer-patches true
