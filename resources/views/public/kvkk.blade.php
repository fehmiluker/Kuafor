@extends('layouts.public')
@section('title', 'KVKK Aydınlatma Metni')
@section('content')
<article class="card prose max-w-3xl p-6 text-sm leading-relaxed">
    <h1 class="text-xl font-bold">Kişisel Verilerin Korunması Aydınlatma Metni</h1>
    <p class="mt-2 rounded bg-amber-50 p-3 text-amber-900">Bu metin bir şablondur; yayına almadan önce hukuk danışmanınız tarafından işletmenize göre güncellenmelidir.</p>
    <h2 class="mt-4 font-semibold">Veri sorumlusu</h2>
    <p>Randevu aldığınız salon, randevu ve müşteri kayıtları bakımından veri sorumlusudur. Platform işletmecisi, salonlar adına veri işleyen ve kendi hesap/üyelik süreçleri bakımından veri sorumlusu sıfatıyla hareket eder.</p>
    <h2 class="mt-4 font-semibold">İşlenen veriler ve amaçlar</h2>
    <p>Ad-soyad, telefon, (varsa) e-posta, randevu tarih/saat ve hizmet bilgileri; randevunun oluşturulması, yönetilmesi, size randevu bildirimlerinin iletilmesi ve hizmetin ifası amacıyla işlenir (KVKK m.5/2-c sözleşmenin ifası). Pazarlama amaçlı ileti yalnızca ayrıca vereceğiniz açık izin/onay ile gönderilir.</p>
    <p class="mt-2">Adrese hizmet (ev/işyeri) randevularında verdiğiniz açık adres yalnızca o randevu için salonun yetkili personeline gösterilir, şifreli saklanır ve randevudan {{ config('booking.home_address_retention_days') }} gün sonra silinir; istatistik için yalnızca ilçe bilgisi tutulur.</p>
    <h2 class="mt-4 font-semibold">Aktarım</h2>
    <p>Bildirimler ve telefon doğrulama kodları için WhatsApp Business Platform (Meta) ve e-posta hizmet sağlayıcıları kullanılabilir (SMS kullanılmaz); bu sağlayıcıların bir kısmı yurt dışında bulunabilir. Yurt dışı aktarımın hukuki dayanağı hukuk danışmanınızla değerlendirilmelidir.</p>
    <h2 class="mt-4 font-semibold">Çerezler</h2>
    <p>Yalnızca hizmetin çalışması için gerekli çerezler kullanılır; reklam veya takip çerezi yoktur. Oturum ve güvenlik çerezlerine ek olarak seçtiğiniz konum (<code>konum</code>) ve bu cihazdan son randevu aldığınız salon ile seçtiğiniz hizmet/personel (<code>son_salon</code>, "Tekrar randevu al" için, 1 yıl) tarayıcınızda saklanır. Tarayıcı ayarlarınızdan silebilirsiniz.</p>
    <h2 class="mt-4 font-semibold">Haklarınız</h2>
    <p>KVKK m.11 kapsamındaki haklarınızı hesabınızdaki "Veri talebi" bölümünden veya salonla iletişime geçerek kullanabilirsiniz.</p>
</article>
@endsection
