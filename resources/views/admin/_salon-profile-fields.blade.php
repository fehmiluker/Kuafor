<fieldset class="space-y-4 rounded-xl border border-slate-200 p-4">
    <legend class="px-2 font-semibold">İşletme Görselleri</legend>
    @foreach(['cover' => ['Kapak / üst banner', 'cover_path'], 'logo' => ['Logo', 'logo_path']] as $field => [$label, $column])
    <div>
        <label class="label" for="profile-{{ $field }}">{{ $label }}</label>
        @if($profileTenant?->{$column})
            <img src="{{ Storage::disk('public')->url($profileTenant->{$column}) }}" alt="{{ $label }}" class="mb-2 h-28 max-w-full rounded-lg object-cover">
            <label class="mb-2 block text-sm"><input type="checkbox" name="remove_{{ $field }}" value="1"> Mevcut görseli kaldır</label>
        @endif
        <input id="profile-{{ $field }}" class="input" type="file" name="{{ $field }}" accept="image/jpeg,image/png,image/webp">
    </div>
    @endforeach
    <div>
        <label class="label" for="profile-gallery">İşletmenin iç mekân fotoğrafları</label>
        <input id="profile-gallery" class="input" type="file" name="gallery[]" multiple accept="image/jpeg,image/png,image/webp">
        <p class="mt-1 text-sm text-slate-500">Toplam en fazla 12 fotoğraf. Her görsel JPG, PNG veya WebP, en fazla 4 MB ve en az 100 × 100 piksel olmalı.</p>
    </div>
    @if($profileMedia->isNotEmpty())
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach($profileMedia as $photo)
        <label class="rounded-lg border border-slate-200 p-2 text-sm">
            <img src="{{ $photo->url() }}" alt="{{ $photo->alt_text }}" class="mb-2 aspect-square w-full rounded object-cover">
            <input type="checkbox" name="remove_media[]" value="{{ $photo->id }}"> Fotoğrafı sil
        </label>
        @endforeach
    </div>
    @endif
</fieldset>
<fieldset class="space-y-4 rounded-xl border border-slate-200 p-4">
    <legend class="px-2 font-semibold">İletişim ve Sosyal Medya</legend>
    <div><label class="label" for="profile-phone">Müşterilere gösterilecek telefon</label><input id="profile-phone" class="input" name="contact_phone" type="tel" placeholder="+905321234567" value="{{ old('contact_phone', $branch?->phone) }}"></div>
    <div><label class="label" for="profile-whatsapp">WhatsApp numarası</label><input id="profile-whatsapp" class="input" name="whatsapp_phone" type="tel" placeholder="+905321234567" value="{{ old('whatsapp_phone', $contact['whatsapp_phone'] ?? '') }}"><p class="mt-1 text-sm text-slate-500">Müşterinin işletmeye yazması içindir. Otomatik randevu bildirimi bağlantısından ayrıdır.</p></div>
    <div><label class="label" for="profile-instagram">Instagram profil bağlantısı</label><input id="profile-instagram" class="input" name="instagram_url" type="url" placeholder="https://www.instagram.com/isletmeniz/" value="{{ old('instagram_url', $contact['instagram_url'] ?? '') }}"></div>
    <div><label class="label" for="profile-facebook">Facebook sayfa bağlantısı</label><input id="profile-facebook" class="input" name="facebook_url" type="url" placeholder="https://www.facebook.com/isletmeniz/" value="{{ old('facebook_url', $contact['facebook_url'] ?? '') }}"></div>
    <p class="text-sm text-slate-500">Boş bırakılan sosyal medya ve WhatsApp alanları işletme sayfasında gösterilmez.</p>
</fieldset>
