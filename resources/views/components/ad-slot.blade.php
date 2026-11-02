@props(['placement', 'cityId' => null])
@php
    $ads = app(\App\Services\Ads\AdService::class);
    $ad = $ads->pick($placement, $cityId);
    if ($ad) { $ads->recordImpression($ad); }
@endphp
@if($ad)
    <aside {{ $attributes->merge(['class' => 'relative']) }} aria-label="Reklam">
        <a href="{{ route('ads.click', $ad) }}" target="_blank" rel="sponsored noopener" class="block overflow-hidden rounded-2xl ring-1 ring-slate-200 transition hover:ring-brand-300">
            <picture>
                @if($ad->mobileImageUrl())<source media="(max-width: 640px)" srcset="{{ $ad->mobileImageUrl() }}">@endif
                <img src="{{ $ad->imageUrl() }}" alt="{{ $ad->title }}" class="block h-auto w-full" loading="lazy" decoding="async">
            </picture>
        </a>
        <span class="pointer-events-none absolute right-2 top-2 rounded-full bg-white/90 px-2 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200">Reklam</span>
    </aside>
@endif
