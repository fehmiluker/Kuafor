@extends('layouts.panel')
@section('title', $branch->name.' · WhatsApp')
@section('content')
<div class="grid gap-6 lg:grid-cols-2">
    <section class="card space-y-3 p-5">
        <h2 class="font-semibold">Bağlantı durumu</h2>
        @if($connection)
            <p class="text-sm">Durum: <strong>{{ $connection->status->label() }}</strong> · Numara: {{ $connection->display_phone ?? '—' }}</p>
            <p class="text-xs text-slate-500">WABA: {{ $connection->waba_id }} · Phone number ID: {{ $connection->phone_number_id }} · Son kontrol: {{ $connection->last_health_check_at?->format('d.m.Y H:i') ?? '—' }}</p>
            @if($connection->last_error)<p class="rounded bg-red-50 p-2 text-sm text-red-800">{{ $connection->last_error }}</p>@endif
            @if($connection->status->value !== 'disconnected')
                <form method="POST" action="{{ route('tenant.branches.whatsapp.disconnect', $branch) }}" onsubmit="return confirm('Bağlantı kaldırılsın mı? Erişim anahtarı silinir.')">@csrf @method('DELETE')<button class="btn-danger btn-sm">Bağlantıyı kaldır</button></form>
            @endif
        @else
            <p class="text-sm text-slate-600">Bu şube henüz bir WhatsApp Business numarasına bağlı değil. Bildirimler panel ve e-posta ile devam eder.</p>
        @endif
        <p class="text-xs text-slate-500">Mod: <strong>{{ config('whatsapp.mode') === 'meta' ? 'Canlı (Meta Cloud API)' : 'Sandbox (gerçek mesaj gönderilmez)' }}</strong></p>
        <p class="text-xs text-slate-500">Bildirim alacak yönetici numaraları: {{ $managerNumbers ? implode(', ', $managerNumbers) : 'tanımlı değil' }} (<a href="{{ route('tenant.branches.edit', $branch) }}" class="underline">düzenle</a>)</p>
    </section>
    <form method="POST" action="{{ route('tenant.branches.whatsapp.connect', $branch) }}" class="card space-y-3 p-5">@csrf
        <h2 class="font-semibold">Numara bağla</h2>
        <p class="text-sm text-slate-600">Meta Embedded Signup sonrası elde edilen bilgileri girin. Numara Meta tarafında bu işletmenin WhatsApp Business hesabına (WABA) kayıtlı ve onaylı olmalıdır. Mevcut WhatsApp Business uygulaması ile birlikte kullanım yalnızca Meta'nın o numara için desteklediği koşullarda mümkündür.</p>
        <x-input name="waba_id" label="WhatsApp Business Account ID" inputmode="numeric" required />
        <x-input name="phone_number_id" label="Phone number ID" inputmode="numeric" required />
        <x-input name="display_phone" label="Görünen numara" type="tel" />
        <x-input name="access_token" label="Erişim anahtarı (system user token)" type="password" autocomplete="off" required help="Şifreli saklanır, hiçbir ekranda veya logda gösterilmez." />
        <button class="btn-primary">Doğrula ve bağla</button>
    </form>
</div>
<section class="card mt-6 p-5"><h2 class="font-semibold">Son mesajlar</h2>
    <div class="table-wrap mt-3"><table class="table"><thead><tr><th>Zaman</th><th>Yön</th><th>Tür</th><th>Olay</th><th>Alıcı</th><th>Durum</th></tr></thead><tbody>
        @forelse($messages as $m)<tr><td>{{ $m->created_at->format('d.m H:i') }}</td><td>{{ $m->direction === 'outbound' ? 'Giden' : 'Gelen' }}</td><td>{{ $m->message_type }}</td><td>{{ $m->event_type }}</td><td>{{ \App\Support\PhoneNumber::mask($m->recipient_e164) }}</td><td>{{ $m->status->value }} @if($m->error_code)<span class="text-xs text-red-600">({{ $m->error_code }})</span>@endif</td></tr>
        @empty<tr><td colspan="6" class="text-center text-slate-500">Mesaj yok.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
