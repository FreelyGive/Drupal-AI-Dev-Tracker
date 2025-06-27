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

# Scaffold settings.php.
composer config --no-plugins -jm extra.drupal-scaffold.file-mapping '{
    "[web-root]/sites/default/settings.php": {
        "path": "web/core/assets/scaffold/files/default.settings.php",
        "overwrite": false
    }
}'
composer config --no-plugins scripts.post-drupal-scaffold-cmd \
    'cd web/sites/default && (test -n "$(grep '\''include \$devpanel_settings;'\'' settings.php)" || patch -Np1 -r /dev/null < $APP_ROOT/.devpanel/drupal-settings.patch || :)'
