# Kurulum ve dağıtım

> cPanel üzerinde demo/test kurulumu için: **[cpanel-kurulum.md](cpanel-kurulum.md)**. Bu belge VPS/üretim kurulumunu anlatır.
> Derlenmiş CSS/JS (`public/build`) depoda bulunur; sunucuda Node.js gerekmez. Ön yüz kodu değişirse `npm run build` çalıştırıp `public/build`'i commit edin (CI kontrol eder).

## Gereksinimler
- PHP **8.4** (8.2+ çalışır; bu teslimat PHP 8.3.6 ile test edildi) + eklentiler: `pdo_mysql`, `mbstring`, `intl`, `gd`, `openssl`, `bcmath` (önerilir), `redis` (üretim), `pcntl` (eşzamanlılık testleri)
- Composer 2, Node 20/22 LTS + npm, MySQL **8.0.16+** (CHECK kısıtları için), Redis 6+ (üretim kuyruğu/önbellek), Nginx + PHP-FPM, TLS sertifikası
- `gpg` (yedek şifreleme)

## Yerel kurulum
```bash
composer install
cp .env.example .env && php artisan key:generate
# .env: DB_* ayarları (MySQL 8)
php artisan migrate --seed          # referans veri + demo veri (production'da demo çalışmaz)
npm ci && npm run build
php artisan storage:link
php artisan test                    # ayrı test veritabanı: DB_DATABASE=kuafor_test (phpunit.xml)
composer dev                        # serve + queue:listen + pail + vite
```
Demo kullanıcılar için `README.md`.

## Üretim
1. **Sunucu:** Nginx (`deploy/nginx/kuafor.conf`, document root yalnızca `public/`, dotfile erişimi kapalı), PHP-FPM 8.4, MySQL 8, Redis.
2. **.env:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `TRUSTED_PROXIES`, `CORS_ALLOWED_ORIGINS`, `META_*`, `WHATSAPP_PROVIDER_MODE=meta`, `SUBSCRIPTION_WEBHOOK_SECRET`, `MAIL_*`. Dosya `600`, sahibi uygulama kullanıcısı.
3. **İzinler:** kod `root:www-data 750`; `storage/` ve `bootstrap/cache/` `www-data` yazabilir.
4. **Dağıtım:** `deploy/scripts/deploy.sh` (releases + `current` symlink, `migrate --force`, `optimize`, `queue:restart`). Geri alma: `current` symlink'ini önceki sürüme çevirip `php artisan optimize && php artisan queue:restart`. Migration'lar ekleyici yazılmalı (sütun silme iki aşamalı: önce kodu bırak, sonraki sürümde sil).
5. **İlk yönetici:**
   ```bash
   php artisan platform:create-admin admin@sirket.com --name="Ad Soyad"
   ```
   Rastgele 24 karakterlik parola bir kez gösterilir; ilk girişte TOTP kurulumu zorunludur. Seed'de sabit üretim şifresi yoktur.
6. **Kuyruk:** Supervisor `deploy/supervisor/kuafor-worker.conf` (`webhooks,notifications,default`). Başarısız işler `failed_jobs`'ta (dead letter); `php artisan queue:retry all`.
7. **Zamanlayıcı:** **tek** sunucuda `deploy/systemd/kuafor-scheduler.service` (`schedule:work`) veya cron: `* * * * * www-data cd /var/www/kuafor/current && php artisan schedule:run >> /dev/null 2>&1`. İşler `onOneServer()` + paylaşılan önbellek kilidi kullanır.
   | Görev | Sıklık |
   |---|---|
   | `ExpireBookingHolds` (TTL süpürme, idempotent) | her dakika |
   | `SendAppointmentReminders` | 5 dk |
   | `SweepSubscriptions` (deneme/gecikme/ek süre/askı) | saatlik |
   | `PurgeExpiredData` (saklama politikası) | günlük 03:30 |
   | `queue:prune-failed`, `auth:clear-resets`, `sanctum:prune-expired` | günlük / 15 dk |
8. **WhatsApp:** `docs/whatsapp.md` canlıya alma kontrol listesi. Şablonları onaylatın, `php artisan db:seed --class=WhatsAppTemplateSeeder` sonrası `approval_status` güncelleyin.
9. **Log rotasyonu:** `LOG_STACK=daily` (14 gün) veya logrotate: `/var/www/kuafor/shared/storage/logs/*.log { daily rotate 14 compress missingok notifempty copytruncate }`.
10. **İzleme:** `GET /up` (Laravel health) için uptime kontrolü; admin "Sistem" sayfası kuyruk/webhook/mesaj durumunu gösterir; `failed_jobs` > 0 ve `whatsapp_webhook_events.status=failed` için alarm kurun.

## Yedekleme ve geri yükleme
- `deploy/scripts/backup.sh`: `mysqldump --single-transaction` → gzip → **GPG AES256** + yüklenen dosyalar; SHA256 listesi; N gün saklama. `deploy/systemd/kuafor-backup.timer` ile günlük. Şifreler `/etc/kuafor/backup.env` ve ayrı bir passphrase dosyasında (`600`), yedekler sürümlü/değiştirilemez nesne depolamaya kopyalanmalı.
- `deploy/scripts/restore.sh <dosya> <hedef_db>`: çözer, yükler, temel sayımları yazdırır.
- **Geri yükleme testi** (aylık): yedeği geçici bir veritabanına yükleyin ve sayımları üretimle karşılaştırın. Bu teslimatta yerel MySQL üzerinde backup→restore turu çalıştırıldı (3 tenant, 12 randevu geri yüklendi).

## Ölçekleme notları
- Web düğümleri durumsuz (oturum/önbellek Redis'te, dosyalar paylaşılan disk veya S3).
- Kilit tablosu (`resource_day_locks`) ve randevu indeksleri yatay ölçekte de doğru çalışır (tek MySQL birincil).
- Müsaitlik hesaplaması önbelleklenmez (her zaman taze); arama `date` filtresi ilk 200 adayla sınırlıdır.
