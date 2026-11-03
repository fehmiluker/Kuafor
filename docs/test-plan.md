# Test planı ve sonuçları

Testler **gerçek MySQL 8** üzerinde çalışır (`kuafor_test`): CHECK kısıtları, bileşik FK'ler ve satır kilitleri testin parçasıdır. Dış sağlayıcılar fake: `FakeWhatsAppClient` (doğrulama kodları dahil), `Mail::fake`, `manual` ödeme sağlayıcısı. Gerçek mesaj veya ödeme gönderilmez.

```bash
php artisan test                         # tümü
php artisan test --testsuite=Concurrency # fork tabanlı gerçek paralel yarış (pcntl gerekir)
```

| Paket | Dosya | Kapsam |
|---|---|---|
| Unit | `tests/Unit/*` | Para (float yok, TR biçimi, yuvarlama), saat dilimi + DST + gece yarısı, durum makinesi, telefon normalizasyonu |
| Feature/Booking | `AvailabilityTest`, `AppointmentLifecycleTest` | Slot üretimi, min. bildirim, tampon, çoklu hizmet, beceri, mola/izin/kapalı zaman, vardiya, ufuk, DST şubesi; otomatik/manuel onay, hold, TTL süpürme (idempotent), geç onayda yeniden kontrol, teklif kabul/red/timeout, tek açık teklif (DB düzeyi), iptal + süre sınırı, taşıma (başarısızlıkta orijinal korunur), tamamla + tahsilat + indirim, no-show, idempotency, "fark etmez" dengeli dağılım, bekleyen talep limiti, gece yarısını aşan rezervasyon |
| Feature/Api | `PublicApiTest`, `TenantApiTest` | Zarf, katalog, müsaitlik, misafir rezervasyon (ödeme yok), token kapsamı/süresi, honeypot/KVKK/telefon doğrulama, 409 çakışma, Idempotency-Key tekrar/uyuşmazlık, teklif uçları, OTP zorunluluğu, panel aksiyonları, limitler (backend), hizmet override, listeleme kapatma, resepsiyon/çalışan yetkileri, **N+1 koruması** |
| Feature/Web | `PagesRenderTest` | Tüm public/müşteri/işletme/admin ekranları rol bazlı render; MFA yönlendirmesi |
| Feature | `BillingTest` | Deneme snapshot/bitişi, imzalı + idempotent ödeme webhook'u, KDV, yenileme, past_due→grace→suspended, düşürme planlama, plan düzenleme snapshot'ı etkilemez, özellik kısıtı, askıdaki tenant salt okunur ve rezervasyon almaz, admin manuel aktivasyon, müşteriden online ödeme istenmez |
| Feature | `ReviewsTest` | Yalnızca tamamlanan + tek yorum, misafir token ile yorum, hesap sahipliği doğrulaması, ortalama yalnızca yayımdakiler, moderasyon log'u, tek yanıt, silme yok, davet tekilliği |
| Feature | `SearchAndAccountTest` | İl/ilçe/kategori/hizmet/fiyat/tarih filtreleri, SEO URL'leri, gizli/askıdaki salon, favoriler, işletme kaydı + deneme, OTP sonrası geçmiş randevu bağlama, parola sıfırlama sızdırmazlık, veri indirme |
| Feature | `EndToEndTest` | Mobil müşteri: arama → salon → Livewire sihirbazı → WhatsApp'tan salon onayı → müşteri bildirimi → tamamlandı → davet linkiyle doğrulanmış yorum; otomatik onay; web linkinden teklif kabulü; takvim beslemesi + sürükle-bırak yeniden doğrulama/yetki |
| Security | `TenantIsolationTest`, `AppSecurityTest` | Tenant A → B kayıtları 404, üyeliksiz tenant geçişi, sahte branch/service/employee/tenant_id, fail-closed scope, hook'lar, bileşik FK, şube müdürü kapsamı, müşteri IDOR; stored XSS, yorum temizleme, yükleme kötüye kullanımı (PHP/SVG), CSRF, OTP brute-force + gönderim limiti, giriş limiti + numaralandırma yok, admin API MFA (joker token reddedilir), destek rolü kısıtı, gerekçeli PII erişimi, güvenlik başlıkları, sırların serileşmemesi, üyelikten çıkarılanın erişim kaybı |
| Integration | `WhatsAppFlowTest`, `WhatsAppHybridTest` | GET doğrulama, POST imza (eksik/sahte), salon onayı butonla, liste ile gerçek slottan teklif + müşteri butonla kabul, başka numaradan red, müşteri reddi, webhook tekrarı, monoton teslim durumları, tenant'lar arası token, aynı numara atlama, paket/izin/şablon/bağlantı yokken e-posta fallback, geçersiz token → bağlantı hatası, rate limit → retry (çift gönderim yok), 24 saat penceresi, tek hatırlatma; hibrit model: platform numarasından salon adıyla gönderim, platform numarasında token'dan tenant çözümü, tenant'lar/numaralar arası token reddi, Embedded Signup (web + API, hata durumları), kendi numaraya geçiş, paket/ayar kapatma, admin platform numarası ve destekli kurulum, yetkiler |
| Concurrency | `DoubleBookingTest` | 8 paralel süreç aynı slot → tam 1 başarı; örtüşen farklı saatler; "fark etmez" yarışında her personel 1 kez; aynı Idempotency-Key ile çift tıklama → 1 satır; teklif kabulü vs yeni rezervasyon |

## Tarayıcı E2E (manuel çalıştırılan Playwright betiği)
iPhone 13 görünümünde ana sayfa → salon → sihirbaz (şube, hizmet, "fark etmez", gün değiştir, slot, bilgiler, özet, onay) ve masaüstünde işletme girişi → takvim (etkinlikler görünür) → randevu detayı; Pixel 7'de panelde yatay taşma yok; konsolda JS hatası yok (sandbox ağında font CDN'i engelli olduğundan yalnız font isteği başarısız).

## Son çalıştırma
Bkz. README "Test sonuçları".

## Çalıştırılmayanlar / sınırlar
- Canlı Meta Cloud API ve SMTP ile entegrasyon (kimlik bilgisi yok; fake/sandbox ile test edildi).
- Otomatik WCAG denetimi (axe vb.) çalıştırılmadı; erişilebilirlik manuel kurallarla uygulandı (etiketler, odak, aria, kontrast).
- Yük/performans testi yapılmadı.
- PHP 8.4 yerine ortamdaki PHP 8.3.6 ile test edildi.
