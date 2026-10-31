@php $branch = $salon->branches->first(); @endphp
<article class="card flex flex-col overflow-hidden">
    <div class="h-28 bg-gradient-to-br from-brand-100 to-brand-50" @if($salon->cover_path) style="background-image:url('{{ Storage::disk('public')->url($salon->cover_path) }}');background-size:cover;background-position:center" @endif role="img" aria-label="{{ $salon->name }} kapak görseli"></div>
    <div class="flex flex-1 flex-col gap-2 p-4">
        <h3 class="text-base font-semibold"><a href="{{ route('salon.show', $salon->slug) }}" class="hover:underline">{{ $salon->name }}</a></h3>
        <p class="text-sm text-slate-500">
            @if($branch){{ collect([$branch->district?->name, $branch->city?->name])->filter()->implode(', ') }}@endif
            @if($salon->branches->count() > 1) · {{ $salon->branches->count() }} şube @endif
        </p>
        <div class="flex items-center justify-between text-sm">
            @if($salon->rating_count)<x-stars :value="$salon->rating_avg" :count="$salon->rating_count" />@else<span class="text-slate-400">Henüz değerlendirme yok</span>@endif
            @if($salon->min_service_price)<span class="text-slate-600">{{ \App\Support\Money::parse((string) $salon->min_service_price)->format() }}'den</span>@endif
        </div>
        <a href="{{ route('salon.book', $salon->slug) }}" class="btn-primary mt-auto">Randevu al</a>
    </div>
</article>
