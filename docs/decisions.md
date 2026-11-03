# Mimari kararlar (ADR) ve açık konular

## ADR-001 Tek veritabanı, `tenant_id` + fail-closed global scope + bileşik FK
Ayrı şema/DB yerine tek DB (operasyon ve arama basitliği). Riskler üç katmanla azaltıldı: fail-closed `TenantScope`, `BelongsToTenant` yazma kancaları, DB'de `(tenant_id, x_id)` bileşik FK'ler. Çapraz tenant kod yolları açıkça `asPlatform()` kullanır.

## ADR-002 Çift rezervasyonu önleme: `resource_day_locks`
MySQL'de exclusion constraint yok; mevcut randevuları `FOR UPDATE` ile kilitlemek "boş aralık" yarışını önlemez. Seçilen yöntem:
1. İşlemden **önce** `(tenant, employee, yerel gün)` kilit satırlarını `INSERT IGNORE` ile garanti et (işlem içinde yapıldığında eşzamanlı ilk rezervasyonlarda InnoDB duplicate-key deadlock'u gözlendi — testle tespit edildi ve bu yüzden dışarı alındı).
2. İşlem içinde satırları **sıralı** (`resource_id, local_date`) `SELECT … FOR UPDATE` ile kilitle; gün aşan/taşıma işlemlerinde tüm günler (eski + yeni) sıralı.
3. Kilitten sonra müsaitliği **taze** sorgula ve yaz.
4. `READ COMMITTED`: REPEATABLE READ'de anlık görüntü kilitten önce alınmışsa eşzamanlı commit görülmeyebilirdi.
5. Deadlock/lock-wait'te `DB::transaction(..., 3)` yeniden dener; idempotency anahtarı ve UNIQUE kısıtları ikinci savunma hattı.
Alternatif `appointment_slot_allocations` (slot başına satır) değerlendirildi; değişken süreli hizmetler ve tamponlarla satır sayısı patladığı için reddedildi.

## ADR-003 Hold modeli
Onaylı randevular satırın kendisiyle (`starts_at_utc`–`blocked_until_utc`) engeller. Bekleyen talepler ve açık teklifler `booking_holds` ile **süreli** engeller; süresi geçen hold, süpürme işi çalışmasa bile engellemez. Onay anında slot yeniden kontrol edilir (hold süresi dolmuş ve slot başkasına verilmişse 409).

## ADR-004 Tampon hesabı
Çoklu hizmette tampon = en büyük hizmet tamponu + personel tamponu (aynı ziyaret içinde koltuk bir kez hazırlanır). Hizmet bitişi çalışma saati içinde olmalı; tampon çalışma saatinin sonuna taşabilir ama başka randevu/izin/kapalı zamanla çakışamaz. Mola yalnızca hizmet süresiyle kontrol edilir.

## ADR-005 "Fark etmez" seçimi
Aynı slot için tüm uygun personel eşit erkendir; sıralama: o yerel günde en az aktif randevusu olan → en küçük id. Seçilen personel kilit altında sabitlenir; kaybederse sıradaki denenir.

## ADR-006 Alternatif teklif reddi
Müşteri teklifi reddederse randevu `rejected` olur (salon zaten ilk saati veremedi). Salon yeni öneri için müşteriyle iletişime geçebilir veya müşteri yeni talep oluşturur. Teklif süresi dolarsa randevu `expired`.

## ADR-007 Abonelik hakları: snapshot
Abonelik oluşturma/yenilemede plan limitleri ve özellikleri `entitlements_snapshot`'a kopyalanır. Plan düzenlemesi mevcut aboneleri dönem sonuna kadar etkilemez. Yükseltme anında, düşürme dönem başında uygulanır. Düşürmede veri silinmez; fazlalık "pasifleştirin" uyarısıyla gösterilir, yeni aktif kayıt limitle engellenir.

## ADR-008 Müşteri kimliği: global hesap ≠ tenant CRM kartı
`users` (global hesap) ile `tenant_customers` (işletmeye özel kart) ayrı. Tenant başına telefon başına tek kart (birleştirme politikası). Hesap ile kart bağlantısı yalnızca **OTP ile telefon doğrulandıktan sonra** kurulur.

## ADR-009 Bildirim dedupe ve olay kaydı
Olaylar `ShouldDispatchAfterCommit`; dinleyici kuyrukta ve tenant bağlamında çalışır. Laravel olay keşfi kapalı (açıkken abone sınıfı iki kez kaydoluyor ve her bildirim çift gidiyordu — testte yakalandı). Her teslimat deterministik anahtar (olay + randevu + `version` + alıcı) ile tekildir; tekrar denemeler çift mesaj üretmez.

## ADR-010 Ödeme
Müşteriden çevrimiçi ödeme/kapora yok; `appointment_payments` yalnızca kayıt. SaaS aboneliği `PaymentGatewayInterface`; sağlanan tek uygulama `manual` (yönetici aktivasyonu + imzalı dahili webhook) ve `supportsAutomaticCharging() = false` — UI otomatik tahsilat varmış gibi göstermez.

## ADR-011 Actions klasörü yerine servisler
Önerilen `app/Actions/*` yerine iş akışları `AppointmentService` içinde (tek kilit sırası ve geçiş kuralları tek yerde). Ayrı action sınıfları ek soyutlama getirmediği için oluşturulmadı.

## ADR-012 Tarih nesneleri
`Date::use(CarbonImmutable::class)`: rezervasyon zamanlarının yanlışlıkla yerinde değiştirilmesini önler.

## ADR-013 Hibrit WhatsApp numara modeli
Kuaförlerin Meta kurulumu yapması gerçekçi değil. Varsayılan: platformun tek numarası (sıfır kurulum, ücret platformda, mesajda işletme adı). İsteğe bağlı: şubenin kendi numarası — Embedded Signup (tek tık), admin destekli kurulum veya manuel form. Platform bağlantısı tenant'sız olduğundan gelen olaylarda tenant, gönderen numarası ve bağlantıya bağlı tek kullanımlık token'dan çözülür. Bu, ilk talimattaki "her şube kendi numarasından" kuralını varsayılan olmaktan çıkarır; ürün sahibinin kararıdır (C seçeneği).

## ADR-014 PayTR iFrame
Abonelik ödemesi PayTR iFrame API ile: kart verisi platforma hiç gelmez (PCI kapsamı dışında kalır). Tutar ödeme başlatılırken `subscription_checkouts` kaydına sabitlenir; aboneliği yalnızca imzası doğrulanan PayTR bildirimi etkinleştirir ve bildirimdeki tutar bu tutardan düşükse etkinleştirmez. Kart saklama/tekrarlayan çekim kullanılmadı (ek sözleşme ve iptal/iade süreçleri gerektirir); her dönem sahibi öder. (Güncelleme: SMS/Netgsm kaldırıldı — telefon doğrulama kodları WhatsApp kimlik doğrulama şablonuyla platform numarasından gider, bkz. ADR-015.)


## ADR-015 Telefon doğrulama yalnızca WhatsApp ile (SMS yok)
Doğrulama kodları (adrese hizmet, salonun istediği doğrulama, hesapta telefon doğrulama) platform WhatsApp numarasından Meta **AUTHENTICATION** kategorisindeki `dogrulama_kodu` şablonuyla ("Kodu kopyala" düğmeli) gönderilir; SMS sağlayıcısı yoktur. Gönderim kuyruğa alınmaz, senkron yapılır (kod beklememeli). Platform numarası bağlı değilse veya şablon onaylı değilse doğrulama istenen akışlar `otp_unavailable` hatası verir; telefonu daha önce doğrulanmış üyeler etkilenmez. Kod veritabanında yalnızca HMAC olarak, mesaj kaydında hiç tutulmaz; fake modda yalnızca log'a yazılır.

## Açık konular / sonraki adımlar
1. Şablonların Meta'ya API ile gönderimi ve onay durumunun otomatik senkronizasyonu (şu an seeder + elle güncelleme). Embedded Signup canlı Meta hesabıyla henüz denenmedi (sandbox ile test edildi).
2. PayTR adaptörü eklendi (ADR-014); gerçek mağazayla test ödemesi yapılmalı. Tekrarlayan kart çekimi (PayTR kart saklama) ileride değerlendirilebilir.
3. WhatsApp Flows ile zengin tarih/saat seçici (şu an liste mesajı + web linki).
4. KVKK metinleri, VDS ve İYS süreçlerinin hukukçu tarafından tamamlanması; veri silme taleplerinin otomatik anonimleştirme işi.
5. İngilizce dil dosyaları (altyapı `locale` ve `SetLocale` ile hazır; metinler şu an Blade'de Türkçe).
6. Otomatik erişilebilirlik (axe) ve yük testleri; CSP'nin nonce ile sıkılaştırılması.
7. Kaynak tipi olarak personel dışında kıt kaynak (oda/koltuk) — `resource_day_locks.resource_type` buna hazır, müsaitlik henüz yalnızca personel.
8. Takvimde personel sütunlu kaynak görünümü (FullCalendar Resource eklentisi ticari lisanslı olduğu için şimdilik personel filtresi kullanılıyor).
