# REST API v1

Temel URL: `/api/v1`. OpenAPI 3: [`openapi.yaml`](openapi.yaml).

## Genel kurallar
- **Zarf:** her yanıt `{"data": …, "meta": {…}, "errors": [ … ]}`. Başarıda `errors = []`, hatada `data = null`.
- **Hata nesnesi:** `{"code": "slot_unavailable", "message": "Türkçe mesaj", "field?": "…", "reason?": "…"}`.
- **Kimlik doğrulama:** `Authorization: Bearer <sanctum token>` (`/auth/login`, `/auth/register`).
- **Tenant seçimi:** `/tenant/*` uçlarında `X-Tenant: <slug|id>`; yalnızca kullanıcının **aktif üyesi** olduğu işletme kabul edilir, aksi halde `403 tenant_required`. Gövde içindeki `tenant_id` yok sayılır.
- **Idempotency:** mutasyonlarda isteğe bağlı `Idempotency-Key` (8–120 karakter). Aynı anahtar + aynı gövde → ilk yanıt tekrar döner (`Idempotent-Replayed: true`); farklı gövde → `422 idempotency_mismatch`; işlenirken → `409 request_in_progress`.
- **Sayfalama:** `meta.pagination = {current_page, per_page, total, last_page}`; `?page=`.
- **Zaman:** tüm `*_at` alanları ISO-8601 UTC (`Z`). Randevu girişleri şube yerel `date` (Y-m-d) + `time` (H:i). `starts_at_local` ve `timezone` yanıtlarda bulunur.
- **Para:** `{"amount": "650.00", "currency": "TRY", "formatted": "650,00 ₺"}` (string, float değil).
- **Rate limit:** genel 120/dk; `auth` 10/dk/IP + 5/dk/hesap; `booking` 10/dk + 30/saat/IP; `guest` 30/dk; `otp` 5/dk; webhooks 600/dk. Aşımda `429 rate_limited` + `Retry-After`.

### Durum kodları
| Kod | Anlam | `errors[0].code` örnekleri |
|---|---|---|
| 200/201 | Başarılı / oluşturuldu (idempotent tekrar 200 dönebilir) | |
| 401 | Oturum yok / hatalı kimlik / MFA kodu gerekli | `unauthenticated`, `invalid_credentials`, `mfa_required` |
| 402 | Abonelik aktif değil (salt okunur) | `subscription_inactive` |
| 403 | Yetki yok, paket özelliği/limit | `forbidden`, `tenant_required`, `feature_unavailable`, `plan_limit` |
| 404 | Kayıt yok **veya başka tenant'a ait** (varlık sızdırılmaz) | `not_found` |
| 409 | Slot dolu / geçersiz durum geçişi / kapalı teklif | `slot_unavailable` (+`reason`), `invalid_transition`, `proposal_closed` |
| 422 | Doğrulama / iş kuralı | `validation_failed`, `too_many_pending`, `cancellation_cutoff`, `phone_verification_required`, `already_reviewed`, `not_completed` |
| 429 | Hız sınırı | `rate_limited` |

`slot_unavailable.reason`: `too_soon`, `outside_horizon`, `outside_working_hours`, `break`, `employee_leave`, `blocked_time`, `booked`, `held`, `in_past`.

## Yetki matrisi
| Uç grubu | Misafir | Müşteri | employee | receptionist | branch_manager | tenant_admin | tenant_owner | platform_support | super_admin |
|---|---|---|---|---|---|---|---|---|---|
| Public katalog/arama/müsaitlik | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `POST /appointments` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `guest/*` | token | token | | | | | | | |
| `me/*` | | ✓ (yalnızca kendi) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `tenant/appointments` (görüntüle) | | | kendi randevuları | atanmış şubeler | atanmış şubeler | ✓ | ✓ | | |
| approve/reject/proposals/cancel/reschedule | | | | atanmış şubeler | atanmış şubeler | ✓ | ✓ | | |
| complete/no-show/payments | | | kendi | atanmış şubeler | atanmış şubeler | ✓ | ✓ | | |
| `tenant/customers` | | | | ✓ | ✓ | ✓ | ✓ | | |
| `tenant/services` yazma | | | | | | ✓ | ✓ | | |
| `tenant/employees` yazma | | | | | atanmış şubeler | ✓ | ✓ | | |
| `tenant/branches` yazma | | | | | | ✓ | ✓ | | |
| `tenant/reports` (+paket `reports`) | | | | | ✓ | ✓ | ✓ | | |
| `tenant/settings`, `booking-settings` | | | | | | ✓ | ✓ | | |
| `tenant/whatsapp/*` (+paket `whatsapp`) | | | | | atanmış şubeler | ✓ | ✓ | | |
| `tenant/reviews/*/reply|report` | | | | | atanmış şubeler | ✓ | ✓ | | |
| `tenant/subscription/change-plan` | | | | | | | ✓ | | |
| `admin/*` okuma | | | | | | | | ✓ | ✓ |
| `admin/plans` yazma, `admin/subscriptions` yazma, `admin/tenants` yazma | | | | | | | | | ✓ |
| `admin/tenants/{id}/customers` (PII, gerekçe + log) | | | | | | | | | ✓ |
| `admin/reviews/*/moderate` | | | | | | | | ✓ | ✓ |

`admin/*` için token, **TOTP doğrulamasıyla** alınmış olmalıdır (`platform` yetenekli token; joker `*` token yetmez).

---

## Public

### `GET /cities` · `GET /districts?city_id=` · `GET /categories`
```json
{"data":[{"id":34,"name":"İstanbul","slug":"istanbul","plate_code":34}],"meta":{},"errors":[]}
```

### `GET /salons`
Parametreler: `city`, `district` (slug/id), `category` (slug), `service` (ad içinde), `q` (salon adı), `date` (Y-m-d, o gün en az bir boş slot), `time` (H:i, bu saatten sonra), `min_price`, `max_price`, `sort` (`rating|price_asc|price_desc|name|newest`), `per_page` (≤50), `page`.
Yalnızca aktif, listelemeye açık ve aboneliği kullanılabilir işletmeler döner.
```json
{"data":[{"id":1,"name":"Işıltı Kuaför","slug":"isilti-kuafor","description":"…","logo_url":null,"cover_url":null,
 "rating":{"average":4.67,"count":3},"min_price":{"amount":"350.00","currency":"TRY","formatted":"350,00 ₺"},
 "branches":[{"id":1,"name":"Kadıköy","slug":"kadikoy","city":{"id":34,"name":"İstanbul","slug":"istanbul"},"district":{"id":23,"name":"Kadıköy","slug":"kadikoy"},"map_url":"https://…","timezone":"Europe/Istanbul","status":"active"}],
 "url":"https://…/salon/isilti-kuafor"}],
 "meta":{"pagination":{"current_page":1,"per_page":12,"total":1,"last_page":1}},"errors":[]}
```

### `GET /salons/{slug}` · `GET /salons/{slug}/branches`
Doğrudan bağlantı listelemeye kapalı işletmede de çalışır (askıdaki/aboneliği bitmiş işletme → 404). Şubeler `working_hours` içerir: `[{"day_of_week":1,"day":"Pazartesi","opens":"09:00","closes":"19:00"}]`.

### `GET /salons/{slug}/reviews`
Yalnızca yayımdaki doğrulanmış yorumlar; yazar maskelenmiş (`"Zeynep K."`). `meta.average`, `meta.count`.

### `GET /branches/{branch}/services`
Şube fiyat/süre override'ı uygulanmış: `{"id":3,"name":"Dip Boya","duration_minutes":90,"buffer_minutes":15,"price":{"amount":"1800.00",…},"category":{…}}`.

### `GET /branches/{branch}/employees?service_id=`
`service_id` verilirse yalnızca o hizmeti verebilenler.

### `GET /availability?branch_id=&service_ids[]=&employee_id=&date=`
`employee_id` boş = "fark etmez".
```json
{"data":[{"starts_at":"2026-11-02T06:00:00Z","ends_at":"2026-11-02T06:45:00Z","time":"09:00","employee_ids":[1,2]}],
 "meta":{"date":"2026-11-02","timezone":"Europe/Istanbul"},"errors":[]}
```

### `POST /appointments`
Misafir veya `Bearer` ile üye. Başlık: isteğe bağlı `Idempotency-Key`.
```json
{"branch_id":1,"service_ids":[1,2],"employee_id":null,"date":"2026-11-02","time":"10:00",
 "full_name":"Zeynep Kaya","phone":"0555 111 22 33","email":"z@example.com","note":"",
 "kvkk_acknowledged":true,"whatsapp_opt_in":true,"marketing_opt_in":false,"verification_code":"123456"}
```
- `phone` E.164'e normalize edilir. `website` (honeypot) dolu ise 422.
- Salon telefon doğrulaması istiyorsa önce `POST /booking/verify-phone {"phone":"…"}` ile kod alınır.
- Yanıt `201` (yeni) veya `200` (idempotent tekrar):
```json
{"data":{"id":42,"reference":"K7MZP2Q9RA","status":"pending","status_label":"Onay bekliyor",
  "branch":{"id":1,"name":"Kadıköy"},"employee":{"id":1,"name":"Ayşe"},
  "services":[{"service_id":1,"name":"Kadın Saç Kesimi","duration_minutes":45,"price":{"amount":"650.00",…}}],
  "starts_at":"2026-11-02T07:00:00Z","ends_at":"2026-11-02T07:45:00Z","starts_at_local":"2026-11-02T10:00:00+03:00","timezone":"Europe/Istanbul",
  "subtotal":{…},"discount":{…},"total":{…},"payment_note":"Ödeme salonda yapılır.","pending_expires_at":"2026-11-02T…Z","source":"api"},
 "meta":{"guest_token":"<48 karakter, yalnızca bir kez gösterilir>","manage_url":"https://…/randevu/K7MZP2Q9RA?token=…","online_payment_required":false},"errors":[]}
```
Hatalar: `409 slot_unavailable`, `422 invalid_services|invalid_employee|too_many_pending|customer_blocked|phone_verification_required|tenant_unavailable`.

### `GET /guest/appointments/{reference}` · `POST /guest/appointments/{reference}/cancel`
Başlık `X-Guest-Token: <token>` (veya `?token=`). Token yalnızca o referans için, scope'lu (`view`, `cancel`, `respond_proposal`, `review`), süreli (30 gün) ve iptal edilebilir. Geçersiz/başka randevu/expired → **404**. İptal gövdesi `{"reason":"…"}`; onaylı randevuda iptal süresi (`cancellation_cutoff_minutes`) geçtiyse `422 cancellation_cutoff`.

### `POST /guest/proposals/{proposal}/accept`
### `POST /guest/proposals/{proposal}/reject`
Başlık `X-Proposal-Token` (teklif e-postası/sayfasındaki tek amaçlı token). Kabulde slot yeniden atomik kontrol edilir. Yanıt güncel randevu. Kapalı teklif → `409 proposal_closed`; süresi dolmuş → `422 proposal_expired`.

## Kimlik ve müşteri

### `POST /auth/register`
```json
{"name":"Elif Yıldız","email":"elif@example.com","phone":"05321110001","password":"Guvenli12345","password_confirmation":"Guvenli12345",
 "kvkk_acknowledged":true,"business_name":"Elif Kuaför","plan":"profesyonel"}
```
`business_name` verilirse deneme abonelikli işletme oluşturulur ve kullanıcı `tenant_owner` olur. `201`, `meta.token`.

### `POST /auth/login`
`{"email","password","otp?","device_name?"}`. Platform personeli için `otp` (TOTP) zorunlu; dönen token `platform` yetenekli ve 1 gün geçerli. Hatalı e-posta/şifre aynı yanıtı verir.

### `POST /auth/logout` · `POST /auth/forgot-password` (her zaman 200)

### `POST /auth/verify-phone`
Gövdesiz → SMS kodu gönderir. `{"code":"123456"}` → doğrular ve aynı numarayla alınmış geçmiş misafir randevularını hesaba bağlar (`data.linked_bookings`).

### `GET /me` · `PATCH /me`
`PATCH` gövdesi: `name`, `locale`, `notification_preferences: [{"channel":"whatsapp","category":"reminder","enabled":false}]`.

### `GET /me/appointments?scope=upcoming|past`
Yalnızca kullanıcının doğrulanmış telefonla bağlanmış CRM kartlarına ait randevular.

### `POST /me/appointments/{id}/cancel` · `POST /me/appointments/{id}/reschedule-request`
Değişiklik talebi salona panel bildirimi olarak düşer: `{"preferred_date":"2026-11-05","preferred_time":"14:00","note":"…"}`.

### `GET /me/favorites` · `POST /me/favorites {"slug"}` · `DELETE /me/favorites/{slug}`
### `POST /appointments/{id}/reviews`
`{"rating":1-5,"employee_rating?":1-5,"body?":"…"}`. Randevu `completed` olmalı ve hesabın doğrulanmış telefonu o randevunun müşterisi olmalı (aksi halde 404). Tekrar → `422 already_reviewed`.
### `GET /me/reviews` · `POST /me/data-requests {"type":"export|delete|rectify","note"}`

## İşletme (`X-Tenant` zorunlu)

| Uç | Açıklama |
|---|---|
| `GET /tenant/dashboard` | `today`, `pending`, `proposals_open`, `upcoming_7d`, `usage`, `over_limit`, `rating` |
| `GET /tenant/reports?from=&to=` | Duruma göre sayılar, tamamlanan ciro, yönteme göre tahsilat, personel, en çok tercih edilen hizmetler, gelmeme oranı |
| `GET/PATCH /tenant/settings` | `name`, `description`, `public_listing_enabled`, `theme.primary_color` (paket `custom_theme`) |
| `GET/PATCH /tenant/booking-settings` | `approval_mode`, `slot_interval_minutes`, `min_notice_minutes`, `max_horizon_days`, `cancellation_cutoff_minutes`, `pending_ttl_minutes`, `proposal_ttl_minutes`, `reminder_hours_before`, `require_phone_verification`, `max_pending_per_phone` |
| `GET/POST /tenant/branches`, `GET/PATCH/DELETE /tenant/branches/{id}` | Gövde: `name, phone, email, address, city_id, district_id, lat, lng, timezone, status, working_hours[{day_of_week,opens,closes}], whatsapp_manager_numbers[]`. Limit aşımı `403 plan_limit`. Gelecek randevulu şube silinemez (`422 branch_has_appointments`) |
| `…/tenant/employees` | `display_name, bio, status, buffer_minutes, branch_ids[], service_ids[], shifts[{branch_id,day_of_week,opens,closes}], user_email, role` |
| `…/tenant/services` | `name, description, category_id, duration_minutes, buffer_minutes, base_price, active, branch_ids[], branch_overrides[{branch_id,price,duration}]` |
| `…/tenant/customers` | `full_name, phone, email, notes, status(active|inactive|blocked)`; `GET /{id}` notları içerir ve erişim denetim kaydına yazılır |
| `GET /tenant/appointments?status=&branch_id=&employee_id=&from=&to=` | Kapsam: rol + şube + (employee ise) kendi |
| `POST /tenant/appointments` | `branch_id, service_ids[], employee_id?, date, time, customer_id? | full_name+phone, email?, note?` → otomatik onaylı |
| `GET /tenant/appointments/{id}` | Personel görünümü (müşteri iletişimi, iç not, tahsilatlar, açık teklif) |
| `POST /tenant/appointments/{id}/approve` | pending → confirmed (yeniden atomik kontrol) |
| `POST /tenant/appointments/{id}/reject` | `{"reason?"}` |
| `POST /tenant/appointments/{id}/proposals` | `{"date","time","employee_id?","note?"}` → `201 {"proposal_id","expires_at"}`; önceki açık teklif geri çekilir |
| `POST /tenant/appointments/{id}/reschedule` | confirmed randevuyu taşır; başarısızlıkta orijinal korunur |
| `POST /tenant/appointments/{id}/cancel` | `{"reason?"}` |
| `POST /tenant/appointments/{id}/complete` | `{"discount?":"50.00","payments?":[{"amount":"600.00","method":"cash|card|other","note?"}]}` |
| `POST /tenant/appointments/{id}/no-show` | yalnızca başlangıç geçtikten sonra |
| `POST /tenant/appointments/{id}/payments` | tamamlanmış randevuya ek tahsilat kaydı (gateway yok) |
| `GET /tenant/subscription` | abonelik, kullanım, `over_limit`, planlar, `automatic_payment_available:false` |
| `POST /tenant/subscription/change-plan` | `{"plan":"premium","interval":"monthly|yearly"}`; yükseltme anında, düşürme dönem sonunda |
| `GET /tenant/whatsapp/connections` | Kimlik bilgisi asla dönmez |
| `POST /tenant/branches/{branch}/whatsapp/connect` | `{"waba_id","phone_number_id","access_token","display_phone?"}` → canlı sağlık kontrolü sonrası `connected` |
| `POST /tenant/branches/{branch}/whatsapp/disconnect` | Token silinir |
| `GET /tenant/branches/{branch}/whatsapp/status` | Anlık sağlık kontrolü |
| `GET /tenant/reviews` · `POST /tenant/reviews/{id}/reply {"body"}` · `POST /tenant/reviews/{id}/report {"reason" ≥10}` | Silme ucu yoktur |

## Süper admin (`platform` yetenekli token)
| Uç | Not |
|---|---|
| `GET /admin/dashboard` | MRR/ARR (+`mrr_assumptions`), abonelik durumları, mesaj başarısı, kötüye kullanım uyarıları |
| `GET/POST /admin/tenants`, `GET/PATCH/DELETE /admin/tenants/{id}` | Durum değişikliği `reason` zorunlu; DELETE = kapatma (veri saklanır) |
| `GET /admin/tenants/{id}/customers?reason=&phone=` | `pii.access`; gerekçe zorunlu, en fazla 50 kayıt, denetim kaydı |
| `GET/POST /admin/plans`, `GET/PATCH/DELETE /admin/plans/{id}` | `features: {"whatsapp":true,…}`; düzenleme mevcut aboneliklerin snapshot'ını değiştirmez |
| `GET/PATCH /admin/subscriptions/{id}` | `status`, `record_payment`+`payment_reference` (idempotent), `plan`+`interval`, `trial_ends_at`; `reason` zorunlu |
| `GET /admin/whatsapp/health` | Bağlantı/webhook/mesaj/kuyruk durumu, ücret yapılandırması |
| `GET /admin/review-reports?status=open` · `POST /admin/reviews/{id}/moderate` | `{"status":"hidden|published|under_review","reason","report_id?"}` |
| `GET /admin/audit-logs?tenant_id=&action=` | |

## Sağlayıcı geri çağrıları (CSRF ve oturum yok, imza zorunlu)
| Uç | Doğrulama |
|---|---|
| `GET /webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=…&hub.challenge=…` | `META_VERIFY_TOKEN` eşleşirse challenge düz metin döner, aksi halde 403 |
| `POST /webhooks/meta/whatsapp` | Ham gövde üzerinde `X-Hub-Signature-256 = sha256=HMAC(app_secret)`; hatalıysa 401. Olaylar dedupe edilir, kuyruğa alınır, hızla `200 EVENT_RECEIVED` |
| `POST /webhooks/subscription-payments/{provider}` | `manual` sağlayıcı: `X-Signature = HMAC-SHA256(raw, SUBSCRIPTION_WEBHOOK_SECRET)`; gövde `{"event_id","subscription_id","status":"succeeded","amount","reference"}`; idempotent |
