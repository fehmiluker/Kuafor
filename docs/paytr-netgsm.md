# PayTR (abonelik ödemesi) ve Netgsm (SMS) kurulumu

Bu iki entegrasyon yalnızca **salon sahiplerinin platforma ödediği abonelik** ve **telefon doğrulama SMS'leri** içindir. Müşterilerden randevu için online ödeme/kapora alınmaz (ADR-010).

Varsayılan ayarlarla ikisi de **kapalıdır** (`SUBSCRIPTION_PAYMENT_PROVIDER=manual`, `SMS_PROVIDER=log`). Açmak için yalnızca `.env` değişir; kod değişmez.

---

## 1. PayTR — kartla abonelik ödemesi

### Nasıl çalışır
1. Salon sahibi **Panel > Abonelik > "Kartla öde"** düğmesine basar (yalnızca `subscriptions.manage` yetkisi olanlar, yani işletme sahibi).
2. Sunucu PayTR'dan imzalı bir ödeme oturumu (token) alır; tutar **sunucuda** belirlenir (mevcut paket fiyatı, planlanmış paket değişikliği varsa yeni paketin fiyatı).
3. Ödeme PayTR'ın kendi sayfasında (iFrame) yapılır; **kart bilgisi bizim sunucumuza hiç gelmez**.
4. PayTR sonucu **Bildirim URL**'ine gönderir. Sunucu imzayı (hash) doğrular, tutarı kontrol eder, aboneliği bir dönem uzatır, faturayı "ödendi" yapar ve PayTR'a `OK` döner.
5. Aynı bildirim tekrar gelirse ikinci kez işlem yapılmaz (idempotent).

Otomatik/tekrarlayan kart çekimi **yoktur**: her dönem salon sahibi tarafından ayrıca ödenir. Süresi dolan abonelik mevcut akışla `past_due → grace_period → suspended` olur.

### PayTR mağaza panelinde
1. **Destek & Kurulum > Entegrasyon Bilgileri**: Mağaza No (`merchant_id`), Mağaza Parola (`merchant_key`), Mağaza Gizli Anahtar (`merchant_salt`).
2. **Destek & Kurulum > Ayarlar > Bildirim URL**:
   ```
   https://ALAN-ADINIZ/webhooks/subscription-payments/paytr
   ```
   HTTPS zorunludur. Bu adres oturum/CSRF kullanmaz; her istek PayTR imzasıyla doğrulanır.
3. Mağaza **test modundayken** test kartlarıyla deneyin (test kart bilgileri PayTR panelindedir).

### `.env`
```dotenv
SUBSCRIPTION_PAYMENT_PROVIDER=paytr
PAYTR_MERCHANT_ID=xxxxxx
PAYTR_MERCHANT_KEY=xxxxxxxxxxxxxxxx
PAYTR_MERCHANT_SALT=xxxxxxxxxxxxxxxx
PAYTR_TEST_MODE=true        # canlıya geçerken false
# PAYTR_TIMEOUT_MINUTES=30  # ödeme oturumu süresi
```
Sonra: `php artisan optimize:clear && php artisan optimize && php artisan migrate --force` ve `php artisan kuafor:check` ("PayTR mağaza bilgileri tanımlı" ✓ ve Bildirim URL satırı).

### Canlıya almadan önce test listesi
- [ ] Test modunda başarılı ödeme → Abonelik sayfasında durum **Aktif**, dönem sonu bir ay/yıl ileri, faturalar listesinde **paid**.
- [ ] Test modunda başarısız kart → durum değişmez, "Ödeme tamamlanamadı" mesajı.
- [ ] PayTR panelinde işlemin bildirim durumu **başarılı** (Bildirim URL'ine ulaşıldı, `OK` alındı).
- [ ] `storage/logs` içinde `[paytr] ... refused` / `amount` uyarısı yok.
- [ ] `PAYTR_TEST_MODE=false`, `php artisan optimize`, küçük tutarlı gerçek bir ödeme, gerekirse PayTR panelinden iade.

> **Doğrulama notu:** İstek imzası (`paytr_token`) PayTR'ın resmî iFrame API PHP örneğindeki alan sırasıyla oluşturulur: `merchant_id + user_ip + merchant_oid + email + payment_amount + user_basket + no_installment + max_installment + currency + test_mode`, ardından `merchant_salt` eklenip `merchant_key` ile HMAC-SHA256 ve base64. Geliştirme ortamından dev.paytr.com'a erişilemediği için bu sıra resmî dokümanla canlı olarak karşılaştırılamadı; **ilk test ödemesi bunu doğrular** (yanlışsa PayTR `paytr_token` hatası döner ve log'a yazılır).

---

## 2. Netgsm — SMS (telefon doğrulama kodları)

### Netgsm panelinde
1. **Abonelik Bilgileri > API İşlemleri**: API kullanıcısı oluşturun (ana hesap yerine **alt kullanıcı** önerilir) ve API erişimini açın.
2. **IP kısıtlaması** kullanıyorsanız sunucunuzun çıkış IP'sini ekleyin (aksi halde kod `30` döner).
3. **Mesaj başlığı** (gönderici adı) onaylı olmalı, ör. `KUAFORRNDV`.
4. (İsteğe bağlı) Doğrulama kodları için **OTP SMS paketi**: daha hızlı teslim; Türkçe karakterler otomatik sadeleştirilir.

### `.env`
```dotenv
SMS_PROVIDER=netgsm
NETGSM_USERCODE=850xxxxxxx
NETGSM_PASSWORD=********
NETGSM_MSGHEADER=KUAFORRNDV
NETGSM_OTP=false   # OTP paketi aldıysanız true
```
Kontrol: `php artisan kuafor:check` → "Netgsm bilgileri tanımlı" ✓. Sonra **Hesabım > Telefon doğrulama** ile kendi numaranıza kod isteyin.

### Hata kodları (log'da `[sms:netgsm] rejected`)
| Kod | Anlamı | Çözüm |
|---|---|---|
| 20 | Mesaj metni hatalı/uzun | — |
| 30 | Kullanıcı/şifre hatalı, API yetkisi yok veya IP kısıtı | Panelde API yetkisi ve IP listesi |
| 40 / 41 | Mesaj başlığı tanımlı değil / geçersiz | `NETGSM_MSGHEADER` onaylı başlıkla aynı olmalı |
| 60 | OTP paketi/kredisi yok | `NETGSM_OTP=false` veya paket alın |
| 70 | Eksik/hatalı parametre | — |
| 80 / 85 | Gönderim sınırı / aynı numaraya tekrar sınırı | Bekleyin |

Kullanıcıya yalnızca "SMS gönderilemedi, lütfen tekrar deneyin" gösterilir; ayrıntı log'dadır (telefon numarası maskelenir).

Bu SMS'ler **bilgilendirme** (doğrulama kodu) niteliğindedir, ticari ileti değildir; İYS filtresi uygulanmaz. Kampanya/pazarlama SMS'i eklenirse İYS onayı ayrıca ele alınmalıdır.
