#!/usr/bin/env bash
# Kuaför Randevu — VPS'e (Hostinger vb.) tek komutla DEMO kurulumu.
# Temiz Ubuntu 22.04 / 24.04, root olarak:
#
#   curl -fsSL https://raw.githubusercontent.com/fehmiluker/Kuafor/main/deploy/vps/install.sh -o install.sh
#   DOMAIN=demo.alanadiniz.com EMAIL=siz@eposta.com DEMO_PASS='guclu-bir-sifre' bash install.sh
#
# Ayarlar (ortam değişkeni):
#   DOMAIN     Alan adı (A kaydı bu sunucunun IP'sine yönlenmiş olmalı). Boşsa IP ile, HTTPS'siz çalışır.
#   EMAIL      Let's Encrypt (HTTPS) için e-posta. DOMAIN ile birlikte verilirse sertifika alınır.
#   DEMO_PASS  Siteyi şifreyle korur (kullanıcı adı: DEMO_USER, varsayılan "demo"). Önerilir: demo şifreleri README'de açık.
#   BRANCH     GitHub dalı (varsayılan main).
#
# Gerçek WhatsApp/ödeme/SMS/e-posta KAPALIDIR (.env.demo.example). Tekrar çalıştırmak güvenlidir:
# mevcut kurulumda kod ve veritabanı korunur, yalnızca eksik adımlar tamamlanır.
set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/fehmiluker/Kuafor.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/var/www/kuafor}"
DOMAIN="${DOMAIN:-}"
EMAIL="${EMAIL:-}"
DEMO_USER="${DEMO_USER:-demo}"
DEMO_PASS="${DEMO_PASS:-}"
PHP_VER=8.4
DB_NAME=kuafor
DB_USER=kuafor
CREDS_FILE=/root/kuafor-kurulum-bilgileri.txt

say() { printf '\n\033[1;35m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mHATA: %s\033[0m\n' "$*" >&2; exit 1; }
svc() { systemctl "$1" "$2" 2>/dev/null || service "$2" "$1"; }
as_web() { sudo -u www-data -H env COMPOSER_HOME=/var/www/.composer "$@"; }

[ "$(id -u)" -eq 0 ] || die "root olarak çalıştırın (sudo -i)."
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || die "Bu betik Ubuntu 22.04/24.04 içindir (bulunan: ${PRETTY_NAME:-?})."
export DEBIAN_FRONTEND=noninteractive

# --------------------------------------------------------------------- paketler
say "Sistem paketleri"
apt-get update -qq
apt-get install -y -qq software-properties-common ca-certificates curl git unzip gnupg openssl \
  nginx mysql-server supervisor certbot python3-certbot-nginx apache2-utils ufw >/dev/null

has_pkg() { apt-cache show "$1" >/dev/null 2>&1; }
add_php_ppa() {
  add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 && return 0
  # Fallback without python-apt: add the PPA by hand (key: Ondřej Surý, 14AA40EC0831756756D7F66C4F4EA0AAE5267A6C).
  curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C" \
    | gpg --dearmor --yes -o /usr/share/keyrings/ondrej-php.gpg || return 1
  echo "deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${VERSION_CODENAME} main" \
    > /etc/apt/sources.list.d/ondrej-php.list
}
if ! has_pkg "php${PHP_VER}-fpm"; then
  say "PHP ${PHP_VER} deposu (ppa:ondrej/php)"
  if add_php_ppa && apt-get update -qq; then :; else
    echo "UYARI: PHP ${PHP_VER} deposuna ulaşılamadı; Ubuntu'nun kendi PHP sürümü denenecek."
    rm -f /etc/apt/sources.list.d/ondrej-php.list; apt-get update -qq
  fi
fi
if ! has_pkg "php${PHP_VER}-fpm"; then
  for v in 8.3 8.2; do if has_pkg "php${v}-fpm"; then PHP_VER=$v; break; fi; done
  has_pkg "php${PHP_VER}-fpm" || die "PHP 8.2+ kurulamadı (Ubuntu 24.04 önerilir)."
  echo "PHP ${PHP_VER} kullanılacak (uygulama 8.2+ ile çalışır)."
fi
apt-get install -y -qq "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring" \
  "php${PHP_VER}-intl" "php${PHP_VER}-gd" "php${PHP_VER}-xml" "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-bcmath" >/dev/null
update-alternatives --set php "/usr/bin/php${PHP_VER}" >/dev/null 2>&1 || true
cat > "/etc/php/${PHP_VER}/fpm/conf.d/99-kuafor.ini" <<'INI'
memory_limit = 256M
upload_max_filesize = 8M
post_max_size = 8M
max_execution_time = 60
expose_php = Off
INI

if ! command -v composer >/dev/null; then
  say "Composer"
  if EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)" && curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php; then
    [ "$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" = "$EXPECTED" ] || die "Composer kurulum dosyası doğrulanamadı."
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
  else
    echo "getcomposer.org'a ulaşılamadı; Ubuntu paketi kuruluyor."
    apt-get install -y -qq composer >/dev/null
  fi
fi

# --------------------------------------------------------------------- MySQL
say "MySQL veritabanı"
svc start mysql
MYSQL_VERSION="$(mysql -N -e 'SELECT VERSION()')"
case "$MYSQL_VERSION" in 8.*) ;; *) die "MySQL 8 gerekli (bulunan: $MYSQL_VERSION). MariaDB desteklenmez." ;; esac
if [ -f "$APP_DIR/.env" ] && grep -q '^DB_PASSWORD=.\+' "$APP_DIR/.env"; then
  DB_PASS="$(grep '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2-)"
else
  DB_PASS="$(openssl rand -hex 24)"
fi
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# --------------------------------------------------------------------- kod
say "Uygulama kodu ($REPO_URL, dal: $BRANCH)"
mkdir -p /var/www/.composer "$APP_DIR"
chown www-data:www-data /var/www/.composer
if [ ! -d "$APP_DIR/.git" ]; then
  [ -z "$(ls -A "$APP_DIR")" ] || die "$APP_DIR boş değil ve git deposu değil."
  chown www-data:www-data "$APP_DIR"
  as_web git clone --quiet --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
else
  echo "Mevcut kurulum bulundu; kod güncellemesi için: bash $APP_DIR/deploy/vps/update.sh"
fi
chown -R www-data:www-data "$APP_DIR"
cd "$APP_DIR"
[ -f public/build/manifest.json ] || die "public/build eksik (derlenmiş CSS/JS depoda olmalı)."

# --------------------------------------------------------------------- .env
if [ -n "$DOMAIN" ]; then
  if [ -n "$EMAIL" ]; then SCHEME=https; else SCHEME=http; fi
  HOST="$DOMAIN"
else
  SCHEME=http
  HOST="$(curl -fsS4 --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"
fi
APP_URL="${SCHEME}://${HOST}"

if [ ! -f .env ]; then
  say ".env (demo ayarları: gerçek WhatsApp/ödeme/SMS/e-posta kapalı)"
  cp .env.demo.example .env
  sed -i \
    -e "s#^APP_URL=.*#APP_URL=${APP_URL}#" \
    -e "s#^CORS_ALLOWED_ORIGINS=.*#CORS_ALLOWED_ORIGINS=${APP_URL}#" \
    -e "s#^DB_HOST=.*#DB_HOST=127.0.0.1#" \
    -e "s#^DB_DATABASE=.*#DB_DATABASE=${DB_NAME}#" \
    -e "s#^DB_USERNAME=.*#DB_USERNAME=${DB_USER}#" \
    -e "s#^DB_PASSWORD=.*#DB_PASSWORD=${DB_PASS}#" \
    .env
  # Without HTTPS the browser would drop a "secure" session cookie and login would fail.
  [ "$SCHEME" = https ] || sed -i 's#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=false#' .env
  chown www-data:www-data .env
fi
chmod 640 .env

say "Composer paketleri + veritabanı + demo veri"
as_web composer install --no-dev --optimize-autoloader --no-interaction --quiet
grep -q '^APP_KEY=base64:' .env || as_web php artisan key:generate --force
as_web php artisan migrate --force --seed
as_web php artisan storage:link >/dev/null 2>&1 || true
as_web php artisan optimize >/dev/null

# --------------------------------------------------------------------- nginx
say "Nginx"
AUTH=""
if [ -n "$DEMO_PASS" ]; then
  htpasswd -bc /etc/nginx/kuafor.htpasswd "$DEMO_USER" "$DEMO_PASS" >/dev/null 2>&1
  chown root:www-data /etc/nginx/kuafor.htpasswd && chmod 640 /etc/nginx/kuafor.htpasswd
  AUTH='auth_basic "Kuafor Randevu Demo"; auth_basic_user_file /etc/nginx/kuafor.htpasswd;'
fi
cat > /etc/nginx/sites-available/kuafor.conf <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${DOMAIN:-_};
    root ${APP_DIR}/public;   # document root = public/ only
    index index.php;
    client_max_body_size 8m;
    charset utf-8;
    server_tokens off;

    location ~ /\.(?!well-known) { deny all; }        # .env, .git ...

    location / {
        ${AUTH}
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    # Provider callbacks and health check must stay reachable without the demo password.
    location ^~ /webhooks/ { try_files \$uri /index.php?\$query_string; }
    location = /up { try_files \$uri /index.php?\$query_string; }

    location /build/ { expires 1y; add_header Cache-Control "public, immutable"; try_files \$uri =404; }
    location ^~ /storage/ { location ~ \.php\$ { deny all; } try_files \$uri =404; }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60s;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/kuafor.conf /etc/nginx/sites-enabled/kuafor.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t -q
svc restart "php${PHP_VER}-fpm"
svc reload nginx || svc restart nginx

# --------------------------------------------------------------------- kuyruk + zamanlayıcı
say "Kuyruk işçisi (supervisor) ve zamanlayıcı (cron)"
mkdir -p /var/log/kuafor && chown www-data:www-data /var/log/kuafor
cat > /etc/supervisor/conf.d/kuafor-worker.conf <<SUP
[program:kuafor-worker]
command=php ${APP_DIR}/artisan queue:work --queue=webhooks,notifications,default --sleep=1 --tries=3 --backoff=10 --max-time=3600 --memory=256
directory=${APP_DIR}
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/kuafor/worker.log
SUP
svc start supervisor
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart 'kuafor-worker:*' >/dev/null 2>&1 || true
echo "* * * * * www-data cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/kuafor
chmod 644 /etc/cron.d/kuafor

# --------------------------------------------------------------------- güvenlik duvarı + HTTPS
say "Güvenlik duvarı (SSH, HTTP, HTTPS)"
ufw allow OpenSSH >/dev/null && ufw allow 'Nginx Full' >/dev/null && ufw --force enable >/dev/null || echo "UYARI: ufw etkinleştirilemedi."

if [ -n "$DOMAIN" ] && [ -n "$EMAIL" ]; then
  say "HTTPS sertifikası (Let's Encrypt): $DOMAIN"
  if certbot --nginx -d "$DOMAIN" --redirect --agree-tos -m "$EMAIL" -n; then
    as_web php artisan optimize >/dev/null
  else
    echo "UYARI: Sertifika alınamadı. DNS A kaydı ${DOMAIN} -> bu sunucu olmalı. Sonra: certbot --nginx -d ${DOMAIN} --redirect"
    sed -i "s#^APP_URL=.*#APP_URL=http://${DOMAIN}#; s#^CORS_ALLOWED_ORIGINS=.*#CORS_ALLOWED_ORIGINS=http://${DOMAIN}#; s#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=false#" .env
    as_web php artisan optimize >/dev/null
    APP_URL="http://${DOMAIN}"
  fi
fi

# --------------------------------------------------------------------- özet
{
  echo "Kuaför Randevu demo kurulumu — $(date '+%Y-%m-%d %H:%M')"
  echo "Adres: ${APP_URL}"
  echo "Uygulama klasörü: ${APP_DIR}"
  echo "Veritabanı: ${DB_NAME} / kullanıcı ${DB_USER} / şifre ${DB_PASS}"
  if [ -n "$DEMO_PASS" ]; then echo "Site şifresi: ${DEMO_USER} / (kurulumda verdiğiniz DEMO_PASS)"; fi
} > "$CREDS_FILE"
chmod 600 "$CREDS_FILE"

say "Kontrol"
as_web php artisan kuafor:check --demo || true

cat <<DONE

==========================================================================
 Kurulum tamamlandı:  ${APP_URL}
$( if [ -n "$DEMO_PASS" ]; then echo " Site şifresi: kullanıcı '${DEMO_USER}', şifre: kurulumda verdiğiniz DEMO_PASS"; fi )
 Demo hesapları (şifre Demo12345!): sahip@isiltikuafor.test, musteri@example.test ...
 Yönetici hesabı:  cd ${APP_DIR} && sudo -u www-data php artisan platform:create-admin siz@eposta.com
 Güncelleme:       bash ${APP_DIR}/deploy/vps/update.sh
 Bilgiler (DB şifresi): ${CREDS_FILE}
==========================================================================
DONE
