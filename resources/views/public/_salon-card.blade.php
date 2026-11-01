@php $branch = $salon->branches->first(); @endphp
<article class="card group flex flex-col overflow-hidden transition duration-200 hover:-translate-y-1 hover:shadow-xl hover:shadow-violet-100">
    <div class="h-44 bg-gradient-to-br from-fuchsia-100 via-violet-100 to-indigo-100 sm:h-48" @if($salon->cover_path) style="background-image:url('{{ Storage::disk('public')->url($salon->cover_path) }}');background-size:cover;background-position:center" @endif role="img" aria-label="{{ $salon->name }} kapak görseli"></div>
    <div class="flex flex-1 flex-col gap-3 p-5">
        <h3 class="text-lg font-bold text-violet-950"><a href="{{ route('salon.show', $salon->slug) }}" class="hover:underline">{{ $salon->name }}</a></h3>
        <p class="text-sm text-slate-500">
            @if($branch){{ collect([$branch->district?->name, $branch->city?->name])->filter()->implode(', ') }}@endif
            @if($salon->branches->count() > 1) · {{ $salon->branches->count() }} şube @endif
        </p>
        <div class="flex items-center justify-between text-sm">
            @if($salon->rating_count)<x-stars :value="$salon->rating_avg" :count="$salon->rating_count" />@else<span class="text-slate-400">Henüz değerlendirme yok</span>@endif
            @if($salon->min_service_price)<span class="text-slate-600">{{ \App\Support\Money::parse((string) $salon->min_service_price)->format() }}'den</span>@endif
        </div>
        <a href="{{ route('salon.book', $salon->slug) }}" class="btn-primary mt-auto w-full">Randevu al</a>
    </div>
</article>
