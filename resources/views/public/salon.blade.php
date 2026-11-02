@extends('layouts.public')
@section('title', $tenant->name.' — Fiyatlar, yorumlar ve online randevu')
@section('description', \Illuminate\Support\Str::limit($tenant->description ?: $tenant->name.' için hizmetler, güncel fiyatlar, çalışma saatleri ve doğrulanmış müşteri yorumları.', 155))
@section('canonical', route('salon.show', $tenant->slug))
@section('content')
@php $schema = ['@context' => 'https://schema.org', '@type' => 'HairSalon', 'name' => $tenant->name, 'url' => route('salon.show', $tenant->slug)]
    + ($tenant->rating_count ? ['aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => (float) $tenant->rating_avg, 'reviewCount' => $tenant->rating_count]] : [])
    + ($branches->first()?->address ? ['address' => $branches->first()->address] : []); @endphp
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
<div class="overflow-hidden rounded-3xl bg-white shadow-lg shadow-slate-100/50 ring-1 ring-slate-200">
    <div class="h-52 bg-gradient-to-br from-brand-100 via-brand-100 to-indigo-100 sm:h-72" @if($tenant->cover_path) style="background-image:url('{{ Storage::disk('public')->url($tenant->cover_path) }}');background-size:cover;background-position:center" @endif role="img" aria-label="{{ $tenant->name }} kapak"></div>
    <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex items-center gap-4">
            @if($tenant->logo_path)<img src="{{ Storage::disk('public')->url($tenant->logo_path) }}" alt="{{ $tenant->name }} logosu" class="-mt-14 h-20 w-20 rounded-xl bg-white object-cover ring-4 ring-white">@endif
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900 sm:text-3xl">{{ $tenant->name }}</h1>
                @if($tenant->rating_count)<x-stars :value="$tenant->rating_avg" :count="$tenant->rating_count" />@endif
            </div>
        </div>
        <div class="flex gap-2">
            @unless($tenant->is_showcase)
                @auth<form method="POST" action="{{ route('account.favorites.toggle', $tenant->slug) }}">@csrf<button class="btn-secondary" aria-pressed="{{ $isFavorite ? 'true' : 'false' }}">{{ $isFavorite ? '♥ Favorilerde' : '♡ Favorilere ekle' }}</button></form>@endauth
                <a href="{{ route('salon.book', $tenant->slug) }}" class="btn-primary">Randevu al</a>
            @endunless
        </div>
    </div>
</div>
@if($tenant->is_showcase)
    <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="note"><strong>Örnek işletme.</strong> Bu sayfa platformun nasıl görüneceğini göstermek için hazırlanmıştır; bu işletmeden online randevu alınamaz. <a href="{{ route('search') }}" class="font-semibold underline">Randevu alabileceğiniz salonlara göz atın.</a></p>
@endif

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        @if($tenant->description)<section class="card p-5"><h2 class="text-lg font-bold text-slate-900">Hakkında</h2><p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $tenant->description }}</p></section>@endif

        @if($media->isNotEmpty())
            <section class="card p-5" aria-labelledby="g-h"><h2 id="g-h" class="text-lg font-bold text-slate-900">Fotoğraflar</h2>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">@foreach($media as $m)<img src="{{ $m->url() }}" alt="{{ $m->alt_text }}" loading="lazy" class="aspect-square w-full rounded-2xl object-cover">@endforeach</div>
            </section>
        @endif

        @foreach($branches as $b)
            <section class="card p-5" aria-labelledby="svc-{{ $b->id }}">
                <h2 id="svc-{{ $b->id }}" class="text-lg font-bold text-slate-900">Hizmetler @if($branches->count() > 1)— {{ $b->name }}@endif</h2>
                @php $list = $servicesByBranch[$b->id]; @endphp
                @if($list->isEmpty())<p class="mt-2 text-sm text-slate-500">Bu şubede henüz online randevuya açık hizmet yok.</p>@endif
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach($list as $s)
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div><p class="font-medium">{{ $s->name }}</p><p class="text-xs text-slate-500">{{ $s->category?->name }} · {{ $s->effective_duration }} dk</p></div>
                            <div class="flex items-center gap-3"><span class="text-lg font-bold text-slate-900">{{ $s->effective_price->format() }}</span>
                                @unless($tenant->is_showcase)<a href="{{ route('salon.book', ['slug' => $tenant->slug, 'sube' => $b->id, 'hizmet' => $s->id]) }}" class="btn-secondary btn-sm">Seç</a>@endunless</div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <section class="card p-5" aria-labelledby="rev-h">
            <h2 id="rev-h" class="text-lg font-bold text-slate-900">Doğrulanmış değerlendirmeler</h2>
            <p class="text-xs text-slate-500">Yalnızca bu salonda randevusu tamamlanan müşteriler değerlendirme yazabilir.</p>
            @forelse($reviews as $r)
                <article class="mt-4 border-t border-slate-100 pt-4">
                    <div class="flex items-center justify-between"><p class="font-medium">{{ $r->authorDisplayName() }} <span class="badge bg-emerald-50 text-emerald-700">✓ Doğrulanmış</span></p><x-stars :value="$r->rating" /></div>
                    @if($r->body)<p class="mt-1 text-sm text-slate-700">{{ $r->body }}</p>@endif
                    <p class="mt-1 text-xs text-slate-400">{{ $r->created_at->locale('tr')->diffForHumans() }} @if($r->employee) · {{ $r->employee->display_name }} @endif</p>
                    @if($r->reply)<div class="mt-2 rounded-lg bg-slate-50 p-3 text-sm"><p class="text-xs font-semibold text-slate-600">İşletmenin yanıtı</p><p>{{ $r->reply->body }}</p></div>@endif
                </article>
            @empty
                <p class="mt-3 text-sm text-slate-500">Henüz değerlendirme yok.</p>
            @endforelse
            {{ $reviews->links() }}
        </section>
    </div>

    <aside class="space-y-6">
        @foreach($branches as $b)
            <section class="card p-5" aria-label="{{ $b->name }} bilgileri">
                <h2 class="text-lg font-bold text-slate-900">{{ $b->name }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ $b->address }}</p>
                <p class="text-sm text-slate-600">{{ collect([$b->district?->name, $b->city?->name])->filter()->implode(', ') }}</p>
                @if($b->phone)<p class="mt-1 text-sm"><a href="tel:{{ $b->phone }}" class="text-brand-700 hover:underline">{{ $b->phone }}</a></p>@endif
                @if($b->mapUrl())<a href="{{ $b->mapUrl() }}" target="_blank" rel="noopener" class="mt-2 inline-block text-sm text-brand-700 hover:underline">Haritada aç ↗</a>@endif
                <h3 class="mt-4 text-sm font-semibold">Çalışma saatleri</h3>
                <dl class="mt-1 text-sm">
                    @foreach([1,2,3,4,5,6,0] as $dow)
                        @php $h = $b->workingHours->where('day_of_week', $dow)->sortBy('starts_local'); @endphp
                        <div class="flex justify-between py-0.5"><dt>{{ \App\Models\WorkingHour::DAY_NAMES[$dow] }}</dt><dd class="text-slate-600">{{ $h->isEmpty() ? 'Kapalı' : $h->map(fn($x) => substr($x->starts_local,0,5).'–'.substr($x->ends_local,0,5))->implode(', ') }}</dd></div>
                    @endforeach
                </dl>
                @unless($tenant->is_showcase)<a href="{{ route('salon.book', ['slug' => $tenant->slug, 'sube' => $b->id]) }}" class="btn-primary mt-4 w-full">Bu şubeden randevu al</a>@endunless
            </section>
        @endforeach
        @if($employees->isNotEmpty())
            <section class="card p-5"><h2 class="text-lg font-bold text-slate-900">Ekibimiz</h2>
                <ul class="mt-3 space-y-2">@foreach($employees as $e)<li class="flex items-center gap-3">
                    @if($e->photo)<img src="{{ Storage::disk('public')->url($e->photo) }}" alt="" class="h-9 w-9 rounded-full object-cover">@else<span aria-hidden="true" class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-700">{{ mb_substr($e->display_name, 0, 1) }}</span>@endif
                    <span class="text-sm">{{ $e->display_name }}</span></li>@endforeach</ul>
            </section>
        @endif
    </aside>
</div>
@endsection
