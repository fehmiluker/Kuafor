# Mimari

## Genel bakış
Laravel 12 monolit (PHP 8.3+ / 8.4 hedef), MySQL 8 (InnoDB, utf8mb4), Blade + Livewire 3 (Alpine dahil) + Tailwind 4 + Vite, FullCalendar 6. REST API v1 (Sanctum). Kuyruk: yerelde `database`, üretimde Redis önerilir.

```
Tarayıcı / Mobil / API istemcisi
        │
   Nginx ─ PHP-FPM ── Laravel (web + api + webhooks)
        │                 │
        │      ┌──────────┼───────────────┐
        │   MySQL 8    Kuyruk (Redis)   Scheduler (cron, tek sunucu)
        │                 │
        │       Worker'lar: notifications, webhooks, default
        │                 │
        └──── Meta WhatsApp Cloud API · SMTP
```

## Katmanlar
| Katman | Klasör | Not |
|---|---|---|
| HTTP | `app/Http/Controllers/{Api/V1,Web,Webhooks}` | İnce controller; iş kuralı yok |
| Doğrulama | `app/Http/Requests`, `app/Rules` | FormRequest `authorize()` önce çalışır (yetkisize doğrulama ayrıntısı sızmaz) |
| Yetki | `app/Policies`, `Services/Tenancy/AccessScope` | Policy + sorgu kapsamı + servis kontrolleri |
| Domain servisleri | `app/Services/*` | Booking, WhatsApp, Billing, Reviews, Search, Notifications |
| Modeller | `app/Models` | Tenant modelleri `BelongsToTenant` |
| Olaylar | `app/Events`, `app/Listeners` | `ShouldDispatchAfterCommit`; tek abonelik (`withEvents(discover: false)`) |
| İşler | `app/Jobs` | Idempotent, retry/backoff, `failed_jobs` = dead letter |
| UI | `resources/views`, `app/Livewire` | Rezervasyon sihirbazı Livewire |

## Çok kiracılılık (multi-tenancy)
- Tek veritabanı; tenant tablolarında zorunlu `tenant_id`, gerektiğinde `branch_id`.
- `TenantContext` (scoped singleton) + `TenantScope` global scope. **Fail-closed**: bağlam yoksa sorgu `1 = 0` döner. Platform kodu bilinçli olarak `asPlatform()` kullanır.
- `BelongsToTenant`: oluşturmada `tenant_id` bağlamdan gelir, farklı tenant'a yazma ve sonradan `tenant_id` değiştirme exception atar.
- Veritabanı düzeyi: bileşik FK `(tenant_id, x_id) → x(tenant_id, id)`; başka tenant'ın şube/personel/hizmet/müşterisine referans DB tarafından reddedilir.
- `ResolveTenant` middleware'i route model binding'den **önce** çalışır (öncelik listesi); bağlanan modeller zaten tenant kapsamlıdır → IDOR 404 olur.
- Tenant seçimi: kullanıcının aktif üyelikleri arasından (`X-Tenant`, oturum veya tek üyelik). İstemcinin gönderdiği `tenant_id` asla kullanılmaz.

## Roller
Spatie Permission "teams" = `tenant_id`. Tenant rolleri tenant bağlamında, platform rolleri (super_admin, platform_support, customer) **team 0**'da. Matris: `app/Support/Permissions.php` (tek kaynak), `docs/security.md`.

## Randevu motoru
Ayrıntı: `docs/decisions.md` (ADR-002/003). Özet:
- `AvailabilityService`: şube saatleri ∩ personel vardiyası − mola − izin − kapalı zaman − onaylı randevular (tampon dahil) − geçerli hold'lar; slot aralığı, min. bildirim, ufuk, saat dilimi.
- `BookingLockService`: `resource_day_locks` (tenant, employee, yerel gün) satırları işlemden önce INSERT IGNORE ile garanti edilir, işlem içinde sıralı `SELECT … FOR UPDATE`, ardından taze yeniden kontrol ve yazma. `READ COMMITTED` + deadlock retry.
- `AppointmentService`: durum makinesi (`AppointmentStatus::transitions()`), hold/teklif yönetimi, geçmiş kaydı, idempotency.

## Bildirimler
`NotificationDispatcher` kanal seçimi: WhatsApp (paket özelliği + bağlı şube numarası + onaylı şablon + müşteri izni) → e-posta → panel. Salon yöneticisi numarası gönderici numarasıyla aynıysa WhatsApp atlanır; panel + imzalı bağlantı kullanılır. Tüm teslimatlar deterministik dedupe anahtarıyla (olay + randevu + sürüm + alıcı) bir kez yapılır.

## Saat ve para
UTC saklama, gösterimde şube saat dilimi; tüm `now()` değerleri `CarbonImmutable`. Para: `DECIMAL(12,2)` ↔ `Money` (tam sayı kuruş), float yok.
