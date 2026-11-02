# Kuaför Randevu — Kuaför ve güzellik salonu SaaS randevu platformu

Çok işletmeli (multi-tenant) randevu platformu: salon arama ve profil sayfaları, üyelikli/üyeliksiz online randevu, manuel/otomatik onay, WhatsApp üzerinden salon onayı ve alternatif saat teklifi, doğrulanmış yorumlar, abonelik paketleri ve platform yönetimi.

**Müşteriden online ödeme/kapora alınmaz.** Hizmet bedeli salonda ödenir; sistem yalnızca kayıt tutar.

| | |
|---|---|
| Altyapı | Laravel 12 · PHP 8.4 hedef (8.3.6 ile test edildi) · MySQL 8 · Livewire 3 + Alpine · Tailwind 4 · Vite · FullCalendar 6 |
| API | REST v1, Sanctum, `{data, meta, errors}` zarfı — [docs/api.md](docs/api.md), [docs/openapi.yaml](docs/openapi.yaml) |
| Yetki | Spatie Permission (teams = tenant) + Policy + sorgu kapsamı |
| WhatsApp | Resmî Meta Cloud API adaptörü + sandbox (fake) modu |

> **Online demo (VPS, ör. Hostinger):** [docs/vps-kurulum.md](docs/vps-kurulum.md) — tek komut: `DOMAIN=… EMAIL=… DEMO_PASS=… bash install.sh`.
> **Online demo (cPanel):** [docs/cpanel-kurulum.md](docs/cpanel-kurulum.md) — `bash deploy/cpanel/install.sh`, sonra `php artisan kuafor:check --demo`.

## Hızlı başlangıç
```bash
composer install
cp .env.example .env && php artisan key:generate   # DB_* ayarlarını girin (MySQL 8)
php artisan migrate --seed                          # referans + demo veri
npm ci && npm run build      # yalnızca CSS/JS değiştiyse (derlenmiş hali public/build içinde depoda)
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

**Örnek işletmeler (Bursa):** Pazar yeri boş görünmesin diye 11 ilçede 20 kurgusal salon; listelenir ama randevu alınamaz. Kartta ve sayfada "Örnek işletme", yorumlarda "Örnek yorum" etiketi bulunur; puanları arama motorlarına yapısal veri olarak verilmez. Canlıda da çalışır:
```bash
php artisan kuafor:ornek-isletmeler           # ekle (tekrar çalıştırmak güvenli; eksikleri tamamlar, listeden çıkarılanları kaldırır)
php artisan kuafor:ornek-isletmeler --kaldir  # gerçek salonlar katılınca kaldır
```

**Müşteri bağlantısı ve favoriler:**
- Her salonun kalıcı randevu bağlantısı `/r/{salon}` doğrudan randevu ekranını açar. Panel > *Müşteri bağlantım*: kopyala, WhatsApp'ta paylaş, QR kod (PNG/SVG indir, yazdırılabilir masa kartı), şube bağlantıları ve son 30 gün ziyaret/randevu sayıları (bağlantı, QR, WhatsApp, e-posta).
- Müşteri tarafı: randevu sonrası "Favorilere ekle"; ana sayfada "Favori ve son salonlarınız" (üye olmayan için bu cihazda son gidilen salon, `son_salon` çerezi); Favoriler sayfasında "Tekrar randevu al" (son hizmet ve personel seçili gelir), ara ve yol tarifi. Müşteri e-postalarında "Yeni randevu al" bağlantısı.

**Adrese hizmet (ev/işyeri):**
- Panel > Şubeler > şube: "Adrese hizmet" açılır; gidilen ilçeler ve ilçe başına ulaşım ücreti, yol süresi (randevunun önü ve arkası personelin takviminde kapatılır), en düşük hizmet tutarı ve "adres randevuları onayımdan sonra kesinleşsin" seçimi. Hizmetlerde "Adreste de yapılabilir", personelde "Adrese hizmete gidebilir" işaretlenir. Her pakette açıktır.
- Müşteri: randevu sihirbazında "Salonda / Adresimde", ilçe + açık adres; ulaşım ücreti özette toplama eklenir (ödeme yine hizmet sonrası). Adres randevusunda telefon doğrulaması zorunludur. Aramada "Evime / işyerime gelsin" filtresi (salon başka ilçede olsa da o ilçeye gidiyorsa listelenir), kartta "🏠 Adrese" etiketi.
- Açık adres şifreli saklanır, yalnızca randevu detayında görünür (listelerde ve bildirimlerde yalnızca ilçe), `HOME_ADDRESS_RETENTION_DAYS` (varsayılan 90) gün sonra silinir.

**Salon ekleme (süper admin):**
- *Salon aktarma* (`/admin/salon-aktarma`): platforma katılmayı kabul eden salonları CSV ile toplu ekleyin (şablon sayfada). Önce önizleme, sonra aktarma; her sahibe 7 gün geçerli şifre belirleme daveti gider. SSH ile: `php artisan kuafor:salon-aktar dosya.csv [--davet] [--onizle]`. İzinsiz toplanmış işletme verisi yüklemeyin.
- *Başvurular* (`/admin/basvurular`): salon sahipleri `/salonunu-ekle` formundan başvurur (alt bilgideki "İşletmeniz mi var?"); onaylayınca salon kurulur ve davet gider, reddedilirse gerekçe e-postalanır. Form aynı sayfadan açılıp kapatılır.
- Davet ve ret e-postaları kuyruktan gider: `queue:work` (veya cron'daki `queue:work --stop-when-empty`) çalışmalı.

Süper admin demo'da yoktur. Güvenli yoldan oluşturun (rastgele parola bir kez gösterilir, ilk girişte TOTP zorunlu):
```bash
php artisan platform:create-admin admin@example.com
```
Demo paket fiyatları (299/599/999 ₺/ay) yönetilebilir örnek değerlerdir: `/admin/paketler`.

## Ekranlar
- **Herkese açık:** `/` · `/salonlar/{il}/{ilçe}?kategori=&tarih=` · `/salon/{slug}` · `/salon/{slug}/randevu` (Livewire sihirbazı) · `/randevu/{ref}?token=` (misafir yönetimi) · `/teklif/{id}?token=` · `/sitemap.xml` · `/robots.txt`
- **Müşteri:** `/hesabim` (randevular, favoriler, yorumlar, profil, telefon doğrulama, KVKK veri indirme/talep)
- **İşletme paneli:** `/panel` (özet, takvim + sürükle-bırak, randevular, müşteriler, hizmetler, personel, şubeler + kapalı zaman + WhatsApp, yorumlar, raporlar, ayarlar, abonelik)
- **Platform yönetimi:** `/admin` (MRR/ARR, işletmeler, paketler, abonelik, moderasyon, **reklamlar**, denetim, sistem) — MFA zorunlu
- **Sponsor bannerlar:** işletme paneli özeti, ana sayfa ve arama sonuçlarında "Reklam" etiketli banner; `/admin/reklamlar` ile görsel, hedef adres, tarih, şehir hedefi; gösterim/tıklama sayaçları

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
| [vps-kurulum.md](docs/vps-kurulum.md) | **VPS (Hostinger) demo kurulumu tek komutla**: Nginx, PHP, MySQL, kuyruk, cron, HTTPS, site şifresi |
| [paytr-netgsm.md](docs/paytr-netgsm.md) | PayTR abonelik ödemesi ve Netgsm SMS kurulumu, test listesi |
| [cpanel-kurulum.md](docs/cpanel-kurulum.md) | **cPanel demo kurulumu adım adım** (Node.js gerekmez), demo hesapları, kurulum sonrası test listesi |
| [deployment.md](docs/deployment.md) | VPS kurulumu, Nginx/PHP-FPM, kuyruk, zamanlayıcı, yedek/geri yükleme |
| [test-plan.md](docs/test-plan.md) | Senaryolar ve sınırlar |
| [decisions.md](docs/decisions.md) | ADR'ler ve açık konular |

Dağıtım dosyaları: `deploy/` (nginx, supervisor, systemd, backup/restore/deploy betikleri). CI: `.github/workflows/ci.yml`.

## Sağlayıcı sınırları (mock ↔ canlı)
| Entegrasyon | Bu teslimatta | Canlı için |
|---|---|---|
| WhatsApp | Hibrit: platform numarası (varsayılan, salon ayar yapmaz) + isteğe bağlı kendi numarası ("WhatsApp'ı bağla"). `fake` modu: gerçek mesaj yok; tüm akışlar testli | Meta işletme doğrulaması, App Review, numara kaydı, şablon onayı, `WHATSAPP_PROVIDER_MODE=meta` — [checklist](docs/whatsapp.md#canlıya-alma-kontrol-listesi) |
| SMS (OTP) | `log` (varsayılan) veya **Netgsm** (`SMS_PROVIDER=netgsm`) | Netgsm API kullanıcısı + onaylı başlık — [kurulum](docs/paytr-netgsm.md#2-netgsm--sms-telefon-doğrulama-kodları) |
| E-posta | `log`/SMTP | SMTP bilgileri |
| Abonelik tahsilatı | `manual` (yönetici aktivasyonu) veya **PayTR** iFrame ile salon sahibinin kartla ödemesi (`SUBSCRIPTION_PAYMENT_PROVIDER=paytr`); otomatik/tekrarlayan çekim **yok** | PayTR mağaza bilgileri + Bildirim URL — [kurulum](docs/paytr-netgsm.md#1-paytr--kartla-abonelik-ödemesi) |
