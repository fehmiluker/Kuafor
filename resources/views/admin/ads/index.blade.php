@extends('layouts.admin')
@section('title', 'Reklamlar')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-600">Sponsor bannerlar (ör. kuaför malzemesi firmaları). Görseller platformda barındırılır; üçüncü taraf reklam kodu veya çerez kullanılmaz. Her bannerda "Reklam" etiketi görünür.</p>
    <a href="{{ route('admin.ads.create') }}" class="btn-primary">Yeni reklam</a>
</div>
@if($ads->isEmpty())
    <x-empty title="Henüz reklam yok" :action="route('admin.ads.create')" action-label="İlk sponsor bannerını ekleyin" />
@else
<div class="table-wrap"><table class="table">
    <thead><tr><th>Banner</th><th>Reklam veren</th><th>Yer</th><th>Hedef</th><th>Tarih</th><th class="text-right">Gösterim</th><th class="text-right">Tıklama</th><th class="text-right">TO</th><th>Durum</th><th></th></tr></thead>
    <tbody>
    @foreach($ads as $ad)
        <tr>
            <td><img src="{{ $ad->imageUrl() }}" alt="{{ $ad->title }}" class="h-10 w-40 rounded object-cover ring-1 ring-slate-200"></td>
            <td><span class="font-medium">{{ $ad->advertiser }}</span><span class="block text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($ad->target_url, 40) }}</span></td>
            <td class="text-sm">{{ \App\Models\AdCampaign::PLACEMENTS[$ad->placement] ?? $ad->placement }}</td>
            <td class="text-sm">{{ $ad->city?->name ?? 'Tüm Türkiye' }}</td>
            <td class="text-xs">{{ $ad->starts_at?->timezone(config('app.display_timezone'))->format('d.m.Y') ?? '—' }} – {{ $ad->ends_at?->timezone(config('app.display_timezone'))->format('d.m.Y') ?? 'süresiz' }}</td>
            <td class="text-right tabular-nums">{{ number_format($ad->impressions, 0, ',', '.') }}</td>
            <td class="text-right tabular-nums">{{ number_format($ad->clicks, 0, ',', '.') }}</td>
            <td class="text-right tabular-nums">{{ $ad->ctr() !== null ? number_format($ad->ctr(), 2, ',', '.').'%' : '—' }}</td>
            <td>@if($ad->isRunning())<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">Yayında</span>@elseif(! $ad->active)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">Kapalı</span>@else<span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">Tarih dışı</span>@endif</td>
            <td class="whitespace-nowrap text-right">
                <a href="{{ route('admin.ads.edit', $ad) }}" class="text-sm font-semibold text-brand-700 hover:underline">Düzenle</a>
                <form method="POST" action="{{ route('admin.ads.destroy', $ad) }}" class="inline" onsubmit="return confirm('Bu reklam silinsin mi? İstatistikler de silinir.')">@csrf @method('DELETE')<button class="ml-2 text-sm text-red-700 hover:underline">Sil</button></form>
            </td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif
@endsection
