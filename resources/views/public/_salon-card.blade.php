@php
    $cards = app(\App\Services\Catalog\SalonCards::class);
    // Show the branch in the visitor's chosen/searched district when the salon has several.
    $branch = (isset($preferDistrictId) ? $salon->branches->firstWhere('district_id', $preferDistrictId) : null) ?? $salon->branches->first();
    $place = $branch ? collect([$branch->district?->name, $branch->city?->name])->filter()->implode(', ') : null;
    $fav = $cards->isFavorite($salon, auth()->user());
    $tags = $cards->tags($salon);
@endphp
<article class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
    <a href="{{ route('salon.show', $salon->slug) }}" class="relative block h-[107px] bg-gradient-to-br from-brand-100 via-slate-100 to-brand-50 sm:h-36" tabindex="-1" aria-hidden="true">
        @if($salon->cover_path)
            <img src="{{ Storage::disk('public')->url($salon->cover_path) }}" alt="" class="h-full w-full object-cover" loading="lazy" decoding="async">
        @else
            <span class="absolute inset-0 grid place-items-center text-brand-300"><x-icon name="store" class="h-10 w-10" /></span>
        @endif
        <span class="absolute bottom-2 left-2 inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-xs font-bold text-slate-900 shadow-sm">
            @if($salon->is_showcase)Örnek işletme@elseif($salon->rating_count)<span class="text-amber-500">★</span>{{ number_format((float) $salon->rating_avg, 1, ',', '') }} <span class="font-medium text-slate-500">({{ $salon->rating_count }})</span>@else Yeni @endif
        </span>
    </a>
    @if($salon->is_showcase)
    @elseauth
        <form method="POST" action="{{ route('account.favorites.toggle', $salon->slug) }}" class="absolute right-2 top-2 z-10">@csrf
            <button class="grid h-8 w-8 place-items-center rounded-full bg-white shadow {{ $fav ? 'text-rose-600' : 'text-slate-800' }}" aria-pressed="{{ $fav ? 'true' : 'false' }}" aria-label="{{ $salon->name }} {{ $fav ? 'favorilerden çıkar' : 'favorilere ekle' }}"><x-icon name="heart" class="h-4 w-4" :filled="$fav" /></button>
        </form>
    @else
        <a href="{{ route('login') }}" class="absolute right-2 top-2 z-10 grid h-8 w-8 place-items-center rounded-full bg-white text-slate-800 shadow" aria-label="Favorilere eklemek için giriş yapın"><x-icon name="heart" class="h-4 w-4" /></a>
    @endif
    <div class="flex flex-1 flex-col px-2.5 pb-2.5 pt-2 sm:px-3 sm:pb-3">
        <h3 class="truncate text-[14.5px] font-extrabold text-slate-900 sm:text-base"><a href="{{ route('salon.show', $salon->slug) }}" class="after:absolute after:inset-0 after:rounded-2xl after:content-[''] focus:outline-none focus-visible:after:ring-2 focus-visible:after:ring-brand-500">{{ $salon->name }}</a></h3>
        @if($place)<p class="mt-0.5 flex items-center gap-1 truncate text-xs text-slate-600 sm:text-[13px]"><x-icon name="pin" class="h-3.5 w-3.5 shrink-0 text-brand-700" filled />{{ $place }}@if($salon->branches->count() > 1)<span class="hidden sm:inline">&nbsp;· {{ $salon->branches->count() }} şube</span>@endif</p>@endif
        @if($tags)
            <div class="mt-2 flex gap-1.5 overflow-hidden">@foreach($tags as $t)<span class="whitespace-nowrap rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700 sm:text-xs">{{ $t }}</span>@endforeach</div>
        @endif
    </div>
</article>
