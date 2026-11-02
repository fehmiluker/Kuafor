{{-- Salon card with "Tekrar randevu al" (pre-filled), last visit and quick actions. $item: RebookService::items() row; $full: show call/directions/favourite actions. --}}
@php($t = $item['tenant'])
@php($b = $item['branch'])
<article class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
    <a href="{{ route('salon.show', $t->slug) }}" class="flex items-center gap-3 p-3">
        <span class="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-brand-50" @if($t->cover_path) style="background-image:url('{{ Storage::disk('public')->url($t->cover_path) }}');background-size:cover;background-position:center" @endif aria-hidden="true"></span>
        <span class="min-w-0">
            <span class="block truncate font-extrabold text-slate-900">{{ $t->name }}</span>
            <span class="block truncate text-xs text-slate-600">{{ collect([$b?->district?->name, $b?->city?->name])->filter()->implode(', ') }}</span>
            <span class="block text-xs text-slate-500">
                @if($item['last_at']){{ $item['last_at']->isFuture() ? 'Sıradaki randevu' : 'Son randevu' }}: {{ $item['last_at']->locale('tr')->diffForHumans() }}@elseif($item['favorite'])♥ Favori @endif
            </span>
        </span>
    </a>
    <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-slate-100 p-3">
        <a href="{{ $item['url'] }}" class="btn-primary btn-sm">{{ $item['last_at'] ? 'Tekrar randevu al' : 'Randevu al' }}</a>
        @if($full ?? false)
            @if($b?->phone)<a href="tel:{{ $b->phone }}" class="btn-ghost btn-sm">Ara</a>@endif
            @if($b?->mapUrl())<a href="{{ $b->mapUrl() }}" target="_blank" rel="noopener" class="btn-ghost btn-sm">Yol tarifi ↗</a>@endif
            @auth
                <form method="POST" action="{{ route('account.favorites.toggle', $t->slug) }}" class="ml-auto">@csrf
                    <button class="btn-ghost btn-sm {{ $item['favorite'] ? 'text-rose-600' : '' }}" aria-pressed="{{ $item['favorite'] ? 'true' : 'false' }}">{{ $item['favorite'] ? '♥ Favoriden çıkar' : '♡ Favorilere ekle' }}</button>
                </form>
            @endauth
        @endif
    </div>
</article>
