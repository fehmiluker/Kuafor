@extends('layouts.panel')
@section('title', $branch->name.' · WhatsApp')
@section('content')
@php
    $own = $connection && $connection->status->value !== 'disconnected' ? $connection : null;
    $live = $embedded['mode'] === 'meta' && $embedded['app_id'] && $embedded['config_id'];
@endphp

<section class="card p-5" aria-labelledby="wa-status">
    <h2 id="wa-status" class="font-semibold">Bildirimler hangi numaradan gidiyor?</h2>
    @if($sender && ! $sender->is_platform)
        <p class="mt-2 text-sm"><span class="badge bg-emerald-50 text-emerald-700">Kendi numaranız</span> {{ $sender->display_phone ?? $sender->phone_number_id }}</p>
        <p class="mt-1 text-sm text-slate-600">Müşterileriniz onay, hatırlatma ve saat önerilerini doğrudan sizin işletme numaranızdan alıyor.</p>
    @elseif($sender && $sender->is_platform)
        <p class="mt-2 text-sm"><span class="badge bg-brand-50 text-brand-700">Platform numarası</span> {{ $sender->display_phone }}</p>
        <p class="mt-1 text-sm text-slate-600">Herhangi bir ayar yapmanıza gerek yok. Bildirimler platform numarasından, mesajın içinde <strong>{{ app(\App\Services\Tenancy\TenantContext::class)->tenant()->name }}</strong> adıyla gönderiliyor. İsterseniz aşağıdan kendi numaranızı bağlayabilirsiniz.</p>
    @else
        <p class="mt-2 text-sm text-slate-600">Şu anda WhatsApp bildirimi gönderilmiyor; bildirimler e-posta ve panel üzerinden devam ediyor.</p>
    @endif
    @if($own && $own->last_error)<p class="mt-2 rounded bg-red-50 p-2 text-sm text-red-800">{{ $own->last_error }}</p>@endif
    <p class="mt-3 text-xs text-slate-500">Talepleri WhatsApp'tan onaylayacak yönetici numaraları: {{ $managerNumbers ? implode(', ', $managerNumbers) : 'tanımlı değil' }} (<a href="{{ route('tenant.branches.edit', $branch) }}" class="underline">düzenle</a>)</p>
</section>

@if($own)
    <section class="card mt-6 space-y-2 p-5">
        <h2 class="font-semibold">Kendi numaranız</h2>
        <p class="text-sm">Durum: <strong>{{ $own->status->label() }}</strong> · {{ $own->display_phone ?? '—' }} · Bağlanma: {{ ['embedded_signup' => 'WhatsApp ile bağlan', 'admin' => 'Destek ekibi', 'manual' => 'Manuel'][$own->onboarding_method] ?? $own->onboarding_method }}</p>
        <p class="text-xs text-slate-500">Son kontrol: {{ $own->last_health_check_at?->format('d.m.Y H:i') ?? '—' }}</p>
        <form method="POST" action="{{ route('tenant.branches.whatsapp.disconnect', $branch) }}" onsubmit="return confirm('Kendi numaranızın bağlantısı kaldırılsın mı? Bildirimler platform numarasından devam eder.')">@csrf @method('DELETE')<button class="btn-secondary btn-sm">Bağlantıyı kaldır</button></form>
    </section>
@else
    <section class="card mt-6 space-y-3 p-5" aria-labelledby="wa-connect">
        <h2 id="wa-connect" class="font-semibold">Kendi işletme numaranızı bağlayın (isteğe bağlı)</h2>
        <ol class="list-inside list-decimal space-y-1 text-sm text-slate-600">
            <li>Aşağıdaki düğmeye basın, Facebook hesabınızla giriş yapın.</li>
            <li>İşletmenizi seçin veya oluşturun, kullanmak istediğiniz numarayı girin.</li>
            <li>Numaranıza gelen SMS kodunu yazın. Gerisini sistem otomatik tamamlar.</li>
        </ol>
        <p class="text-xs text-slate-500">Not: Bağladığınız numara, Meta'nın desteklediği durumlar dışında telefonunuzdaki WhatsApp uygulamasında aynı anda kullanılamaz. Mesaj ücretleri Meta tarafından işletme hesabınıza yansıtılır.</p>

        @if($live)
            <button type="button" id="wa-es-button" class="btn-primary">WhatsApp'ı bağla</button>
            <p id="wa-es-status" class="text-sm text-slate-600" role="status" aria-live="polite"></p>
            <script>
                (function () {
                    let session = null;
                    window.fbAsyncInit = function () {
                        FB.init({ appId: @json($embedded['app_id']), autoLogAppEvents: true, xfbml: false, version: @json($embedded['graph_version']) });
                    };
                    // Meta's popup reports the chosen WABA and phone number via postMessage.
                    window.addEventListener('message', function (event) {
                        if (!/(^|\.)facebook\.com$/.test(new URL(event.origin).hostname)) return;
                        try {
                            const msg = JSON.parse(event.data);
                            if (msg.type === 'WA_EMBEDDED_SIGNUP' && msg.event && msg.event.startsWith('FINISH')) session = msg.data;
                        } catch (e) {}
                    });
                    const status = document.getElementById('wa-es-status');
                    document.getElementById('wa-es-button').addEventListener('click', function () {
                        if (typeof FB === 'undefined') { status.textContent = 'Facebook bağlantısı yüklenemedi, sayfayı yenileyin.'; return; }
                        FB.login(function (response) {
                            const code = response.authResponse && response.authResponse.code;
                            if (!code) { status.textContent = 'Bağlantı iptal edildi.'; return; }
                            const send = function () {
                                if (!session) { setTimeout(send, 300); return; }
                                status.textContent = 'Numaranız bağlanıyor…';
                                fetch(@json(route('tenant.branches.whatsapp.embedded', $branch)), {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                    body: JSON.stringify({ code: code, waba_id: session.waba_id, phone_number_id: session.phone_number_id }),
                                }).then(function (r) { return r.json().then(function (b) { return [r.ok, b]; }); })
                                  .then(function ([ok, b]) { status.textContent = ok ? b.message : (b.message || 'Bağlantı tamamlanamadı.'); if (ok) setTimeout(function () { location.reload(); }, 1500); });
                            };
                            send();
                        }, { config_id: @json($embedded['config_id']), response_type: 'code', override_default_response_type: true,
                             extras: { setup: {}, featureType: '', sessionInfoVersion: '3' } });
                    });
                })();
            </script>
            <script async defer crossorigin="anonymous" src="https://connect.facebook.net/tr_TR/sdk.js"></script>
        @elseif($embedded['mode'] !== 'meta')
            <form method="POST" action="{{ route('tenant.branches.whatsapp.embedded', $branch) }}">@csrf
                <input type="hidden" name="code" value="sandbox-{{ \Illuminate\Support\Str::random(16) }}">
                <input type="hidden" name="waba_id" value="{{ random_int(10000000000, 99999999999) }}">
                <input type="hidden" name="phone_number_id" value="{{ random_int(10000000000, 99999999999) }}">
                <button class="btn-primary">WhatsApp'ı bağla (sandbox simülasyonu)</button>
            </form>
            <p class="text-xs text-amber-700">Sandbox modundasınız: gerçek Meta penceresi açılmaz, bağlantı simüle edilir.</p>
        @else
            <p class="rounded bg-amber-50 p-3 text-sm text-amber-900">"WhatsApp'ı bağla" henüz etkin değil (platform yöneticisinin Meta yapılandırmasını tamamlaması gerekiyor). Bu sürede bildirimler platform numarasından gider.</p>
        @endif

        <details class="pt-2 text-sm">
            <summary class="cursor-pointer text-slate-500">Gelişmiş: bilgileri elle gir</summary>
            <form method="POST" action="{{ route('tenant.branches.whatsapp.connect', $branch) }}" class="mt-3 space-y-3">@csrf
                <x-input name="waba_id" label="WhatsApp Business Account ID" inputmode="numeric" required />
                <x-input name="phone_number_id" label="Phone number ID" inputmode="numeric" required />
                <x-input name="display_phone" label="Görünen numara" type="tel" />
                <x-input name="access_token" label="Erişim anahtarı" type="password" autocomplete="off" required help="Şifreli saklanır, hiçbir ekranda gösterilmez." />
                <button class="btn-secondary">Doğrula ve bağla</button>
            </form>
        </details>
    </section>
@endif

<section class="card mt-6 p-5"><h2 class="font-semibold">Son mesajlar</h2>
    <div class="table-wrap mt-3"><table class="table"><thead><tr><th>Zaman</th><th>Yön</th><th>Numara</th><th>Olay</th><th>Alıcı</th><th>Durum</th></tr></thead><tbody>
        @forelse($messages as $m)<tr><td>{{ $m->created_at->format('d.m H:i') }}</td><td>{{ $m->direction === 'outbound' ? 'Giden' : 'Gelen' }}</td><td>{{ $m->connection?->is_platform ? 'Platform' : 'Kendi' }}</td><td>{{ $m->event_type }}</td><td>{{ \App\Support\PhoneNumber::mask($m->recipient_e164) }}</td><td>{{ $m->status->value }} @if($m->error_code)<span class="text-xs text-red-600">({{ $m->error_code }})</span>@endif</td></tr>
        @empty<tr><td colspan="6" class="text-center text-slate-500">Mesaj yok.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
