@extends('layouts.panel')
@section('title', 'Ayarlar')
@section('content')
<form method="POST" action="{{ route('tenant.settings.save') }}" class="card max-w-3xl space-y-4 p-5">@csrf
    <h2 class="font-semibold">İşletme</h2>
    <x-input name="name" label="İşletme adı" :value="$tenant->name" required />
    <x-input type="textarea" name="description" label="Tanıtım metni" :value="$tenant->description" />
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="public_listing_enabled" value="0"><input type="checkbox" name="public_listing_enabled" value="1" @checked($tenant->public_listing_enabled)> Platform aramasında listelensin (kapalıyken doğrudan bağlantınız çalışmaya devam eder)</label>
    @if($customTheme)<x-input name="primary_color" label="Tema rengi" type="color" :value="$tenant->settings['theme']['primary_color'] ?? '#2f55c7'" />@endif

    <h2 class="pt-4 font-semibold">Randevu kuralları</h2>
    <div class="grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="st-mode">Onay modu</label><select id="st-mode" name="approval_mode" class="input"><option value="manual" @selected($booking->approvalMode->value === 'manual')>Manuel — salon onaylar</option><option value="automatic" @selected($booking->approvalMode->value === 'automatic')>Otomatik — anında onaylanır</option></select></div>
        <div><label class="label" for="st-slot">Slot aralığı</label><select id="st-slot" name="slot_interval_minutes" class="input">@foreach([5,10,15,20,30,60] as $m)<option value="{{ $m }}" @selected($booking->slotIntervalMinutes === $m)>{{ $m }} dk</option>@endforeach</select></div>
        <x-input name="min_notice_minutes" label="En erken randevu (dk sonra)" type="number" :value="$booking->minNoticeMinutes" required />
        <x-input name="max_horizon_days" label="En ileri randevu (gün)" type="number" :value="$booking->maxHorizonDays" required />
        <x-input name="cancellation_cutoff_minutes" label="Online iptal son süresi (dk önce)" type="number" :value="$booking->cancellationCutoffMinutes" required />
        <x-input name="pending_ttl_minutes" label="Onay bekleme süresi (dk)" type="number" :value="$booking->pendingTtlMinutes" help="Süre dolarsa talep otomatik düşer, saat serbest kalır." required />
        <x-input name="proposal_ttl_minutes" label="Alternatif saat teklifinin geçerliliği (dk)" type="number" :value="$booking->proposalTtlMinutes" required />
        <x-input name="reminder_hours_before" label="Hatırlatma (saat önce)" type="number" :value="$booking->reminderHoursBefore" required />
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="require_phone_verification" value="0"><input type="checkbox" name="require_phone_verification" value="1" @checked($booking->requirePhoneVerification)> Üye olmayan müşterilerden WhatsApp ile telefon doğrulaması iste</label>
    <button class="btn-primary">Kaydet</button>
</form>

<section class="card mt-6 max-w-3xl p-5">
    <h2 class="font-semibold">Logo, kapak ve galeri</h2>
    <form method="POST" action="{{ route('tenant.settings.media') }}" enctype="multipart/form-data" class="mt-3 grid gap-2 sm:grid-cols-4">@csrf
        <select name="kind" class="input" aria-label="Görsel türü"><option value="logo">Logo</option><option value="cover">Kapak</option><option value="gallery">Galeri</option></select>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="input sm:col-span-2" required aria-label="Görsel">
        <button class="btn-secondary">Yükle</button>
        <input name="alt_text" class="input sm:col-span-4" placeholder="Görsel açıklaması (erişilebilirlik için)" aria-label="Alternatif metin">
    </form>
    <p class="mt-2 text-xs text-slate-500">JPEG/PNG/WebP, en fazla 4 MB. Görseller yeniden işlenir ve meta verileri temizlenir.</p>
    <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-6">@foreach($media as $m)<img src="{{ $m->url() }}" alt="{{ $m->alt_text }}" class="aspect-square rounded object-cover">@endforeach</div>
</section>
@endsection
