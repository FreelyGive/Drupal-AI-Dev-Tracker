#!/usr/bin/env bash
# ---------------------------------------------------------------------
# Copyright (C) 2024 DevPanel
# You can install any service here to support your project
# Please make sure you run apt update before install any packages
# Example:
# - sudo apt-get update
# - sudo apt-get install nano
#
# ----------------------------------------------------------------------
if [ -n "$DEBUG_SCRIPT" ]; then
    set -x
fi

# Debug logging to track which scripts run on container start
mkdir -p /var/www/html/.logs
echo "$(date '+%Y-%m-%d %H:%M:%S') - [custom_package_installer.sh] Script started" >> /var/www/html/.logs/devpanel-debug.log

# Install APT packages.
if ! command -v npm >/dev/null 2>&1; then
  sudo apt-get update
  sudo apt-get install -y cron jq nano npm
fi

# Install cron if not already installed.
if ! command -v cron >/dev/null 2>&1; then
  sudo apt-get update
  sudo apt-get install -y cron
fi

# Enable AVIF support in GD extension if not already enabled.
if [ -z "$(php --ri gd | grep AVIF)" ]; then
  sudo apt-get install -y libavif-dev
  sudo docker-php-ext-configure gd --with-avif --with-freetype --with-jpeg --with-webp
  sudo docker-php-ext-install gd
fi

PECL_UPDATED=false
# Install APCU extension. Bypass question about enabling internal debugging.
if ! php --ri apcu > /dev/null 2>&1; then
  $PECL_UPDATED || sudo pecl update-channels && PECL_UPDATED=true
  sudo pecl install apcu <<< ''
  echo 'extension=apcu.so' | sudo tee /usr/local/etc/php/conf.d/apcu.ini
fi
# Install uploadprogress extension.
if ! php --ri uploadprogress > /dev/null 2>&1; then
  $PECL_UPDATED || sudo pecl update-channels && PECL_UPDATED=true
  sudo pecl install uploadprogress
  echo 'extension=uploadprogress.so' | sudo tee /usr/local/etc/php/conf.d/uploadprogress.ini
fi
# Reload Apache if it's running.
if $PECL_UPDATED && sudo /etc/init.d/apache2 status > /dev/null; then
  sudo /etc/init.d/apache2 reload
fi

#== Set up AI Dashboard cron job (every 30 minutes).
echo 'Set up AI Dashboard cron job.'
chmod +x $APP_ROOT/.devpanel/ai-dashboard-cron.sh
CRON_CMD="*/30 * * * * cd /var/www/html && APP_ROOT=/var/www/html PATH=/usr/local/bin:/usr/bin:/bin /var/www/html/.devpanel/ai-dashboard-cron.sh"
(crontab -u www -l 2>/dev/null | grep -v 'ai-dashboard-cron.sh'; echo "$CRON_CMD") | crontab -u www -
echo 'AI Dashboard cron job configured.'
