#!/usr/bin/env bash
#
# Update a running install to the latest code. Run on the server, from the
# app folder, as the deploy user (not root):
#
#   ./scripts/deploy.sh
#
# Takes a backup first, shows the maintenance page while migrating, and
# brings the app back up even if a step fails after it went down.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "→ Backup before deploy"
php artisan backup:run --only-db

echo "→ Maintenance mode"
php artisan down --retry=30 --refresh=15
trap 'php artisan up' EXIT

echo "→ Code"
git pull --ff-only

echo "→ Dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "→ Database"
php artisan migrate --force

echo "→ Caches"
php artisan optimize:clear
php artisan optimize
php artisan filament:optimize

echo "→ Reload PHP-FPM (clears OPcache)"
sudo systemctl reload php8.5-fpm

echo "✓ Deployed $(git rev-parse --short HEAD)"
