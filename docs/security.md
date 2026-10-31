# Güvenlik ve KVKK

## Yetkilendirme modeli
Her istekte üç katman: (1) **Policy** (izin + kaydın tenant'ı = bağlam + şube ataması + çalışan kısıtı), (2) **sorgu kapsamı** (`TenantScope` fail-closed, `AccessScope::scopeAppointments/scopeBranches`), (3) **servis** düzeyi kontroller (ör. `AppointmentService` geçişleri, `BelongsToCurrentTenant` kuralı). Arayüzde gizleme tek koruma değildir. FormRequest'ler `authorize()` ile doğrulamadan önce yetki kontrol eder.

### Rol → izin matrisi (`app/Support/Permissions.php`)
| İzin | owner | admin | branch_manager* | receptionist* | employee** |
|---|---|---|---|---|---|
| branches.manage | ✓ | ✓ | | | |
| employees.manage | ✓ | ✓ | ✓ | | |
| services.manage | ✓ | ✓ | | | |
| appointments.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| appointments.create / approve / reschedule / cancel | ✓ | ✓ | ✓ | ✓ | |
| appointments.complete | ✓ | ✓ | ✓ | ✓ | ✓ |
| customers.view / manage | ✓ | ✓ | ✓ | ✓ | |
| reports.view | ✓ | ✓ | ✓ | | |
| whatsapp.manage | ✓ | ✓ | ✓ | | |
| reviews.reply | ✓ | ✓ | ✓ | | |
| settings.manage | ✓ | ✓ | | | |
| subscriptions.manage | ✓ | | | | |

\* yalnızca `branch_user` ile atanmış şubeler. \** yalnızca kendisine atanmış randevular.
Platform: `super_admin` (tüm platform izinleri), `platform_support` (`tenants.view`, `reviews.moderate`, `audit.view` — PII, paket ve faturalama yok), `customer`.
Süper admin tenant verisine otomatik erişmez (`Gate::before` bypass yok); müşteri PII erişimi `pii.access`, zorunlu gerekçe, 50 kayıt sınırı ve denetim kaydıyla.

## Kimlik doğrulama
- Şifre: bcrypt, min. 10 karakter + harf + rakam; giriş başarısında oturum yenileme; şifre sıfırlamada tüm API tokenları iptal.
- Platform personeli: **TOTP MFA zorunlu** (web oturumu `two_factor_passed`; API token'ı `platform` yeteneği yalnızca TOTP ile verilir, joker `*` yetmez, 1 gün geçerli).
- Kullanıcı numaralandırması yok (aynı hata, şifre sıfırlamada hep aynı yanıt).
- Rate limit: auth, OTP, rezervasyon, misafir, webhook (bkz. `docs/api.md`).
- OTP: 6 hane, HMAC hash saklanır, 10 dk, kod başına 5 deneme, numara başına saatte 5 gönderim, IP limiti.
- Misafir: 48 karakter rastgele token, yalnızca SHA-256 hash, scope + referans bağlı, 30 gün, iptal edilebilir.

## Uygulama güvenliği
| Tehdit | Önlem |
|---|---|
| SQL injection | Eloquent/bound query; LIKE girdilerinde `%`/`_` kaçışı |
| XSS | Blade `{{ }}`; yorum/not girdilerinde `strip_tags`; JSON-LD `JSON_HEX_TAG` |
| CSRF | Web formları token'lı; webhook'lar CSRF dışı ama imzalı |
| IDOR | Tenant scope + bağlama önce tenant çözümleme → başka tenant kaydı 404 |
| Toplu atama | Durum/para/version alanları guarded; servisler `forceFill` ile açık alan yazar; `tenant_id` hook ile zorlanır |
| Dosya yükleme | mime + boyut + boyut sınırı + `getimagesize`; GD ile yeniden kodlama (meta/payload temizlenir), rastgele 40 karakter ad, SVG kabul edilmez |
| Başlıklar | CSP, `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy, HSTS (HTTPS) |
| Özel sayfalar | `X-Robots-Tag: noindex`, `Cache-Control: no-store`; robots.txt'de kapalı |
| Webhook sahteciliği | HMAC-SHA256 ham gövde, `hash_equals`; dedupe; replay zararsız |
| Sırlar | WhatsApp token, TOTP secret, vergi bilgisi, CRM notları `encrypted` cast; serileştirmede gizli; audit log'da redakte |
| Çift rezervasyon | Kilit + yeniden kontrol + DB kısıtları (bkz. decisions.md) |
| Spam | Honeypot, IP limitleri, telefon başına bekleyen talep limiti, isteğe bağlı OTP, engelli müşteri durumu |

**Bilinen sınır:** CSP `script-src 'unsafe-inline' 'unsafe-eval'` içerir (Livewire/Alpine). Sertleştirme: nonce + Alpine CSP derlemesi.

## Denetim izi
`audit_logs` (yalnızca ekleme; model update/delete engelli): aktör, tenant, işlem, hedef, önce/sonra (redakte), gerekçe, IP HMAC. Kayıtlı olaylar: şube/personel değişiklikleri, ayarlar, müşteri kartı görüntüleme, WhatsApp bağlama/kaldırma, admin işletme/paket/abonelik işlemleri, PII erişimi, yorum moderasyonu, MFA etkinleştirme. Randevu geçişleri ayrıca `appointment_status_histories`'te.

## KVKK
> ⚠️ Aşağıdakiler teknik tasarım varsayımlarıdır; **hukuk danışmanı tarafından doğrulanmalıdır.** `resources/views/public/kvkk.blade.php` ve kullanım koşulları **şablondur**.

| Konu | Tasarım | Hukuki doğrulama gereken nokta |
|---|---|---|
| Roller | Randevu/CRM verisi için salon **veri sorumlusu**, platform **veri işleyen**; hesap/üyelik ve platform güvenliği için platform veri sorumlusu | Rollerin dağılımı ve **veri işleme sözleşmesi** (salon–platform) |
| Hukuki dayanak | Randevu oluşturma ve operasyonel bildirimler: **sözleşmenin ifası (m.5/2-c)**; güvenlik/denetim kayıtları: meşru menfaat / hukuki yükümlülük | Her ileti türü için dayanak — operasyonel bildirim için **açık rıza genellemesi yapılmadı** |
| WhatsApp bildirim izni | Rezervasyonda ayrı onay kutusu, kanıtıyla saklanır (`communication_consents`) | Meta politikası gereği opt-in; KVKK açısından ayrı rıza gerekip gerekmediği |
| Ticari elektronik ileti | Ayrı, varsayılan kapalı onay; `marketing` kategorisi ayrı; bu sürümde kampanya gönderimi yok | 6563 sayılı Kanun, **İYS kaydı** ve ret hakkı |
| Yurt dışına aktarım | Meta (WhatsApp), e-posta/SMS sağlayıcıları yurt dışında olabilir | KVKK m.9 (2024 değişikliği) kapsamında dayanak (standart sözleşme vb.) ve **alt işleyen listesi** |
| Veri sahibi hakları | Hesapta JSON veri indirme; export/delete/rectify talepleri `data_subject_requests` ile admin'e (30 gün) | Başvuru usulü, kimlik doğrulama, yanıt süresi |
| Saklama | Webhook 30 gün, mesaj alıcısı 180 gün sonra maskeleme, OTP 7 gün, token'lar süresi + 30 gün | Randevu/fatura kayıtları için yasal saklama süreleri (VUK vb.) ve imha politikası |
| Minimizasyon | Yorumlarda "Ad S." gösterimi; buton payload'larında PII yok; loglarda maskeli numara | — |
| Özel nitelikli veri | Toplanmaz; not alanlarına sağlık verisi girilmemesi için personel eğitimi önerilir | Cilt/estetik hizmetlerinde sağlık verisi riski |

## Sır yönetimi ve rotasyon
- `.env` web kökü dışında, `600` izin; üretimde secret manager önerilir.
- `APP_KEY` rotasyonu: Laravel 11+ `APP_PREVIOUS_KEYS` ile şifreli sütunlar okunmaya devam eder; ardından yeniden şifreleme işi çalıştırılmalı.
- `META_APP_SECRET` rotasyonunda webhook imzası yeni secret'la doğrulanır (kısa süreli kesinti planlayın).
- Şube erişim token'ı iptalinde `disconnect` token'ı siler.

## Yedek şifreleme
Bkz. `docs/deployment.md` — `mysqldump | gzip | gpg --symmetric` / age, anahtarlar ayrı saklanır.
