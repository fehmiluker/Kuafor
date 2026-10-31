# Gereksinimler ve karşılanma durumu

| # | Gereksinim | Durum | Nerede |
|---|---|---|---|
| 1 | Çok işletmeli SaaS, tenant + şube izolasyonu | ✅ | `TenantScope`, bileşik FK, `AccessScope`, `tests/Security` |
| 2 | Platform araması + işletmeye özel rezervasyon sayfası | ✅ | `/salonlar/{il}/{ilçe}`, `/salon/{slug}`, `/salon/{slug}/randevu` |
| 3 | Üyeli / üyeliksiz randevu, süreli misafir token | ✅ | `GuestTokenService` (yalnızca hash, scope, expiry, revoke) |
| 4 | Manuel / otomatik onay | ✅ | `BookingSettings.approval_mode` |
| 5 | WhatsApp'tan salon onayı / alternatif saat; müşteri kabul/red | ✅ (sandbox) | `InboundMessageHandler`, `tests/Integration/WhatsAppFlowTest` |
| 6 | Şube başına WhatsApp numarası, gönderim şube numarasından | ✅ | `whatsapp_connections` (branch_id unique) |
| 7 | WhatsApp yoksa e-posta/panel ile çalışma | ✅ | `NotificationDispatcher` fallback |
| 8 | Online ödeme/kapora YOK, salonda tahsilat kaydı | ✅ | `appointment_payments`, `BillingTest::test_customers_are_never_asked_to_pay_online` |
| 9 | Yalnızca tamamlanmış randevuya doğrulanmış yorum, tek yanıt, moderasyon | ✅ | `ReviewService`, `ModerationService` |
| 10 | Listeleme aç/kapa, konum/kategori/hizmet/fiyat/uygunluk filtreleri | ✅ | `SalonSearchService` |
| 11 | Paket limitleri ve özellikleri (yönetilebilir demo fiyatlar) | ✅ | `plans`, `EntitlementService` (snapshot) |
| 12 | Yıllık paket, deneme, grace, askı | ✅ | `SubscriptionService::sweep()` |
| 13 | Roller: super_admin…customer, granular izinler | ✅ | `Support/Permissions.php` |
| 14 | Takvim, sürükle-bırak + yeniden müsaitlik kontrolü | ✅ | `/panel/takvim`, `PanelAppointmentController::drag` |
| 15 | Personel vardiya, mola, izin, beceri; şube/personel bazlı fiyat-süre | ✅ | `working_hours`, `employee_breaks`, `employee_leaves`, `branch_services`, `employee_services` |
| 16 | Yarış koşulları: aynı slot iki kişiye satılamaz | ✅ | `tests/Concurrency` (gerçek fork + MySQL) |
| 17 | KVKK: aydınlatma, izin kayıtları, veri indirme/silme talebi | ✅ (hukuki metin şablon) | `communication_consents`, `/hesabim/profil` |
| 18 | MFA (süper admin) | ✅ | TOTP (`Support/Totp.php`) web + API |
| 19 | Türkçe arayüz, mobil öncelikli, WCAG 2.2 AA hedefi | ✅ (otomatik a11y denetimi yapılmadı) | Blade/Livewire |
| 20 | REST API v1 + OpenAPI | ✅ | `docs/api.md`, `docs/openapi.yaml` |

## Kapsam dışı / açık konular
`docs/decisions.md` → "Açık konular".
