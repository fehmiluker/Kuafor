@extends('layouts.public')
@php
    $place = collect([$district?->name, $city?->name])->filter()->implode(', ');
    $catName = $categories->firstWhere('slug', $filters['kategori'] ?? null)?->name;
    $heading = trim(($place ? $place.' ' : '').($catName ?? 'kuaför ve güzellik salonları'));
@endphp
@section('title', ucfirst($heading).' — '.config('app.name'))
@section('description', ($place ?: 'Türkiye').' bölgesindeki '.($catName ? mb_strtolower($catName) : 'kuaför ve güzellik').' salonları: fiyatlar, yorumlar ve online randevu.')
@section('canonical', url()->current())
@section('content')
<h1 class="text-xl font-bold sm:text-2xl">{{ ucfirst($heading) }}</h1>
<div class="mt-4 grid gap-6 lg:grid-cols-[18rem_1fr]">
    <form method="GET" action="{{ url()->current() }}" class="card h-fit space-y-4 p-4" aria-label="Filtreler">
        <div>
            <label class="label" for="s-city">Şehir</label>
            <select id="s-city" class="input" onchange="window.location = this.value ? '{{ url('salonlar') }}/' + this.value + window.location.search : '{{ route('search') }}' + window.location.search">
                <option value="">Tüm şehirler</option>
                @foreach($cities as $c)<option value="{{ $c->slug }}" @selected($city?->id === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
        </div>
        @if($city && $districts->isNotEmpty())
            <div>
                <label class="label" for="s-district">İlçe</label>
                <select id="s-district" class="input" onchange="window.location = '{{ url('salonlar/'.$city->slug) }}' + (this.value ? '/' + this.value : '') + window.location.search">
                    <option value="">Tüm ilçeler</option>
                    @foreach($districts as $d)<option value="{{ $d->slug }}" @selected($district?->id === $d->id)>{{ $d->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div><label class="label" for="s-cat">Kategori</label><select id="s-cat" name="kategori" class="input"><option value="">Tümü</option>@foreach($categories as $cat)<option value="{{ $cat->slug }}" @selected(($filters['kategori'] ?? '') === $cat->slug)>{{ $cat->name }}</option>@endforeach</select></div>
        <x-input name="hizmet" label="Hizmet adı" :value="$filters['hizmet'] ?? ''" placeholder="ör. keratin" />
        <x-input name="q" label="Salon adı" :value="$filters['q'] ?? ''" />
        <div class="grid grid-cols-2 gap-2">
            <x-input name="min" label="Min ₺" type="number" min="0" :value="$filters['min'] ?? ''" />
            <x-input name="max" label="Maks ₺" type="number" min="0" :value="$filters['max'] ?? ''" />
        </div>
        <div class="grid grid-cols-2 gap-2">
            <x-input name="tarih" label="Müsait tarih" type="date" :min="now('Europe/Istanbul')->toDateString()" :value="$filters['tarih'] ?? ''" />
            <x-input name="saat" label="Saatten sonra" type="time" :value="$filters['saat'] ?? ''" />
        </div>
        <div><label class="label" for="s-sort">Sıralama</label><select id="s-sort" name="sirala" class="input">
            @foreach(['rating' => 'En yüksek puan', 'price_asc' => 'Fiyat (artan)', 'price_desc' => 'Fiyat (azalan)', 'name' => 'İsim', 'newest' => 'En yeni'] as $k => $l)<option value="{{ $k }}" @selected(($filters['sirala'] ?? 'rating') === $k)>{{ $l }}</option>@endforeach
        </select></div>
        <button class="btn-primary w-full">Filtrele</button>
    </form>
    <section aria-live="polite">
        <p class="mb-3 text-sm text-slate-500">{{ $results->total() }} salon bulundu</p>
        @if($results->isEmpty())
            <x-empty title="Aramanıza uygun salon bulunamadı" :action="route('search')" actionLabel="Filtreleri temizle">Farklı bir tarih, ilçe veya kategori deneyin.</x-empty>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">@foreach($results as $salon)@include('public._salon-card', ['salon' => $salon])@endforeach</div>
            {{ $results->links() }}
        @endif
    </section>
</div>
@endsection
