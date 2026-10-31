# cPanel Kurulum Rehberi (Demo / Test Sunucusu)

Bu rehber, Kuaför Randevu uygulamasını **SSH ve Terminal destekli bir cPanel hosting** üzerinde online **demo/test** amacıyla kurmak içindir. Gerçek salonlarla canlı kullanım için VPS önerilir (bkz. [Bölüm 10](#10-cpanel-yeterli-mi-vps-ne-zaman-gerekir)).

> Bu ayarlarla **gerçek WhatsApp mesajı, gerçek ödeme, gerçek e-posta ve SMS gönderilmez.**
> Rehberdeki örneklerde `KULLANICI` = cPanel kullanıcı adınız, `demo.alanadiniz.com` = demo adresiniz.

---

## 1. Hosting satın almadan önce kontrol listesi

Hosting firmasına satın almadan önce şu soruları sorun; **hepsi "evet" olmalı**:

| # | Gereksinim | Neden | Nasıl doğrulanır |
|---|---|---|---|
| 1 | **PHP 8.4** (MultiPHP ile seçilebilir) | Uygulama PHP 8.2+ ister, 8.4 hedeflenir | cPanel > MultiPHP Manager |
| 2 | **MySQL 8.0.16 veya üstü** (MariaDB değil) | Veritabanı kuralları (CHECK kısıtları) MySQL 8 ile test edildi; MariaDB test edilmedi | Firmaya sorun; kurulumdan sonra `kuafor:check` gösterir |
| 3 | **GD** eklentisi (JPEG + PNG) | Logo/fotoğraf yükleme | cPanel > Select PHP Version > Extensions |
| 4 | Diğer eklentiler: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `dom`, `xml`, `ctype`, `fileinfo`, `curl` (öneri: `intl`, `zip`, `bcmath`) | Laravel çekirdeği | Aynı ekran |
| 5 | **SSH / Terminal** erişimi | Composer ve artisan komutları | cPanel > Terminal |
| 6 | **Composer** | PHP paketlerini kurmak | Terminal'de `composer --version` |
| 7 | **Git** | GitHub'dan kod çekmek | Terminal'de `git --version` |
| 8 | **Her dakika çalışan Cron** | Zamanlayıcı ve bildirim kuyruğu | Firmaya sorun ("en kısa cron aralığı 1 dakika mı?") |
| 9 | `proc_open` ve `symlink` fonksiyonları **kapalı değil** | Zamanlanmış komutlar ve görsel klasörü bağlantısı | Firmaya sorun; `kuafor:check` gösterir |
| 10 | Alt alan adı için **document root seçilebilmeli** | Sitenin kökü `public/` olmalı | cPanel > Domains |
| 11 | AutoSSL / Let's Encrypt | HTTPS | cPanel > SSL/TLS Status |

**Gerekmeyenler:** Node.js (CSS/JS dosyaları derlenmiş olarak GitHub'da gelir), Redis, Supervisor, root erişimi.

**Disk:** ~200 MB yeterli.

---

## 2. PHP ayarları

1. cPanel > **MultiPHP Manager** → `demo.alanadiniz.com` için **PHP 8.4** seçin.
2. cPanel > **Select PHP Version** (veya MultiPHP INI Editor) > **Extensions** → yukarıdaki eklentileri işaretleyin.
3. Önerilen PHP ayarları: `memory_limit = 256M`, `upload_max_filesize = 8M`, `post_max_size = 8M`, `max_execution_time = 60`.

> **Önemli:** Terminal'deki `php` komutu bazen farklı (eski) bir sürümdür. Bu rehberde PHP 8.4'ün tam yolu kullanılır:
> `/opt/cpanel/ea-php84/root/usr/bin/php`
> Terminal'de `ls /opt/cpanel/ea-php84/root/usr/bin/php` ile var olduğunu kontrol edin. Yoksa firmanızın PHP 8.4 yolunu sorun.

---

## 3. Veritabanı

cPanel > **MySQL® Databases**:

1. **Create New Database:** `kuafor` → tam adı `KULLANICI_kuafor` olur.
2. **Add New User:** `kuafor`, güçlü bir şifre (Password Generator) → `KULLANICI_kuafor`.
3. **Add User To Database:** kullanıcıyı veritabanına **ALL PRIVILEGES** ile ekleyin.

Şifreyi bir yere not edin; 6. adımda `.env` dosyasına yazacaksınız. **Bu şifreyi GitHub'a veya başka bir dosyaya koymayın.**

---

## 4. Alt alan adı ve SSL

1. cPanel > **Domains** > **Create A New Domain** → `demo.alanadiniz.com`.
2. "Share document root" seçeneğini **kapatın**, Document Root olarak **`kuafor/public`** yazın. (Klasör henüz yoksa sorun değil; 5. adımda oluşacak.)
3. cPanel > **SSL/TLS Status** → alan adını seçip **Run AutoSSL**.

> Sitenin kökü mutlaka `public` klasörü olmalı. `public_html` kullanmayın; aksi halde `.env` gibi dosyalar dışarıdan erişilebilir hale gelebilir.

---

## 5. Kodu GitHub'dan çekme

cPanel > **Terminal**:

```bash
cd ~
git clone -b main https://github.com/fehmiluker/Kuafor.git kuafor
cd kuafor
```

**Depo gizli (private) ise** önce bir okuma anahtarı (deploy key) ekleyin:

```bash
ssh-keygen -t ed25519 -C "cpanel-demo" -f ~/.ssh/kuafor_deploy -N ""
cat ~/.ssh/kuafor_deploy.pub
```

Ekrana çıkan satırı GitHub > depo > **Settings > Deploy keys > Add deploy key** bölümüne yapıştırın ("Allow write access" **işaretlemeyin**). Sonra:

```bash
cat >> ~/.ssh/config <<'CFG'
Host github-kuafor
  HostName github.com
  User git
  IdentityFile ~/.ssh/kuafor_deploy
CFG
chmod 600 ~/.ssh/config
cd ~ && git clone -b main git@github-kuafor:fehmiluker/Kuafor.git kuafor && cd kuafor
```

---

## 6. `.env` dosyası (demo ayarları)

```bash
cd ~/kuafor
cp .env.demo.example .env
nano .env
```

Sadece `<...>` ile işaretli yerleri doldurun:

| Satır | Ne yazılacak |
|---|---|
| `APP_URL=https://<demo.alanadiniz.com>` | Demo adresiniz |
| `DB_DATABASE=<cpanelkullanici_kuafor>` | `KULLANICI_kuafor` |
| `DB_USERNAME=<cpanelkullanici_kuafor>` | `KULLANICI_kuafor` |
| `DB_PASSWORD=<veritabani_sifresi>` | 3. adımdaki şifre |
| `CORS_ALLOWED_ORIGINS=https://<demo.alanadiniz.com>` | Demo adresiniz |

Kaydetmek için `Ctrl+O`, `Enter`, çıkmak için `Ctrl+X`.

Şablon zaten demo için güvenli gelir, **bu satırlara dokunmayın**:

| Ayar | Değer | Etkisi |
|---|---|---|
| `APP_ENV` | `staging` | Demo verisi oluşturulabilir (production'da oluşturulmaz) |
| `APP_DEBUG` | `false` | Hata ayrıntıları ziyaretçilere gösterilmez |
| `WHATSAPP_PROVIDER_MODE` | `fake` | **Gerçek WhatsApp mesajı gitmez** (mesajlar panelde kayıt olarak görünür) |
| `META_APP_SECRET`, `META_VERIFY_TOKEN` | boş | Meta bağlantısı yok |
| `SUBSCRIPTION_PAYMENT_PROVIDER` | `manual` | **Gerçek ödeme yok**; abonelik admin panelinden elle aktifleşir |
| `SUBSCRIPTION_WEBHOOK_SECRET` | boş | Ödeme bildirim adresi tüm istekleri reddeder |
| `MAIL_MAILER` | `log` | E-posta gönderilmez, içerik `storage/logs` içine yazılır |
| `SMS_PROVIDER` | `log` | SMS gönderilmez, doğrulama kodları log dosyasına yazılır |

> `.env` dosyası `.gitignore` içindedir ve **asla GitHub'a gönderilmez.** Sunucudan başka bir yere kopyalamayın.

---

## 7. Kurulum komutları (sırasıyla)

Tek komutla (önerilen):

```bash
cd ~/kuafor
PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php bash deploy/cpanel/install.sh
```

Betik sırasıyla şunları yapar ve sonunda `kuafor:check --demo` raporunu gösterir. Elle yapmak isterseniz aynı komutlar:

```bash
cd ~/kuafor
alias php84=/opt/cpanel/ea-php84/root/usr/bin/php

# 1) PHP paketleri (geliştirici paketleri hariç)
php84 -d memory_limit=-1 $(command -v composer) install --no-dev --optimize-autoloader --no-interaction

# 2) Uygulama anahtarı
php84 artisan key:generate --force

# 3) Klasör izinleri (dosyalara çalıştırma izni verilmez)
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +
git config core.fileMode false

# 4) Veritabanı tabloları + il/ilçe, kategori, paket verisi + DEMO salonlar
php84 artisan migrate --force --seed

# 5) Logo/fotoğraf klasörü bağlantısı
php84 artisan storage:link

# 6) Hızlandırma önbellekleri
php84 artisan optimize

# 7) Kontrol
php84 artisan kuafor:check --demo
```

### 7.1 Süper admin hesabı

```bash
php84 artisan platform:create-admin sizin@epostaniz.com --name="Adınız Soyadınız"
```

Ekranda **bir kez** gösterilen geçici parolayı güvenli bir yere kaydedin. İlk girişte telefonunuzdaki doğrulama uygulamasıyla (Google Authenticator, Microsoft Authenticator vb.) **iki adımlı doğrulama** kurmanız istenir.

---

## 8. Cron görevleri

cPanel > **Cron Jobs** > "Common Settings: **Once Per Minute (* * * * *)**" seçip **iki görev** ekleyin:

**1) Zamanlayıcı** (süresi dolan talepler, hatırlatmalar, abonelik kontrolleri):
```
/opt/cpanel/ea-php84/root/usr/bin/php /home/KULLANICI/kuafor/artisan schedule:run >> /dev/null 2>&1
```

**2) Bildirim kuyruğu** (her dakika başlar, iş bitince kapanır):
```
/opt/cpanel/ea-php84/root/usr/bin/php /home/KULLANICI/kuafor/artisan queue:work --queue=webhooks,notifications,default --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```

> `KULLANICI` yerine cPanel kullanıcı adınızı yazın (Terminal'de `echo $HOME` tam yolu gösterir).

**2–3 dakika bekleyip** kontrol edin:

```bash
php84 artisan kuafor:check --demo
```

"Zamanlayıcı ... son çalışma X dk önce" ve "Kuyruk işçisi ... son çalışma X dk önce" satırları **yeşil ✓** olmalı.

---

## 9. Demo'yu herkese kapatma (önerilir)

Demo hesaplarının şifreleri README'de yazılıdır. Demo'yu yalnızca sizin paylaştığınız kişiler görsün:

cPanel > **Directory Privacy** → `kuafor/public` klasörü → "Password protect this directory" → bir kullanıcı adı ve şifre oluşturun.

---

## 10. cPanel yeterli mi, VPS ne zaman gerekir?

| Kullanım | cPanel (bu rehber) | VPS |
|---|---|---|
| Online demo / test | ✅ Yeterli | Gerek yok |
| Birkaç gerçek salonla pilot | ⚠️ Çalışır, sınırlı | Önerilir |
| Gerçek salonlar + gerçek WhatsApp | ❌ Önerilmez | ✅ Gerekli |

cPanel'de bildirim kuyruğu cron ile dakikada bir çalıştığı için bildirimler **en fazla ~1 dakika** gecikebilir; paylaşımlı hosting'de işlem/CPU sınırları vardır. Canlı kullanımda sürekli çalışan kuyruk işçisi (Supervisor), Redis ve güvenilir webhook işleme için VPS gerekir (`docs/deployment.md`).

---

## 11. Demo verisi ve hesaplar

`migrate --seed` komutu (APP_ENV production **değilse**) şunları oluşturur: 81 il ve büyük şehirlerin ilçeleri, hizmet kategorileri, 3 paket (Standart/Profesyonel/Premium), WhatsApp şablonları (sandbox), **3 demo salon**, personel, hizmetler, her durumda randevular ve doğrulanmış yorumlar, sandbox platform WhatsApp numarası.

Demo hesapları (şifre hepsi için **`Demo12345!`**):

| Rol | E-posta | Ne görülür |
|---|---|---|
| Salon sahibi (Premium, 2 şube, manuel onay) | `sahip@isiltikuafor.test` | Tüm panel, takvim, raporlar, abonelik |
| Şube müdürü | `mudur@isiltikuafor.test` | Yalnızca Kadıköy şubesi |
| Resepsiyon | `resepsiyon@isiltikuafor.test` | Randevu ve müşteri işlemleri |
| Çalışan | `ayse@isiltikuafor.test` | Yalnızca kendi randevuları |
| Berber (Profesyonel, otomatik onay) | `sahip@modaberber.test` | Otomatik onaylı salon |
| Güzellik salonu (Standart, listelemeye kapalı) | `sahip@denizguzellik.test` | WhatsApp'sız paket |
| Müşteri | `musteri@example.test` | Randevularım, favoriler, yorumlar |

**Faydalı komutlar:**

```bash
# Demo'yu ilk haline döndür (TÜM VERİ SİLİNİR!)
php84 artisan migrate:fresh --seed --force
php84 artisan platform:create-admin sizin@epostaniz.com   # admin'i tekrar oluşturun

# Sadece demo salonlarını ekle (tablolar zaten varsa)
php84 artisan db:seed --class=DemoSeeder --force

# Gönderilmeyen e-posta / SMS kodlarını görmek (demo'da log'a yazılır)
tail -n 50 storage/logs/laravel-$(date +%Y-%m-%d).log
```

---

## 12. GitHub'dan güncelleme alma

Geliştirme `main` dalına gönderildikten sonra sunucuda:

```bash
cd ~/kuafor
PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php bash deploy/cpanel/update.sh
```

Betik sırasıyla: bakım modunu açar → `git fetch` + yalnızca ileri birleştirme (`--ff-only`) → `composer install --no-dev` → `migrate --force` → önbellekleri yeniler → `queue:restart` → bakım modunu kapatır → `kuafor:check` çalıştırır. Sunucuda elle değiştirilmiş dosya varsa güvenlik için durur.

> CSS/JS dosyaları GitHub'da derlenmiş geldiği için güncellemede **Node.js gerekmez.**
> Güncellemeden sonra `kuafor:check` zamanlayıcı/kuyruk için 1–2 dakika "henüz kayıt yok" uyarısı gösterebilir (önbellek temizlendiği için); cron'un bir sonraki çalışmasında düzelir.

---

## 13. Kurulum sonrası test listesi

Kurulumdan sonra sırayla işaretleyin. Telefondan (mobil) ve bilgisayardan deneyin.

### A. Sunucu
- [ ] `php84 artisan kuafor:check --demo` → "tüm zorunlu kontroller geçti" (✗ yok)
- [ ] 3 dakika sonra tekrar: Zamanlayıcı ✓ ve Kuyruk işçisi ✓
- [ ] `https://demo.alanadiniz.com/up` → yeşil "Application up" sayfası
- [ ] Adres çubuğunda kilit simgesi (HTTPS) var
- [ ] `https://demo.alanadiniz.com/.env` → **403 veya 404** (asla dosya içeriği görünmemeli)

### B. Ziyaretçi / müşteri (telefondan)
- [ ] Ana sayfa açılıyor, tasarım düzgün (renkler, butonlar)
- [ ] "Salon bul" → İstanbul seçince Işıltı Kuaför listeleniyor
- [ ] Işıltı Kuaför sayfası: hizmetler, fiyatlar, çalışma saatleri, yorumlar görünüyor
- [ ] "Randevu al" → şube → hizmet → "Fark etmez" → tarih/saat → bilgiler → özet → **"Talebiniz alındı"**
- [ ] Özette "Online ödeme veya kapora alınmaz" yazıyor; hiçbir yerde kart bilgisi istenmiyor
- [ ] Onay ekranındaki "Randevumu yönet" bağlantısı açılıyor; randevu iptal edilebiliyor
- [ ] Aynı saat için ikinci kez randevu alınamıyor ("saat artık müsait değil")

### C. Salon paneli (`sahip@isiltikuafor.test` / `Demo12345!`)
- [ ] Giriş → "Özet" sayfası, onay bekleyen talepler görünüyor
- [ ] B bölümünde alınan talep "Onay bekleyen" listesinde
- [ ] Talebi aç → **Onayla** → durum "Onaylandı"
- [ ] Başka bir talep → "Başka saat öner" → saat seç → durum "Alternatif saat önerildi"
- [ ] **Takvim** → Kadıköy şubesi → randevular renkli görünüyor; onaylı randevu sürüklenip taşınabiliyor
- [ ] Şubeler > Kadıköy > **WhatsApp** → "Platform numarası" ve sandbox bilgisi görünüyor
- [ ] Şubeler > WhatsApp > "Mesajlar" tablosunda bildirim kayıtları var (gerçek mesaj gitmedi)
- [ ] Hizmet ekle/düzenle, personel ekle (Premium limiti içinde), müşteri kartı açılıyor
- [ ] Ayarlar > Logo yükle → salon sayfasında logo görünüyor (`storage:link` çalışıyor)
- [ ] Raporlar sayfası açılıyor
- [ ] Abonelik sayfasında "otomatik kart tahsilatı yapmaz" bilgisi var

### D. Rol kısıtları
- [ ] `resepsiyon@...` girişinde "Ayarlar" ve "Abonelik" menüsü yok
- [ ] `ayse@...` (çalışan) yalnızca kendi randevularını görüyor
- [ ] `sahip@modaberber.test` başka salonun (Işıltı) randevularını göremiyor

### E. Admin (`platform:create-admin` ile oluşturduğunuz hesap)
- [ ] Giriş → iki adımlı doğrulama kurulumu → doğrulama kodu ile giriş
- [ ] Admin özet: MRR, işletme ve abonelik sayıları
- [ ] İşletmeler → Işıltı Kuaför → abonelik bilgileri
- [ ] Paketler → fiyat değiştir → kaydet
- [ ] Sistem → "Platform WhatsApp numarası: Bağlı" (sandbox) ve kuyruk durumu

### F. Demo güvenliği
- [ ] `kuafor:check --demo` çıktısında: "WhatsApp: kapalı / sandbox", "Ödeme: gerçek ödeme yok"
- [ ] Hiçbir test telefonuna gerçek WhatsApp/SMS gelmedi, hiçbir e-posta adresine mail gelmedi
- [ ] (Önerilen) Directory Privacy ile demo şifre korumalı

---

## 14. Sık karşılaşılan sorunlar

| Belirti | Çözüm |
|---|---|
| **500 Server Error** | `tail -n 50 storage/logs/laravel-*.log` ile hatayı görün. Çoğunlukla `.env` DB bilgileri veya izinler. Sonra `php84 artisan optimize:clear && php84 artisan optimize` |
| Sayfa tasarımsız (düz yazı) | `public/build` eksik: `git status` ve `ls public/build/assets` kontrol edin; `git pull` ile gelir |
| "Vite manifest not found" | Aynı: `public/build/manifest.json` eksik |
| Logolar görünmüyor | `php84 artisan storage:link` (hata verirse firmadan symlink iznini isteyin) |
| `composer: command not found` | Firmaya composer'ı sorun veya `curl -sS https://getcomposer.org/installer \| php84` ile `composer.phar` indirip `php84 composer.phar install --no-dev --optimize-autoloader` kullanın |
| Composer "Allowed memory size exhausted" | `php84 -d memory_limit=-1 $(command -v composer) install ...` |
| `kuafor:check` "Sunucu: ...MariaDB" ✗ | Hosting MySQL 8 değil; MySQL 8 sunan pakete geçin |
| Zamanlayıcı/kuyruk ⚠ uzun süre | Cron satırlarındaki PHP yolu ve `/home/KULLANICI/kuafor` yolu doğru mu? cPanel > Cron Jobs e-posta uyarısını açıp hatayı görün |
| `update.sh`: "elle değiştirilmiş dosyalar var" | `git status` ile dosyaları görün; sunucuda kod dosyası düzenlemeyin (`.env` hariç) |
| Admin girişinde kod kabul edilmiyor | Telefonun saati otomatik (ağdan) ayarlı olmalı |
