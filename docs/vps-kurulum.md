# VPS kurulum rehberi (Hostinger VPS — demo)

Bu rehber uygulamayı **Hostinger VPS** (veya herhangi bir Ubuntu 22.04/24.04 sunucu) üzerine **tek komutla demo olarak** kurar. Gerçek WhatsApp, ödeme ve e-posta **kapalıdır**; demo salonlar ve hesaplar yüklenir.

Betik şunları kurar ve ayarlar: Nginx, PHP 8.4 (FPM), MySQL 8, Composer, kuyruk işçisi (Supervisor), zamanlayıcı (cron), güvenlik duvarı (ufw), isteğe bağlı HTTPS (Let's Encrypt) ve site şifresi.

---

## 1. Sunucuyu hazırlayın (hPanel)
1. **hPanel > VPS > İşletim Sistemi**: **Ubuntu 24.04** (eklentisiz, "Plain OS") seçin. Panelli şablonlar (CyberPanel, CloudPanel, Coolify…) yerine düz Ubuntu kullanın; betik her şeyi kendisi kurar.
   > Sunucuda başka bir site/uygulama varsa betiği **çalıştırmayın**: varsayılan Nginx sitesini değiştirir.
2. **Root şifresi** belirleyin ve sunucunun **IP adresini** not edin.
3. **Alan adı (önerilir):** Alan adınızın DNS ayarlarında bir **A kaydı** ekleyin, ör. `demo.alanadiniz.com → VPS IP`. Yayılması birkaç dakika sürebilir. Alan adı olmadan da kurulur (IP ile, HTTPS'siz).

## 2. Sunucuya bağlanın
- hPanel > VPS > **Tarayıcı terminali** (en kolayı), veya
- Bilgisayarınızdan: `ssh root@SUNUCU_IP`

## 3. Kurulum komutu
Aşağıdaki iki satırı terminale yapıştırın; `demo.alanadiniz.com`, e-posta ve site şifresini kendinizinkilerle değiştirin:

```bash
curl -fsSL https://raw.githubusercontent.com/fehmiluker/Kuafor/main/deploy/vps/install.sh -o install.sh
DOMAIN=demo.alanadiniz.com EMAIL=siz@eposta.com DEMO_PASS='Guclu-Bir-Sifre' bash install.sh
```

| Ayar | Ne işe yarar |
|---|---|
| `DOMAIN` | Alan adınız. Boş bırakılırsa site `http://SUNUCU_IP` adresinde açılır. |
| `EMAIL` | HTTPS sertifikası için. `DOMAIN` ile birlikte verilirse site `https://` olur. |
| `DEMO_PASS` | Siteyi tarayıcı şifresiyle korur (kullanıcı adı `demo`). **Önerilir:** demo hesap şifreleri README'de açık. |

Kurulum 5–10 dakika sürer. Sonunda adres ve bir kontrol raporu (`kuafor:check --demo`) görünür. Veritabanı şifresi `/root/kuafor-kurulum-bilgileri.txt` dosyasına yazılır.

> Alan adı yoksa: `DEMO_PASS='Guclu-Bir-Sifre' bash install.sh`

## 4. Yönetici hesabı
```bash
cd /var/www/kuafor && sudo -u www-data php artisan platform:create-admin siz@eposta.com --name="Adınız"
```
Ekranda **bir kez** gösterilen parolayı saklayın; ilk girişte iki adımlı doğrulama (Google Authenticator vb.) istenir.

## 5. Kontrol
- Tarayıcıda adresi açın → site şifresi (`demo` / `DEMO_PASS`) → ana sayfa.
- Demo hesapları (şifre `Demo12345!`): `sahip@isiltikuafor.test` (salon sahibi), `musteri@example.test` (müşteri) — tam liste README'de.
- 2–3 dakika sonra: `cd /var/www/kuafor && sudo -u www-data php artisan kuafor:check --demo` → Zamanlayıcı ✓ ve Kuyruk işçisi ✓.
- Ayrıntılı test listesi: [cpanel-kurulum.md § 13](cpanel-kurulum.md#13-kurulum-sonrası-test-listesi) (aynı maddeler VPS için de geçerli).

## 6. Güncelleme
GitHub'daki `main` dalına yeni sürüm geldiğinde:
```bash
bash /var/www/kuafor/deploy/vps/update.sh
```
Bakım modunu açar, kodu çeker, paketleri ve veritabanı değişikliklerini uygular, kuyruğu yeniden başlatır.

## 7. Sık karşılaşılan sorunlar
| Belirti | Çözüm |
|---|---|
| Sertifika alınamadı | DNS A kaydı henüz yayılmamış. Bekleyip: `certbot --nginx -d demo.alanadiniz.com --redirect`, sonra `.env` içinde `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true` ve `cd /var/www/kuafor && sudo -u www-data php artisan optimize` |
| Giriş yapınca tekrar giriş sayfası | HTTPS yokken `SESSION_SECURE_COOKIE=true` olmuş. `.env`'de `false` yapıp `php artisan optimize` |
| 500 hatası | `tail -n 50 /var/www/kuafor/storage/logs/laravel-*.log` |
| Kuyruk ⚠ | `supervisorctl status` ; log: `/var/log/kuafor/worker.log` |
| Site şifresini değiştirmek | `htpasswd -b /etc/nginx/kuafor.htpasswd demo 'YeniSifre' && systemctl reload nginx` |

## Canlıya geçiş (gerçek salonlar)
Demo yerine gerçek kullanım için `.env`'de `APP_ENV=production`, gerçek e-posta (SMTP), PayTR ([paytr.md](paytr.md)), WhatsApp ([whatsapp.md](whatsapp.md)) ve yedekleme ([deployment.md](deployment.md#yedekleme-ve-geri-yükleme)) ayarlanmalıdır. `APP_ENV=production` iken demo verisi oluşturulmaz.
