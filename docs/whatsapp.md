# WhatsApp Business Platform entegrasyonu

> **Durum:** Kod, resmî **Meta WhatsApp Cloud API** (`graph.facebook.com/{version}/{phone-number-id}/messages`) için yazılmıştır. Bu teslimatta **canlı Meta kimlik bilgisi yoktur**; tüm akışlar `WHATSAPP_PROVIDER_MODE=fake` (sandbox) ile ve otomatik testlerle doğrulandı. Gerçek mesaj gönderilmedi. Gayriresmî WhatsApp Web botu / QR / tarayıcı otomasyonu kullanılmaz.

## Numara modeli (hibrit)
| Sıra | Gönderici | Kurulum | Ücret kime yansır |
|---|---|---|---|
| 1 | **Şubenin kendi numarası** (bağlıysa) | Salon: panelde **"WhatsApp'ı bağla"** (Meta Embedded Signup — Facebook ile giriş, numara, SMS kodu) · veya destek ekibi admin panelinden salon adına · veya gelişmiş manuel form | Salonun Meta işletme hesabı |
| 2 | **Platform numarası** (varsayılan) | Süper admin bir kez: `Admin → Sistem → Platform WhatsApp numarası` | Platformun Meta hesabı |
| 3 | Yok → e-posta + panel | — | — |

- Kuaförün **hiçbir ayar yapmasına gerek yoktur**; paketinde WhatsApp varsa bildirimler platform numarasından gider. Salona yönelik ve müşteriye giden tüm şablonlarda işletme adı yer alır (ör. "Işıltı Kuaför – Kadıköy").
- Seçim `ConnectionService::senderFor()` içinde yapılır; `WHATSAPP_PLATFORM_FALLBACK=false` ile platform numarası kapatılabilir.
- **Platform numarasında tenant ayrımı:** platform bağlantısının tenant'ı yoktur. Gelen buton yanıtında tenant, tek kullanımlık **action token**'dan çözülür; token (1) gönderenin numarasına (HMAC), (2) taşındığı bağlantıya (`connection_id`) bağlıdır. Böylece başka bir salonun yöneticisine iletilmiş bir buton veya başka numaradan basılan buton çalışmaz. Durum güncellemeleri ilgili mesaj satırının tenant'ı ile işlenir; token'sız gelen mesajlar tenant'sız kaydedilir ve otomatik işlem yapılmaz.
- Salon kendi numarasını bağladığında yeni bildirimler o numaradan gider; platform üzerinden gönderilmiş eski butonlar yalnızca platform numarasında geçerli kalır.

## Bileşenler
| Sınıf | Görev |
|---|---|
| `WhatsAppClientInterface` | Tek arayüz: `sendTemplate`, `sendInteractive`, `sendText`, `health`, `subscribeWebhooks` |
| `MetaCloudApiClient` | Gerçek Graph API istemcisi (timeout, hata kodu ayrıştırma) |
| `FakeWhatsAppClient` | Sandbox: ağ yok, gönderilenleri kaydeder; hata enjeksiyonu |
| `ConnectionService` | Şube ↔ numara bağlama; canlı sağlık kontrolü sonrası `connected`; iptalde token silinir |
| `TemplateCatalog` + `WhatsAppTemplateSeeder` | Platform UTILITY şablonları, Türkçe metinler, quick-reply butonlar |
| `WhatsAppSender` | Mesaj satırı (idempotency anahtarı) + `SendWhatsAppMessage` işi; 24 saat pencere kontrolü |
| `SendWhatsAppMessage` | Satırı "claim" eder, bir kez gönderir; retry/backoff 30s→2dk→10dk→30dk; kalıcı hatada `failed` + e-posta/panel fallback; token hatasında bağlantı `error` |
| `WebhookVerifier` | GET challenge, POST `X-Hub-Signature-256` (ham gövde, `hash_equals`) |
| `WebhookIngestor` | Olayları ayırır, `provider_event_key` ile dedupe, şifreli saklar, kuyruğa atar, hızlı 200 |
| `ProcessWhatsAppWebhook` | Tek işleyici claim, retry/backoff, tükenince `failed_jobs` (dead letter) |
| `InboundMessageHandler` | Durum güncellemeleri (geriye gitmez), buton/liste yanıtları |

## Şube bağlantısı (Embedded Signup)
1. Platform bir kez **Meta Tech Provider** olur: Meta Developer'da uygulama (WhatsApp ürünü), işletme doğrulaması, izinler (`whatsapp_business_management`, `whatsapp_business_messaging`), **App Review**, ve "Facebook Login for Business → Configurations" altında WhatsApp Embedded Signup yapılandırması (`META_EMBEDDED_SIGNUP_CONFIG_ID`).
2. Salon `Şubeler → WhatsApp → "WhatsApp'ı bağla"` düğmesine basar. FB JS SDK (`FB.login`, `response_type=code`) Meta penceresini açar; pencere seçilen `waba_id` ve `phone_number_id`'yi `postMessage` (`WA_EMBEDDED_SIGNUP`) ile bildirir.
3. Tarayıcı `code` + kimlikleri `POST /panel/subeler/{id}/whatsapp/embedded` (API: `POST /api/v1/tenant/branches/{id}/whatsapp/embedded-signup`) adresine gönderir. Sunucu kodu `GET /oauth/access_token` ile token'a çevirir, numarayı `POST /{phone_number_id}/register` ile rastgele PIN'le Cloud API'ye kaydeder (PIN şifreli saklanır) ve aşağıdaki doğrulamaya geçer.
4. Sandbox modunda düğme bağlantıyı simüle eder; canlı modda yapılandırma eksikse salona "platform numarası kullanılıyor" bilgisi gösterilir.
5. `ConnectionService` numarayı Graph API'den sorgular (`display_phone_number`, `quality_rating`), başarılıysa `subscribed_apps` ile webhook aboneliği yapar ve `connected` olarak işaretler. Token `encrypted` cast ile şifreli saklanır, hiçbir yanıtta/logda gösterilmez.
4. Mevcut WhatsApp Business **uygulaması** ile aynı numarayı kullanma ("coexistence") yalnızca Meta'nın o numara/ülke için resmî olarak desteklediği koşullarda mümkündür; evrensel garanti verilmez.

## Mesaj akışları
| Olay | Alıcı | Kanal |
|---|---|---|
| Talep alındı (manuel mod) | Müşteri | Şablon `randevu_talebi_alindi` + yönetim linki |
| Yeni talep | Şube yöneticileri | Şablon `salon_yeni_talep` + **[Onayla] [Başka Saat Öner]** quick-reply; ayrıca panel bildirimi + e-postayla imzalı panel linki |
| Onaylandı / değişti / iptal / reddedildi / süresi doldu | Müşteri | İlgili şablon |
| Alternatif saat | Müşteri | Şablon `randevu_alternatif_teklif` + **[Kabul Et] [Reddet]**; e-postada web teklif sayfası linki |
| Teklif sonucu, müşteri iptali | Şube yöneticileri | `salon_randevu_guncelleme` + panel |
| Hatırlatma | Müşteri | `randevu_hatirlatma` (ayarlanabilir saat önce, durum yeniden kontrol, bir kez) |
| Değerlendirme daveti | Müşteri | `degerlendirme_daveti` + süreli, yalnızca `review` kapsamlı link (tamamlandıktan 2 saat sonra) |

### Salonun WhatsApp'tan alternatif saat seçmesi
1. Yönetici **Başka Saat Öner**'e basar → gelen buton mesajı 24 saatlik pencereyi açar.
2. Sistem **gerçek müsait slotlardan** (en fazla 10, 7 gün) etkileşimli **liste mesajı** gönderir; her satır tek kullanımlık token.
3. Seçilen satır → `AppointmentService::propose` (kilit + yeniden kontrol, teklif slotu TTL boyunca tutulur) → müşteriye teklif şablonu.
4. Müşteri **Kabul Et** → kilit + yeniden kontrol → `confirmed`. **Reddet** → teklif serbest, randevu `rejected`.

## Buton güvenliği
- Payload yalnızca `a:{40 karakter rastgele}`; PII/ID içermez. DB'de SHA-256 hash (`action_tokens`).
- Token: amaç, tenant, randevu/teklif, **izinli alıcı numarasının HMAC'i**, son kullanma; atomik tek kullanım (`UPDATE … WHERE used_at IS NULL`).
- Webhook'u alan bağlantının tenant'ı ile token tenant'ı eşleşmezse reddedilir; farklı numaradan basılan buton reddedilir.
- Bir eylem tamamlanınca kardeş tokenlar geçersizleştirilir (ör. onaydan sonra "Başka Saat Öner").

## Aynı numara sınırlaması
Bir numara kendine mesaj gönderemez / kendi butonlarını alamaz. Bu yüzden **yönetici bildirim numaraları şube gönderici numarasından farklı olmalıdır**; aynı olan numaralar otomatik atlanır ve yönetici panel bildirimi + e-postadaki imzalı link (giriş + yetki yine gerekir) ile işlem yapar.

## Şablon ve 24 saat kuralları
- İşletme başlatmalı mesajlar yalnızca **APPROVED** şablonlarla gönderilir. Şablon onaylı değilse e-posta/panel fallback kullanılır.
- Serbest metin/etkileşimli mesaj yalnızca kişinin son gelen mesajından sonraki 24 saat içinde gönderilir (`WhatsAppSender::insideServiceWindow`); dışarıdaysa gönderilmez.
- Müşteriye WhatsApp yalnızca rezervasyonda alınan **WhatsApp bildirim izni** varsa gönderilir (`communication_consents`); pazarlama ayrı izin ve ayrı kategori (bu sürümde kampanya gönderimi yok).

## Webhook
- `GET /webhooks/meta/whatsapp`: `hub.verify_token` = `META_VERIFY_TOKEN` ise challenge.
- `POST /webhooks/meta/whatsapp`: imza → JSON → olay başına `provider_event_key` (`msg:{wamid}`, `status:{wamid}:{status}:{ts}`) → tekrarlar atılır → kuyruk → `200`. Gelen mesajlar `whatsapp_messages.provider_message_id` UNIQUE ile ikinci kez işlenmez.
- Durumlar `sent < delivered < read` sırasıyla yalnızca ileri gider; `failed` her zaman işlenir ve fallback tetikler.

## Saklama ve PII
Webhook gövdeleri şifreli saklanır, `WHATSAPP_WEBHOOK_RETENTION_DAYS` (30) sonra silinir; mesaj satırlarında alıcı numarası 180 gün sonra maskelenir (`PurgeExpiredData`). Mesaj satırında yalnızca şablon adı/parametre sayısı tutulur.

## Ücretlendirme
Meta konuşma/mesaj ücretleri ve BSP ücretleri **koda gömülmez**: `WHATSAPP_PRICE_UTILITY`, `WHATSAPP_PRICE_MARKETING`, `WHATSAPP_BSP_FEE` (.env) ile girilir ve admin "Sistem" sayfasında gösterilir. Güncel fiyatları Meta'nın resmî fiyat sayfasından/sözleşmenizden alın.

## Canlıya alma kontrol listesi
- [ ] **Platform numarası**: Meta'da platform WABA'sı + numara + system user token; `Admin → Sistem`'den bağlandı ve "Bağlı" görünüyor; Meta hesabında ödeme yöntemi tanımlı
- [ ] Tech Provider kaydı ve Embedded Signup yapılandırması (`META_EMBEDDED_SIGNUP_CONFIG_ID`), uygulama alan adı Meta'da izinli
- [ ] Meta Business doğrulaması, WABA uygunluğu, App Review ve izinler
- [ ] Her şube numarası WABA'ya kayıtlı, görünen ad onaylı
- [ ] `META_APP_ID`, `META_APP_SECRET`, `META_VERIFY_TOKEN`, `META_GRAPH_API_VERSION` ayarlı; `WHATSAPP_PROVIDER_MODE=meta`
- [ ] Webhook URL `https://alanadi/webhooks/meta/whatsapp` Meta'da doğrulandı, `messages` alanına abone
- [ ] 11 şablon Meta'ya gönderildi ve onaylandı (`whatsapp_templates.approval_status=APPROVED`, isimler `TemplateCatalog` ile aynı)
- [ ] Yönetici numaraları gönderici numarasından farklı
- [ ] Test numarasıyla uçtan uca: talep → Onayla; talep → Başka Saat Öner → liste → müşteri Kabul Et
- [ ] Kuyruk worker'ı `notifications,webhooks` kuyruklarını dinliyor; `failed_jobs` alarmı kurulu
