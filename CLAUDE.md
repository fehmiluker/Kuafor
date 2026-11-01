# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Proje
Kuaför/güzellik salonları için çok işletmeli (multi-tenant) SaaS randevu platformu. Laravel 12 monolit · PHP 8.4 hedef (8.3+) · MySQL 8 · Blade + Livewire 3 (Alpine) · Tailwind 4 + Vite · FullCalendar 6 · REST API v1 (Sanctum). Arayüz metinleri ve dokümanlar Türkçe; kod ve kod yorumları İngilizce.

**Müşteriden online ödeme/kapora alınmaz** — `appointment_payments` yalnızca kayıttır. Ayrıntılı belgeler `docs/` altında (architecture, decisions/ADR, erd, api, security, whatsapp, deployment, test-plan).

## Komutlar
```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # referans + demo veri (demo parola: Demo12345!)
composer dev                        # serve + queue:listen + pail + vite birlikte
php artisan schedule:work           # zamanlayıcı (routes/console.php)

php artisan test                                   # tüm paketler
php artisan test --testsuite=Feature               # Unit | Feature | Security | Integration | Concurrency
php artisan test --filter=DoubleBookingTest        # tek sınıf / tek metot
vendor/bin/pint --test                             # stil kontrolü (düzeltmek için: vendor/bin/pint)

npm run build                       # CSS/JS değiştiyse ZORUNLU, çıktıyı commit edin
php artisan platform:create-admin admin@example.com   # süper admin (TOTP zorunlu)
php artisan kuafor:check [--demo]   # kurulum/sağlık kontrolü
```

- **Testler gerçek MySQL 8 ister** (`phpunit.xml` → `kuafor_test`); SQLite ile çalışmaz — CHECK kısıtları, bileşik FK'ler ve satır kilitleri testin parçasıdır.
- `tests/Concurrency` `pcntl` gerektirir (yoksa atlanır); `DatabaseTruncation` + fork edilen süreçlerle gerçek InnoDB yarışı test eder.
- **`public/build` depoda commit'lidir** (cPanel hosting'de Node yok). CI, `npm run build` sonrası `git diff public/build` temiz değilse başarısız olur.
- CI (`.github/workflows/ci.yml`): PHP 8.4 + MySQL 8, `npm ci && npm run build`, build güncelliği, `pint --test`, `php artisan test`.

## Mimari (büyük resim)

### Katmanlar
İnce controller'lar (`app/Http/Controllers/{Api/V1,Web,Webhooks}`) → FormRequest (`authorize()` doğrulamadan önce) → Policy + `Services/Tenancy/AccessScope` → domain servisleri (`app/Services/*`). İş kuralları servislerde; ayrı `Actions` klasörü yok (ADR-011). Domain hataları `app/Exceptions/*` ile atılır ve `bootstrap/app.php` içinde API için `{data, meta, errors}` zarfına (`Http/Responses/ApiResponse`) dönüştürülür.

### Çok kiracılılık — en kritik kural
- Tek DB, tenant tablolarında zorunlu `tenant_id`. Tenant modelleri `Models/Concerns/BelongsToTenant` kullanır; `TenantScope` **fail-closed**: `TenantContext` yoksa sorgu `1 = 0` döner (boş sonuç, hata değil).
- Bağlam: `TenantContext::run($tenant, fn)` ; çapraz tenant kod (admin, job, scheduler) bilinçli olarak `asPlatform(fn)` kullanır. Testlerde `$this->inTenant($tenant, fn)`.
- `BelongsToTenant` `tenant_id`'yi bağlamdan doldurur; başka tenant'a yazmak veya `tenant_id` değiştirmek exception atar. İstemciden gelen `tenant_id` asla kullanılmaz (tenant: `X-Tenant`, oturum veya tek üyelik).
- DB düzeyinde bileşik FK `(tenant_id, x_id) → x(tenant_id, id)`: yeni migration'larda `App\Support\Database\TenantSchema::foreign()` / `check()` / `checkIn()` kullanın.
- `ResolveTenant` middleware'i `SubstituteBindings`'ten **önce** çalışır (öncelik listesi, `bootstrap/app.php`) → başka tenant'ın modeli 404 olur.
- Roller: Spatie Permission "teams" = `tenant_id`; platform rolleri team 0'da. Yetki matrisinin tek kaynağı `app/Support/Permissions.php`.

### Randevu motoru (`app/Services/Booking`)
- `AvailabilityService`: şube saatleri ∩ vardiya − mola − izin − kapalı zaman − onaylı randevular (tampon dahil) − geçerli hold'lar.
- `BookingLockService` (ADR-002): `resource_day_locks` satırları işlemden **önce** `INSERT IGNORE` ile oluşturulur, işlem içinde sıralı `SELECT … FOR UPDATE`, sonra müsaitlik **taze** yeniden kontrol edilir. `READ COMMITTED` + deadlock retry. Bu sırayı bozmayın; `DoubleBookingTest` bunu doğrular.
- `AppointmentService`: durum makinesi (`Enums/AppointmentStatus::transitions()`), hold/teklif, durum geçmişi, idempotency (`idempotent` middleware + `IdempotencyKey`).
- Hold'lar süreli engeller (ADR-003); süresi dolan hold, süpürme işi çalışmasa bile engellemez. Tampon kuralları ADR-004, "fark etmez" personel seçimi ADR-005.

### Olaylar ve bildirimler
- Olay keşfi **kapalı** (`withEvents(discover: false)`); dinleyiciler `AppServiceProvider::boot` içinde elle kaydedilir — keşfi açmak bildirimleri çift gönderir.
- Olaylar `ShouldDispatchAfterCommit`. `NotificationDispatcher` kanal sırası: WhatsApp (paket özelliği + bağlı numara + onaylı şablon + izin) → e-posta → panel. Her teslimat deterministik dedupe anahtarıyla (olay + randevu + version + alıcı) tekildir.
- Job'lar idempotent; zamanlanmış işler `routes/console.php` (`onOneServer`, heartbeat'ler `kuafor:check` için).

### Entegrasyonlar (arayüz + sahte/yerel uygulama)
- WhatsApp: `WhatsAppClientInterface` → `MetaCloudApiClient` / `FakeWhatsAppClient` (`WHATSAPP_PROVIDER_MODE=fake|meta`). Hibrit numara modeli (platform numarası varsayılan, şube kendi numarası opsiyonel) — ADR-013, `docs/whatsapp.md`. Webhook'lar `routes/webhooks.php` (CSRF muaf, imza doğrulamalı).
- SMS: `SmsProviderInterface` → `LogSmsProvider` / `NetgsmSmsProvider` (`SMS_PROVIDER=log|netgsm`).
- Abonelik tahsilatı: `PaymentGatewayInterface` → `ManualPaymentGateway` / `PaytrPaymentGateway` (`SUBSCRIPTION_PAYMENT_PROVIDER=manual|paytr`). PayTR `HostedCheckoutGateway`'dir: `CheckoutService` `subscription_checkouts` kaydı + iFrame token oluşturur; sonuç yalnızca imzalı callback'ten (`/webhooks/subscription-payments/paytr`, cevap düz `OK`) gelir, tutar checkout kaydından alınır. Tekrarlayan çekim yok. Ayrıntı: `docs/paytr-netgsm.md`.
- Abonelik hakları `entitlements_snapshot`'tan okunur (ADR-007): `EntitlementService`, `subscribed` / `feature` middleware'leri, `PlanLimitException`.

### Zaman ve para
- UTC saklanır, şube saat diliminde gösterilir (`Support/LocalTime`). Tüm tarihler `CarbonImmutable` (ADR-012).
- Para: DB'de `DECIMAL(12,2)` ↔ `Support/Money` (tam sayı kuruş, `Casts/MoneyCast`); float kullanmayın.

## Test yardımcıları
- `Tests\TestCase`: `RefreshDatabase` + `TestBaseSeeder`, `plan($slug)`, `inTenant()`.
- `Tests\Support\BuildsSalon`: standart fixture (Profesyonel plan, İstanbul şubesi, 2 hizmet, 2 personel), zamanı **Pazartesi 2026-11-02 08:00 İstanbul**'a sabitler; `at('10:00')` yerel saati UTC'ye çevirir.
- Seeder tarafında `Database\Seeders\Support\SalonBuilder` fixture/demosalon kurar.
