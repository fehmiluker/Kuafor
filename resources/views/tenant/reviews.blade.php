@extends('layouts.panel')
@section('title', 'Değerlendirmeler')
@section('content')
<p class="mb-4 text-sm text-slate-600">Değerlendirmeler yalnızca tamamlanan randevulardan gelir. Silemezsiniz; tek bir yanıt yazabilir (düzenleyebilir) veya kurallara aykırıysa platforma bildirebilirsiniz.</p>
@forelse($reviews as $r)
    <article class="card mb-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-medium">{{ $r->authorDisplayName() }} · {{ $r->branch?->name }} @if($r->employee)· {{ $r->employee->display_name }}@endif</p>
            <div class="flex items-center gap-2"><x-stars :value="$r->rating" /><span class="badge bg-slate-100 text-slate-600">{{ $r->status->label() }}</span></div></div>
        @if($r->body)<p class="mt-2 text-sm">{{ $r->body }}</p>@endif
        <p class="mt-1 text-xs text-slate-400">{{ $r->created_at->format('d.m.Y') }}</p>
        <form method="POST" action="{{ route('tenant.reviews.reply', $r) }}" class="mt-3 space-y-2">@csrf
            <label class="label" for="rep-{{ $r->id }}">{{ $r->reply ? 'Yanıtınızı düzenleyin' : 'Yanıt yazın' }}</label>
            <textarea id="rep-{{ $r->id }}" name="body" rows="2" class="input" required>{{ $r->reply?->body }}</textarea>
            <button class="btn-secondary btn-sm">Kaydet</button>
        </form>
        <details class="mt-2 text-sm"><summary class="cursor-pointer text-slate-500">Platforma bildir</summary>
            <form method="POST" action="{{ route('tenant.reviews.report', $r) }}" class="mt-2 space-y-2">@csrf<textarea name="reason" rows="2" class="input" minlength="10" required placeholder="Neden kurallara aykırı? (en az 10 karakter)" aria-label="Bildirim nedeni"></textarea><button class="btn-ghost btn-sm">Bildir</button></form>
        </details>
    </article>
@empty
    <x-empty title="Henüz değerlendirme yok">Tamamlanan randevulardan sonra müşterilerinize otomatik değerlendirme daveti gönderilir.</x-empty>
@endforelse
{{ $reviews->links() }}
@endsection
