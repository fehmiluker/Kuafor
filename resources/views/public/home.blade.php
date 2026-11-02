@extends('layouts.public')
@section('title', config('app.name').' — Kuaför ve güzellik salonu randevusu')
@section('description', 'Size en yakın kuaför ve güzellik salonlarını bulun, fiyatları ve doğrulanmış yorumları görün, ücretsiz online randevu alın.')
@section('canonical', route('home'))
@section('content')
{{-- Hero --}}
<section class="relative flex h-[220px] items-center overflow-hidden rounded-[18px] bg-gradient-to-r from-brand-50 via-brand-50 to-[#dfe6f2] sm:h-[320px] sm:rounded-3xl">
    <img src="{{ asset('images/home/hero.jpg') }}" alt="" class="absolute inset-y-0 right-0 h-full w-[58%] object-cover object-left-top [mask-image:linear-gradient(90deg,transparent_0,#000_30%)] sm:w-[55%]" fetchpriority="high">
    <div class="relative max-w-[52%] px-3.5 py-4 sm:max-w-[52%] sm:px-11">
        <h1 class="text-[22px] font-extrabold leading-[1.14] tracking-tight text-slate-900 sm:text-[38px]">Profesyonel Kuaför ve Güzellik Hizmetleri</h1>
        <p class="mt-1.5 text-[13px] leading-snug text-slate-700 sm:mt-3 sm:text-base">Size en yakın salonlardan kolayca randevu alın.</p>
        <a href="{{ $location->isSet() ? $location->searchUrl() : route('search') }}" class="mt-3 inline-flex items-center gap-2 whitespace-nowrap rounded-full bg-brand-700 px-3.5 py-2 text-sm font-bold text-white shadow-lg shadow-brand-700/30 hover:bg-brand-800 sm:mt-5 sm:px-5 sm:py-3 sm:text-[15px]">
            <x-icon name="calendar" class="h-[18px] w-[18px]" />Hemen Randevu Al<x-icon name="chevron-right" class="h-4 w-4 stroke-[2.5]" />
        </a>
    </div>
</section>

{{-- Search --}}
<form method="GET" action="{{ $location->isSet() ? $location->searchUrl() : route('search') }}" role="search" class="relative z-10 mx-auto mt-3 flex max-w-3xl items-center gap-2.5 rounded-full border border-slate-200 bg-white px-3.5 py-1 shadow-[0_6px_20px_rgba(15,23,42,.06)] sm:-mt-7 sm:px-5 sm:py-1.5">
    <x-icon name="search" class="h-5 w-5 shrink-0 text-slate-900" />
    <label for="home-q" class="sr-only">Salon, hizmet veya konum ara</label>
    <input id="home-q" name="q" type="search" placeholder="Salon, hizmet veya konum ara..." class="min-h-10 w-full border-0 bg-transparent p-0 text-sm text-slate-900 placeholder:text-slate-500 focus:ring-0 sm:text-[15px]">
    <a href="{{ route('search') }}" class="shrink-0 text-slate-900 hover:text-brand-700" aria-label="Gelişmiş filtreler"><x-icon name="sliders" class="h-5 w-5" /></a>
</form>

{{-- Category tiles --}}
<nav aria-label="Kategoriler" class="mx-auto mt-3 grid max-w-xl grid-cols-5 gap-2 sm:mt-6 sm:gap-3.5">
    @foreach($tiles as $t)
        <a href="{{ $location->isSet() ? $location->searchUrl(['kategori' => $t['slug']]) : route('search', ['kategori' => $t['slug']]) }}" class="cat-tile tone-{{ $t['tone'] }}">
            <span class="cat-ico"><x-icon :name="$t['icon']" class="h-6 w-6 sm:h-7 sm:w-7" /></span>{{ $t['label'] }}
        </a>
    @endforeach
</nav>

{{-- Popular services --}}
<section class="mt-4 sm:mt-8" aria-labelledby="pop-h">
    <div class="mb-2.5 flex items-center justify-between"><h2 id="pop-h" class="text-lg font-extrabold tracking-tight sm:text-xl">Popüler Hizmetler</h2><a href="{{ route('search') }}" class="text-[13px] font-semibold text-brand-600 hover:underline sm:text-sm">Tümünü Gör ›</a></div>
    <div class="grid grid-cols-3 gap-2 sm:gap-4">
        @foreach($popular as $p)
            <a href="{{ $location->isSet() ? $location->searchUrl(['kategori' => $p['slug']]) : route('search', ['kategori' => $p['slug']]) }}" class="relative block h-[120px] overflow-hidden rounded-[14px] bg-slate-200 sm:h-[180px] sm:rounded-2xl">
                <img src="{{ $p['image'] }}" alt="" class="h-full w-full object-cover" loading="lazy">
                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-white via-white/95 to-transparent px-2 pb-1.5 pt-6 sm:px-3 sm:pb-2.5">
                    <span class="block text-[14px] font-bold text-slate-900 sm:text-base">{{ $p['title'] }}</span>
                    @if($p['price'])<span class="text-sm font-extrabold text-brand-700 sm:text-base">{{ $p['price']->short() }}'den</span>@endif
                </span>
            </a>
        @endforeach
    </div>
</section>

{{-- Near the chosen location --}}
<section class="mt-4 sm:mt-8" aria-labelledby="near-h">
    <div class="mb-2.5 flex items-center justify-between">
        <h2 id="near-h" class="flex items-center gap-2 text-lg font-extrabold tracking-tight sm:text-xl"><span class="grid h-[22px] w-[22px] place-items-center rounded-full bg-brand-700 text-white"><x-icon name="star" class="h-3.5 w-3.5" filled /></span>Size Yakın Salonlar</h2>
        @if($location->isSet())<a href="{{ $location->searchUrl() }}" class="flex items-center gap-1 text-[13px] font-semibold text-brand-600 hover:underline sm:text-sm"><x-icon name="map" class="h-[18px] w-[18px]" />Tümünü Gör</a>@endif
    </div>
    @if(! $location->isSet())
        <a href="{{ route('location.edit', ['geri' => '/']) }}" class="flex items-center justify-between gap-3 rounded-2xl border border-dashed border-brand-200 bg-brand-50 p-4 text-sm text-brand-900 hover:bg-brand-100">
            <span><strong class="block">Konumunuzu seçin</strong>İl ve ilçenizi seçin, size en yakın salonları gösterelim.</span><x-icon name="chevron-right" class="h-5 w-5 shrink-0" />
        </a>
    @elseif($nearby->isEmpty())
        <p class="rounded-2xl border border-slate-200 p-4 text-sm text-slate-600">{{ $location->label() }} bölgesinde henüz salon yok. <a href="{{ route('location.edit', ['geri' => '/']) }}" class="font-semibold text-brand-700 underline">Konumu değiştir</a> veya <a href="{{ route('search') }}" class="font-semibold text-brand-700 underline">tüm salonlara</a> göz atın.</p>
    @else
        <div class="grid grid-cols-2 gap-2.5 sm:gap-4 lg:grid-cols-4">@foreach($nearby as $salon)@include('public._salon-card', ['salon' => $salon, 'preferDistrictId' => $location->district?->id])@endforeach</div>
    @endif
</section>

<x-ad-slot placement="home" class="mt-4 sm:mt-8" />

{{-- Featured --}}
@if($featured->isNotEmpty())
<section class="mt-4 sm:mt-8" aria-labelledby="feat-h">
    <div class="mb-2.5 flex items-center justify-between"><h2 id="feat-h" class="flex items-center gap-2 text-lg font-extrabold tracking-tight sm:text-xl"><x-icon name="crown" class="h-[22px] w-[22px] text-brand-700" filled />Öne Çıkan Salonlar</h2><a href="{{ route('search') }}" class="text-[13px] font-semibold text-brand-600 hover:underline sm:text-sm">Tümünü Gör ›</a></div>
    <div class="grid grid-cols-2 gap-2.5 sm:gap-4 lg:grid-cols-4">@foreach($featured as $salon)@include('public._salon-card', ['salon' => $salon])@endforeach</div>
</section>
@endif
@endsection
