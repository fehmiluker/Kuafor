# Kuaför Randevu — Kuaför ve güzellik salonu SaaS randevu platformu

Çok işletmeli (multi-tenant) randevu platformu: salon arama ve profil sayfaları, üyelikli/üyeliksiz online randevu, manuel/otomatik onay, WhatsApp üzerinden salon onayı ve alternatif saat teklifi, doğrulanmış yorumlar, abonelik paketleri ve platform yönetimi.

**Müşteriden online ödeme/kapora alınmaz.** Hizmet bedeli salonda ödenir; sistem yalnızca kayıt tutar.

| | |
|---|---|
| Altyapı | Laravel 12 · PHP 8.4 hedef (8.3.6 ile test edildi) · MySQL 8 · Livewire 3 + Alpine · Tailwind 4 · Vite · FullCalendar 6 |
| API | REST v1, Sanctum, `{data, meta, errors}` zarfı — [docs/api.md](docs/api.md), [docs/openapi.yaml](docs/openapi.yaml) |
| Yetki | Spatie Permission (teams = tenant) + Policy + sorgu kapsamı |
| WhatsApp | Resmî Meta Cloud API adaptörü + sandbox (fake) modu |

## Hızlı başlangıç
```bash
composer install
cp .env.example .env && php artisan key:generate   # DB_* ayarlarını girin (MySQL 8)
php artisan migrate --seed                          # referans + demo veri
npm ci && npm run build
php artisan storage:link
composer dev                                        # http://localhost:8000
```
Kuyruk (`queue:work`) ve zamanlayıcı (`schedule:work`) için [docs/deployment.md](docs/deployment.md).

### Demo hesapları (yalnızca yerel/demo — parola: `Demo12345!`)
| Rol | E-posta |
|---|---|
| İşletme sahibi (Premium, 2 şube, manuel onay, WhatsApp sandbox bağlı) | `sahip@isiltikuafor.test` |
| Şube müdürü (Kadıköy) | `mudur@isiltikuafor.test` |
| Resepsiyon | `resepsiyon@isiltikuafor.test` |
| Çalışan (Ayşe) | `ayse@isiltikuafor.test` |
| İşletme sahibi (Profesyonel, otomatik onay) | `sahip@modaberber.test` |
| İşletme sahibi (Standart, listelemeye kapalı) | `sahip@denizguzellik.test` |
| Müşteri | `musteri@example.test` |

Süper admin demo'da yoktur. Güvenli yoldan oluşturun (rastgele parola bir kez gösterilir, ilk girişte TOTP zorunlu):
```bash
php artisan platform:create-admin admin@example.com
```
Demo paket fiyatları (299/599/999 ₺/ay) yönetilebilir örnek değerlerdir: `/admin/paketler`.

## Ekranlar
- **Herkese açık:** `/` · `/salonlar/{il}/{ilçe}?kategori=&tarih=` · `/salon/{slug}` · `/salon/{slug}/randevu` (Livewire sihirbazı) · `/randevu/{ref}?token=` (misafir yönetimi) · `/teklif/{id}?token=` · `/sitemap.xml` · `/robots.txt`
- **Müşteri:** `/hesabim` (randevular, favoriler, yorumlar, profil, telefon doğrulama, KVKK veri indirme/talep)
- **İşletme paneli:** `/panel` (özet, takvim + sürükle-bırak, randevular, müşteriler, hizmetler, personel, şubeler + kapalı zaman + WhatsApp, yorumlar, raporlar, ayarlar, abonelik)
- **Platform yönetimi:** `/admin` (MRR/ARR, işletmeler, paketler, abonelik, moderasyon, denetim, sistem) — MFA zorunlu

## Testler
```bash
php artisan test        # gerçek MySQL 8 test veritabanı gerekir (phpunit.xml: kuafor_test)
vendor/bin/pint --test
```

### Test sonuçları (son çalıştırma)
| Paket | Test | Doğrulama |
|---|---|---|
| Unit | 16 | 40 |
| Feature | 75 | 406 |
| Security | 21 | 84 |
| Integration (WhatsApp) | 28 | 142 |
| Concurrency (fork + MySQL) | 5 | 14 |
| **Toplam** | **145 geçti, 0 hata** | **684** |

Ayrıca: Pint temiz, `npm run build` başarılı, Playwright ile mobil tarayıcıda uçtan uca rezervasyon akışı ve masaüstünde panel/takvim manuel doğrulandı, şifreli yedek → geri yükleme turu yerel MySQL'de çalıştırıldı. Ayrıntı: [docs/test-plan.md](docs/test-plan.md).

## Dokümantasyon
| Belge | İçerik |
|---|---|
| [architecture.md](docs/architecture.md) | Katmanlar, tenancy, randevu motoru, bildirimler |
| [requirements.md](docs/requirements.md) | Gereksinim → uygulama eşlemesi |
| [erd.md](docs/erd.md) | Veri modeli ve kısıtlar |
| [api.md](docs/api.md) | Uçlar, örnekler, hata kodları, yetki matrisi |
| [whatsapp.md](docs/whatsapp.md) | Meta Cloud API akışları, güvenlik, canlıya alma listesi |
| [security.md](docs/security.md) | RBAC, uygulama güvenliği, KVKK (hukuki doğrulama gereken maddeler) |
| [deployment.md](docs/deployment.md) | Kurulum, Nginx/PHP-FPM, kuyruk, zamanlayıcı, yedek/geri yükleme |
| [test-plan.md](docs/test-plan.md) | Senaryolar ve sınırlar |
| [decisions.md](docs/decisions.md) | ADR'ler ve açık konular |

Dağıtım dosyaları: `deploy/` (nginx, supervisor, systemd, backup/restore/deploy betikleri). CI: `.github/workflows/ci.yml`.

## Sağlayıcı sınırları (mock ↔ canlı)
| Entegrasyon | Bu teslimatta | Canlı için |
|---|---|---|
| WhatsApp | Hibrit: platform numarası (varsayılan, salon ayar yapmaz) + isteğe bağlı kendi numarası ("WhatsApp'ı bağla"). `fake` modu: gerçek mesaj yok; tüm akışlar testli | Meta işletme doğrulaması, App Review, numara kaydı, şablon onayı, `WHATSAPP_PROVIDER_MODE=meta` — [checklist](docs/whatsapp.md#canlıya-alma-kontrol-listesi) |
| SMS (OTP) | `log` sağlayıcı | `SmsProviderInterface` için sağlayıcı adaptörü |
| E-posta | `log`/SMTP | SMTP bilgileri |
| Abonelik tahsilatı | `manual` (yönetici aktivasyonu + imzalı webhook), otomatik kart tahsilatı **yok** | `PaymentGatewayInterface` için iyzico/PayTR vb. adaptör |
