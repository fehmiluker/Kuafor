# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Proje
Kuaför/güzellik salonları için çok işletmeli (multi-tenant) SaaS randevu platformu. Laravel 12 monolit (kurumsal lacivert tema) · PHP 8.4 hedef (8.3+) · MySQL 8 · Blade + Livewire 3 (Alpine) · Tailwind 4 + Vite · FullCalendar 6 · REST API v1 (Sanctum). Arayüz metinleri ve dokümanlar Türkçe; kod ve kod yorumları İngilizce.

**Müşteriden online ödeme/kapora alınmaz** — `appointment_payments` yalnızca kayıttır; kartla ödeme yalnızca salon sahibinin platform aboneliği içindir (PayTR). Ayrıntılı belgeler `docs/` altında (architecture, decisions/ADR, erd, api, security, whatsapp, paytr-netgsm, cpanel-kurulum, deployment, test-plan).

## Dallar ve yayın
- `main`: kararlı sürüm; cPanel kurulumu (`docs/cpanel-kurulum.md`) bu daldan çeker. `claude/kuafor-saas-platform`: geliştirme dalı. `design/modern-purple-ui`: yeni arayüz (PR #1, taslak).
- **`main`'e birleştirme ve canlı sunucuya yayın yalnızca kullanıcı açıkça isterse.** `claude/ftp-deploy-workflow` dalındaki `deploy-ftp.yml` `main`'e birleşirse her `main` push'u FTP ile canlıya yükler (yalnızca dosya; migrate/optimize çalıştırmaz).

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
- **`public/build` depoda commit'lidir** (cPanel hosting'de Node yok). CI, `npm run build` sonrası `git diff public/build` temiz değilse başarısız olur ("Committed frontend build is up to date"). Blade'de Tailwind sınıfı değiştiren her commit'te `npm run build` çalıştırıp `public/build`'i de commit edin.
- `npm run build` öncesi **`composer install` şart**: `resources/css/app.css` `vendor/laravel/.../Pagination` görünümlerini `@source` ile tarar; `vendor/` yoksa CSS farklı çıkar ve CI kırılır.
- `design/**` dallarında `.github/workflows/build-assets.yml` (o dalda) derlemeyi otomatik commit eder ve CI'ı `workflow_dispatch` ile yeniden başlatır; bu dallarda push/PR çalışmalarında eski build yalnızca uyarıdır.
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
- Sağlayıcılar `AppServiceProvider::register` içinde config'e göre **singleton** bağlanır. Sağlayıcı HTTP hataları (`HttpClientException`) log'a yazılır, kullanıcıya `DomainRuleException` ile genel mesaj gösterilir.
- Reklamlar (sponsor banner): platform düzeyi `AdCampaign` (tenant'sız), `Services/Ads/AdService::pick($placement, $cityId)` (şehre özel > genel, `weight` ile rastgele), Blade `<x-ad-slot placement="panel_dashboard|home|search" />`. Kendi barındırdığımız görseller: üçüncü taraf reklam kodu/çerez yok (CSP ve KVKK için bilinçli). Tıklama `/reklam/{id}` → sayaç + DB'deki adrese yönlendirme (açık yönlendirme yok). Yönetim: `/admin/reklamlar` (`platform.settings`). Her bannerda "Reklam" etiketi zorunlu; salon profil sayfasına ve randevu sihirbazına reklam konmaz.
- Ana sayfa kategori kutuları: Kuaför, Berber, Güzellik, Tırnak, Masaj (`PublicController::HOME_CATEGORIES`; "masaj" kategorisi veri migration'ı ile eklenir, "makyaj" kategorisi aramada durur).
- Tema: kurumsal lacivert. Renkler `resources/css/app.css` `@theme` içindeki `brand-*` paletinden gelir; görünümlerde `violet/fuchsia` gibi sabit renk yerine `brand-*` kullanın. Ana sayfa kategori kutuları `.cat-tile` + `.tone-k1..k5`.
- Müşteri ana sayfası: konum seçici (`/konum`, çerez `konum` = `il-slug/ilce-slug`, `Support/VisitorLocation`), "Size yakın salonlar" seçilen ilçe/ile göre `SalonSearchService` ile. Salon kartı (`public/_salon-card`) etiket ve favorileri sayfa başına tek sorguda `Services/Catalog/SalonCards` (scoped) ile alır; listeleyen controller `->prime($tenants)` çağırır. Mobilde alt menü `layouts/public` içinde. Ana sayfa görselleri `public/images/home/*` (kullanıcının tasarım görselinden; lisanslı/yüksek çözünürlüklü görsellerle değiştirilebilir).
- Örnek işletmeler: `tenants.is_showcase` → listelenir/görüntülenir, randevu alınamaz (`Tenant::bookable` hariç tutar, salon sayfası `viewableTenant`). `BursaShowcaseSeeder` + `kuafor:ornek-isletmeler [--kaldir]`. Örnek yorumlar doğrudan DB'ye yazılır (olay/bildirim yok), arayüzde "Örnek yorum" etiketlidir ve JSON-LD `aggregateRating`'e girmez — gerçek yorum gibi gösterilmemeli.
- Salon ekleme: `Services/Onboarding/SalonOnboardingService` (sahip hesabı şifresiz + `TenantProvisioner` + `TenantCatalogService` ile "Merkez" şubesi/hizmet/personel; hizmet yoksa listelenmez) ortak çekirdektir. `SalonCsvImporter` (önizle → aktar, `kuafor:salon-aktar`) ve `salon_applications` (`/salonunu-ekle` → `/admin/basvurular` onay/ret; açık/kapalı `salon_applications_enabled` platform ayarı) bunu kullanır. Davet: `invites` parola aracısı (7 gün) + `/davet/{token}`, `SalonInvitation` kuyruklu bildirim. Yetki: `tenants.manage`. Yalnızca katılmayı kabul eden salonlar — kazınmış işletme verisi yüklenmez.
- Müşteri bağlantısı: `/r/{slug}` (`BookingLinkController`) → randevu sihirbazı; `Services/Catalog/BookingLinkService` (QR SVG `bacon/bacon-qr-code`, `?k=link|qr|wa|eposta` kaynakları, oturum başına ziyaret + randevu atfı, `booking_link_stats` günlük sayaç). Panel: `tenant.share` (QR indir/yazdır). Tenant slug'ı değiştirilemez; basılı QR'lar bu yüzden kalıcıdır — slug değişikliği eklenirse eski slug'lar yönlendirilmeli.
- Tekrar randevu: `Services/Catalog/RebookService` (girişli: randevu geçmişi + favoriler; misafir: `son_salon` çerezi, sihirbazda onaydan sonra yazılır). Sihirbaz `?sube=&hizmet=1,2&personel=` ön seçimini kabul eder ve şubede hâlâ sunulanlarla sınırlar. Ana sayfa bu nedenle kişiye özeldir (sayfa önbelleği eklemeyin).
- CSP `Http/Middleware/SecurityHeaders::policy($extraOrigins)` ile üretilir; harici bir alan adı (ör. PayTR) yalnızca ihtiyaç duyan sayfanın cevabına eklenir, global politikaya değil.

### Zaman ve para
- UTC saklanır, şube saat diliminde gösterilir (`Support/LocalTime`). Tüm tarihler `CarbonImmutable` (ADR-012).
- Para: DB'de `DECIMAL(12,2)` ↔ `Support/Money` (tam sayı kuruş, `Casts/MoneyCast`); float kullanmayın.

## Test yardımcıları
- `Tests\TestCase`: `RefreshDatabase` + `TestBaseSeeder`, `plan($slug)`, `inTenant()`.
- `Tests\Support\BuildsSalon`: standart fixture (Profesyonel plan, İstanbul şubesi, 2 hizmet, 2 personel), zamanı **Pazartesi 2026-11-02 08:00 İstanbul**'a sabitler; `at('10:00')` yerel saati UTC'ye çevirir.
- Seeder tarafında `Database\Seeders\Support\SalonBuilder` fixture/demosalon kurar.
- `phpunit.xml` dış servisleri kapatır (`WHATSAPP_PROVIDER_MODE=fake`, `SMS_PROVIDER=log`, `manual` ödeme). Başka sağlayıcıyı test ederken `config([...])` sonrası `$this->app->forgetInstance(Interface::class)` yapın ve dış API'yi `Http::fake()` ile taklit edin (örnek: `tests/Feature/PaymentProvidersTest.php`).
- Web panel testleri: `$this->actingAs($this->owner($this->tenant))` (tek üyelik → tenant otomatik seçilir).
