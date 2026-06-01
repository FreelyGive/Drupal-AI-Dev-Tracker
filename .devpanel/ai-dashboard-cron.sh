#!/usr/bin/env bash
# ---------------------------------------------------------------------
# AI Dashboard Cron Script
# Runs every 30 minutes to import issues and sync assignments
# ---------------------------------------------------------------------

# Exit on error
set -e

# Set up environment
cd "${APP_ROOT:-/var/www/html}"

# Single log file, kept to last 1000 lines max
LOG_FILE=".logs/ai-dashboard-cron.log"
mkdir -p .logs

# Truncate log if over 1000 lines
if [ -f "$LOG_FILE" ] && [ "$(wc -l < "$LOG_FILE")" -gt 1000 ]; then
  tail -500 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting AI Dashboard cron job" >> "$LOG_FILE"

# Run import-all first (imports issues with metadata processing)
echo "$(date '+%Y-%m-%d %H:%M:%S') - Running drush ai-dashboard:import-all" >> "$LOG_FILE"
drush ai-dashboard:import-all >> "$LOG_FILE" 2>&1

# Run sync-assignments after import completes
echo "$(date '+%Y-%m-%d %H:%M:%S') - Running drush ai-dashboard:sync-assignments" >> "$LOG_FILE"
drush ai-dashboard:sync-assignments >> "$LOG_FILE" 2>&1

echo "$(date '+%Y-%m-%d %H:%M:%S') - AI Dashboard cron job completed" >> "$LOG_FILE"
