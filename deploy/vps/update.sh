#!/usr/bin/env bash
# VPS kurulumunu GitHub'daki daldan günceller (root olarak):  bash /var/www/kuafor/deploy/vps/update.sh
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/kuafor}"
BRANCH="${BRANCH:-$(git -C "$APP_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)}"  # stay on the installed branch
as_web() { sudo -u www-data -H env COMPOSER_HOME=/var/www/.composer "$@"; }

[ "$(id -u)" -eq 0 ] || { echo "root olarak çalıştırın (sudo -i)."; exit 1; }
cd "$APP_DIR"
if [ -n "$(as_web git status --porcelain --untracked-files=no)" ]; then
  echo "HATA: Sunucuda elle değiştirilmiş dosyalar var (git status). Güncelleme durduruldu."; exit 1
fi

as_web php artisan down --retry=30 || true
trap 'as_web php artisan up' EXIT

as_web git fetch --quiet origin "$BRANCH"
as_web git checkout --quiet "$BRANCH"
as_web git merge --ff-only --quiet "origin/$BRANCH"
as_web composer install --no-dev --optimize-autoloader --no-interaction --quiet
as_web php artisan migrate --force
as_web php artisan optimize:clear >/dev/null
as_web php artisan optimize >/dev/null
as_web php artisan queue:restart
supervisorctl restart 'kuafor-worker:*' >/dev/null 2>&1 || true

echo "Güncellendi: $(as_web git log -1 --format='%h %s')"
as_web php artisan kuafor:check || true
