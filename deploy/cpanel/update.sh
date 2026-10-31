#!/usr/bin/env bash
# GitHub'daki main dalından güncelleme (cPanel Terminal):
#   bash deploy/cpanel/update.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

PHP_BIN="${PHP_BIN:-/opt/cpanel/ea-php84/root/usr/bin/php}"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer)}"
BRANCH="${BRANCH:-main}"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "HATA: Sunucuda elle değiştirilmiş dosyalar var (git status). Güncelleme durduruldu."; exit 1
fi

"$PHP_BIN" artisan down --retry=30 || true
trap '"$PHP_BIN" artisan up' EXIT

git fetch origin "$BRANCH"
git merge --ff-only "origin/$BRANCH"
"$PHP_BIN" -d memory_limit=-1 "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan queue:restart

echo "Güncellendi: $(git log -1 --format='%h %s')"
"$PHP_BIN" artisan kuafor:check || true
