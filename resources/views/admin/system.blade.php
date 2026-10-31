@extends('layouts.admin')
@section('title', 'Sistem ve mesajlaşma durumu')
@section('content')
<section class="card mb-6 p-5" aria-labelledby="pn-h">
    <h2 id="pn-h" class="font-semibold">Platform WhatsApp numarası</h2>
    <p class="mt-1 text-sm text-slate-600">Kendi numarasını bağlamamış tüm salonların bildirimleri bu numaradan, mesajda salon adıyla gider. Ücretler platformun Meta hesabına yansır.</p>
    @if($platform && $platform->status->value !== 'disconnected')
        <p class="mt-3 text-sm">Durum: <strong>{{ $platform->status->label() }}</strong> · {{ $platform->display_phone }} · WABA {{ $platform->waba_id }} · Son kontrol {{ $platform->last_health_check_at?->format('d.m.Y H:i') }}</p>
        @if($platform->last_error)<p class="mt-1 text-sm text-red-700">{{ $platform->last_error }}</p>@endif
        @if($canManage)<form method="POST" action="{{ route('admin.whatsapp.platform.disconnect') }}" class="mt-2" onsubmit="return confirm('Platform numarası devre dışı bırakılsın mı?')">@csrf @method('DELETE')<button class="btn-danger btn-sm">Devre dışı bırak</button></form>@endif
    @else
        <p class="mt-3 text-sm text-amber-700">Platform numarası bağlı değil: kendi numarası olmayan salonlar WhatsApp bildirimi gönderemiyor (e-posta/panel devam ediyor).</p>
    @endif
    @if($canManage)
        <form method="POST" action="{{ route('admin.whatsapp.platform') }}" class="mt-4 grid gap-2 sm:grid-cols-2">@csrf
            <x-input name="waba_id" label="WABA ID" id="p_waba" required />
            <x-input name="phone_number_id" label="Phone number ID" id="p_pnid" required />
            <x-input name="display_phone" label="Görünen numara" id="p_disp" />
            <x-input name="access_token" label="System user erişim anahtarı" type="password" id="p_tok" autocomplete="off" required />
            <button class="btn-primary sm:col-span-2">{{ $platform ? 'Güncelle ve doğrula' : 'Bağla ve doğrula' }}</button>
        </form>
    @endif
</section>
<div class="grid gap-4 lg:grid-cols-3">
    <section class="card p-5"><h2 class="font-semibold">WhatsApp</h2><p class="mt-1 text-sm">Mod: <strong>{{ $mode === 'meta' ? 'Canlı (Meta Cloud API)' : 'Sandbox/fake' }}</strong></p>
        @foreach($connections as $s => $c)<p class="text-sm">{{ $s }}: {{ $c }}</p>@endforeach</section>
    <section class="card p-5"><h2 class="font-semibold">Son 24 saat</h2><p class="text-sm font-medium">Webhook</p>@foreach($webhooks as $s => $c)<p class="text-sm">{{ $s }}: {{ $c }}</p>@endforeach
        <p class="mt-2 text-sm font-medium">Mesajlar</p>@foreach($messages as $s => $c)<p class="text-sm">{{ $s }}: {{ $c }}</p>@endforeach</section>
    <section class="card p-5"><h2 class="font-semibold">Kuyruk</h2><p class="text-sm">Bekleyen iş: {{ $jobs }}</p><p class="text-sm">Başarısız iş: {{ $failedJobs->count() }}</p></section>
</div>
<section class="card mt-6 p-5"><h2 class="font-semibold">Sağlayıcı ücretleri (yapılandırma)</h2>
    <p class="text-sm text-slate-600">Ücretler koda gömülü değildir; Meta/BSP sözleşmenize göre .env üzerinden girilir.</p>
    <pre class="mt-2 rounded bg-slate-50 p-3 text-xs">{{ json_encode($pricing, JSON_PRETTY_PRINT) }}</pre></section>
<section class="card mt-6 p-5"><h2 class="font-semibold">Son başarısız işler (dead letter)</h2>
    @forelse($failedJobs as $f)<p class="border-b border-slate-100 py-1 text-xs">{{ $f->failed_at }} · {{ $f->queue }} · {{ \Illuminate\Support\Str::limit(strtok($f->exception, "\n"), 160) }}</p>@empty<p class="text-sm text-slate-500">Yok.</p>@endforelse
</section>
@endsection
