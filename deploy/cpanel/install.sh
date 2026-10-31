#!/usr/bin/env bash
# İlk kurulum (cPanel Terminal). .env dosyasını DOLDURDUKTAN sonra çalıştırın:
#   bash deploy/cpanel/install.sh
# PHP yolu farklıysa:  PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php bash deploy/cpanel/install.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

PHP_BIN="${PHP_BIN:-/opt/cpanel/ea-php84/root/usr/bin/php}"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || true)}"

echo "==> PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;') ($PHP_BIN)"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || { echo "HATA: PHP 8.2+ gerekli (önerilen 8.4)."; exit 1; }
[ -f .env ] || { echo "HATA: .env yok. Önce: cp .env.demo.example .env ve alanları doldurun."; exit 1; }
[ -f public/build/manifest.json ] || { echo "HATA: public/build eksik (GitHub'dan tam çekildiğinden emin olun)."; exit 1; }

echo "==> Composer paketleri (geliştirici paketleri hariç)"
if [ -n "$COMPOSER_BIN" ]; then
  "$PHP_BIN" -d memory_limit=-1 "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
else
  echo "HATA: composer bulunamadı. cPanel Terminal'de 'composer --version' deneyin veya COMPOSER_BIN=/yol/composer verin."; exit 1
fi

grep -q '^APP_KEY=base64:' .env || "$PHP_BIN" artisan key:generate --force

echo "==> Dosya izinleri"
# Klasörler 775, dosyalar 664: dosyalara çalıştırma izni verilmez, böylece 'git status' temiz kalır
# ve update.sh güncellemeleri engellemez.
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +
git config core.fileMode false 2>/dev/null || true

echo "==> Veritabanı tabloları + başlangıç verisi (+ APP_ENV production değilse demo verisi)"
"$PHP_BIN" artisan migrate --force --seed

echo "==> Görsel klasörü bağlantısı"
"$PHP_BIN" artisan storage:link || echo "UYARI: storage:link başarısız (hosting symlink'e izin vermiyor olabilir)."

echo "==> Önbellekler"
"$PHP_BIN" artisan optimize

echo "==> Kontrol"
"$PHP_BIN" artisan kuafor:check --demo || true
echo
echo "Sıradaki adım: yönetici hesabı ->  $PHP_BIN artisan platform:create-admin eposta@adresiniz.com"
echo "Cron görevlerini docs/cpanel-kurulum.md içindeki gibi ekleyin."
