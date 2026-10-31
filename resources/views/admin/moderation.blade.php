@extends('layouts.admin')
@section('title', 'Yorum moderasyonu')
@section('content')
<nav class="mb-4 flex gap-2">@foreach(['open' => 'Açık', 'upheld' => 'Kabul edilen', 'dismissed' => 'Reddedilen'] as $k => $l)<a href="{{ route('admin.moderation', ['durum' => $k]) }}" class="chip {{ request('durum', 'open') === $k ? 'chip-active' : '' }}">{{ $l }}</a>@endforeach</nav>
@forelse($reports as $r)
    <article class="card mb-4 p-5">
        <p class="text-xs text-slate-500">#{{ $r->id }} · {{ $tenants[$r->review?->tenant_id] ?? '—' }} · Bildiren: {{ $r->reporter_type }} {{ $r->is_appeal ? '(itiraz)' : '' }} · {{ $r->created_at->format('d.m.Y H:i') }}</p>
        <p class="mt-1 text-sm"><strong>Bildirim nedeni:</strong> {{ $r->reason }}</p>
        @if($r->review)<div class="mt-2 rounded bg-slate-50 p-3 text-sm"><x-stars :value="$r->review->rating" /> <span class="badge bg-slate-200">{{ $r->review->status->label() }}</span><p class="mt-1">{{ $r->review->body ?: '(yorum metni yok)' }}</p></div>@endif
        @if($r->status === 'open' && $r->review)
            <form method="POST" action="{{ route('admin.moderate', $r->review_id) }}" class="mt-3 grid gap-2 sm:grid-cols-4">@csrf
                <input type="hidden" name="report_id" value="{{ $r->id }}">
                <select name="status" class="input" aria-label="Karar"><option value="hidden">Gizle (bildirimi kabul et)</option><option value="published">Yayında tut (bildirimi reddet)</option><option value="under_review">İncelemeye al</option></select>
                <input name="reason" class="input sm:col-span-2" placeholder="Karar gerekçesi (zorunlu, kaydedilir)" required aria-label="Gerekçe">
                <button class="btn-primary">Karar ver</button>
            </form>
        @else
            <p class="mt-2 text-xs text-slate-500">Karar: {{ $r->status }} — {{ $r->decision_reason }}</p>
        @endif
    </article>
@empty
    <x-empty title="Bekleyen bildirim yok">Yeni bildirimler burada görünecek.</x-empty>
@endforelse
{{ $reports->links() }}
@endsection
