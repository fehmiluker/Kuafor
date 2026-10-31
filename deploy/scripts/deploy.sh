#!/usr/bin/env bash
# Zero-downtime release (symlink switch). Rollback: point `current` back to the previous release
# and run `php artisan optimize` (migrations are written to be additive; see docs/deployment.md).
set -euo pipefail
APP_ROOT=/var/www/kuafor
REL="$APP_ROOT/releases/$(date -u +%Y%m%d%H%M%S)"
git clone --depth 1 "${REPO_URL:?}" "$REL"
cd "$REL"
ln -s "$APP_ROOT/shared/.env" .env
rm -rf storage && ln -s "$APP_ROOT/shared/storage" storage
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci && npm run build && rm -rf node_modules
php artisan migrate --force
php artisan optimize
php artisan storage:link || true
ln -sfn "$REL" "$APP_ROOT/current"
sudo systemctl reload php8.4-fpm
php artisan queue:restart
ls -1dt "$APP_ROOT"/releases/* | tail -n +6 | xargs -r rm -rf   # keep 5 releases
echo "deployed $REL"
