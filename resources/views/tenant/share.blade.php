@extends('layouts.panel')
@section('title', 'Müşteri bağlantım')
@section('content')
@unless($listed)
    <p class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" role="note">Salonunuz aramada listelenmiyor; bağlantınız yine de çalışır. Listelemeyi <a href="{{ route('tenant.settings') }}" class="font-semibold underline">Ayarlar</a>'dan açabilirsiniz.</p>
@endunless
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <section class="card p-5" aria-labelledby="link-h" x-data="{ copied: false, copy(t) { navigator.clipboard.writeText(t).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000) }) } }">
            <h2 id="link-h" class="text-lg font-bold">Randevu bağlantınız</h2>
            <p class="mt-1 text-sm text-slate-600">Müşterileriniz bu bağlantıyla doğrudan randevu ekranınıza gelir. Bağlantı kalıcıdır; Instagram profilinize, WhatsApp durumunuza, Google işletme profilinize ve kartvizitinize ekleyin.</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <label for="share-url" class="sr-only">Randevu bağlantısı</label>
                <input id="share-url" value="{{ $url }}" readonly class="input min-w-0 flex-1 font-mono text-sm" @focus="$el.select()">
                <button type="button" class="btn-primary" @click="copy(@js($url))"><span x-show="!copied">Kopyala</span><span x-show="copied" x-cloak>Kopyalandı ✓</span></button>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="https://wa.me/?text={{ rawurlencode($whatsappText) }}" target="_blank" rel="noopener" class="btn-secondary">WhatsApp'ta paylaş</a>
                <button type="button" class="btn-secondary" @click="copy(@js($whatsappText))">Hazır mesajı kopyala</button>
                <a href="{{ $url }}" target="_blank" rel="noopener" class="btn-ghost">Bağlantıyı dene ↗</a>
            </div>
            <p class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">Hazır mesaj: “{{ $whatsappText }}”</p>
        </section>

        @if($branchUrls->isNotEmpty())
        <section class="card p-5" aria-labelledby="branch-h">
            <h2 id="branch-h" class="text-lg font-bold">Şubelere özel bağlantılar</h2>
            <p class="mt-1 text-sm text-slate-600">Şube seçimini atlayıp doğrudan o şubenin randevu ekranını açar.</p>
            <ul class="mt-3 divide-y divide-slate-100">
                @foreach($branches as $b)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                        <span class="font-medium">{{ $b->name }}</span>
                        <span class="flex flex-wrap items-center gap-2"><code class="break-all text-xs text-slate-600">{{ $branchUrls[$b->id] }}</code>
                            <a href="{{ route('tenant.share.qr', ['sube' => $b->id]) }}" class="btn-ghost btn-sm">QR indir</a>
                            <a href="{{ route('tenant.share.print', ['sube' => $b->id]) }}" target="_blank" class="btn-ghost btn-sm">Yazdır</a></span>
                    </li>
                @endforeach
            </ul>
        </section>
        @endif

        <section class="card p-5" aria-labelledby="stats-h">
            <h2 id="stats-h" class="text-lg font-bold">Son 30 gün</h2>
            <div class="table-wrap mt-3"><table class="table text-sm">
                <thead><tr><th>Kaynak</th><th class="text-right">Ziyaret</th><th class="text-right">Online randevu</th></tr></thead>
                <tbody>
                @foreach($stats as $s)<tr><td>{{ $s['label'] }}</td><td class="text-right tabular-nums">{{ $s['visits'] }}</td><td class="text-right tabular-nums">{{ $s['bookings'] }}</td></tr>@endforeach
                <tr class="font-semibold"><td>Toplam</td><td class="text-right tabular-nums">{{ collect($stats)->sum('visits') }}</td><td class="text-right tabular-nums">{{ collect($stats)->sum('bookings') }}</td></tr>
                </tbody>
            </table></div>
            <p class="mt-2 text-xs text-slate-500">Bir ziyaretçi aynı oturumda bir kez sayılır. E-posta: müşterilere giden randevu e-postalarındaki "Yeni randevu al" bağlantısı.</p>
        </section>
    </div>

    <section class="card self-start p-5 text-center" aria-labelledby="qr-h">
        <h2 id="qr-h" class="text-lg font-bold">QR kod</h2>
        <p class="mt-1 text-sm text-slate-600">Tezgâha, aynaya veya kartvizite koyun; müşteri telefon kamerasıyla okutup randevu alır.</p>
        <div id="qr-box" class="mx-auto mt-3 w-60 rounded-xl bg-white p-2 ring-1 ring-slate-200 [&>svg]:h-auto [&>svg]:w-full" role="img" aria-label="{{ $tenant->name }} randevu QR kodu">{!! $qr !!}</div>
        <div class="mt-4 flex flex-col gap-2">
            <a href="{{ route('tenant.share.print') }}" target="_blank" class="btn-primary">Masa kartını yazdır</a>
            <button type="button" class="btn-secondary" onclick="kuaforQrPng(@js($tenant->slug.'-randevu-qr.png'))">PNG indir (Instagram, WhatsApp)</button>
            <a href="{{ route('tenant.share.qr') }}" class="btn-ghost">SVG indir (matbaa için)</a>
        </div>
    </section>
</div>
@endsection
@push('scripts')
<script>
function kuaforQrPng(name) {
    const svg = document.querySelector('#qr-box svg');
    const img = new Image();
    img.onload = () => {
        const size = 1024, c = document.createElement('canvas');
        c.width = c.height = size;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
        ctx.drawImage(img, 0, 0, size, size);
        c.toBlob(b => { const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = name; a.click(); URL.revokeObjectURL(a.href); });
    };
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg));
}
</script>
@endpush
