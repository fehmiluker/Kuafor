@extends('layouts.admin')
@section('title', $tenant->name)
@section('content')
@php $sub = $tenant->subscription; @endphp
<div class="grid gap-6 lg:grid-cols-3">
    <section class="card space-y-2 p-5">
        <h2 class="font-semibold">İşletme</h2>
        <p class="text-sm">Sahip: {{ $tenant->owner?->name }} ({{ $tenant->owner?->email }})</p>
        <p class="text-sm">Şube: {{ $tenant->branches_count }} · Personel: {{ $tenant->employees_count }} · Randevu (30g): {{ $appointments30d }}</p>
        <p class="text-sm">Durum: <strong>{{ $tenant->status->label() }}</strong> @if($tenant->suspension_reason)— {{ $tenant->suspension_reason }}@endif</p>
        <a href="{{ route('salon.show', $tenant->slug) }}" target="_blank" class="text-sm text-brand-700 hover:underline">Profil sayfası ↗</a>
        @if($canManage)
        <form method="POST" action="{{ route('admin.tenants.update', $tenant->id) }}" class="space-y-2 border-t border-slate-100 pt-3">@csrf
            <label class="label" for="t-st">Durum</label><select id="t-st" name="status" class="input">@foreach(\App\Enums\TenantStatus::cases() as $s)<option value="{{ $s->value }}" @selected($tenant->status === $s)>{{ $s->label() }}</option>@endforeach</select>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="public_listing_enabled" value="0"><input type="checkbox" name="public_listing_enabled" value="1" @checked($tenant->public_listing_enabled)> Aramada listelensin</label>
            <x-input name="reason" label="Gerekçe (denetim kaydına yazılır)" required />
            <button class="btn-primary">Kaydet</button>
        </form>
        @endif
    </section>
    <section class="card space-y-2 p-5 lg:col-span-2">
        <h2 class="font-semibold">Abonelik</h2>
        @if($sub)
            <p class="text-sm">{{ $sub->plan->name }} · {{ $sub->billing_interval->label() }} · <strong>{{ $sub->status->label() }}</strong> · {{ $sub->price_snapshot->format() }}</p>
            <p class="text-xs text-slate-500">Dönem: {{ $sub->current_period_start?->format('d.m.Y') }} – {{ $sub->current_period_end?->format('d.m.Y') }} · Deneme bitişi: {{ $sub->trial_ends_at?->format('d.m.Y') ?? '—' }}</p>
            <form method="POST" action="{{ route('admin.subscriptions.update', $sub) }}" class="grid gap-2 sm:grid-cols-3">@csrf
                <select name="action" class="input" aria-label="İşlem"><option value="record_payment">Manuel ödeme kaydet (dönemi aktifleştir)</option><option value="set_status">Durum değiştir</option><option value="change_plan">Paket değiştir</option><option value="extend_trial">Denemeyi uzat</option></select>
                <select name="status" class="input" aria-label="Durum"><option value="">(durum)</option>@foreach(\App\Enums\SubscriptionStatus::cases() as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select>
                <select name="plan" class="input" aria-label="Paket"><option value="">(paket)</option>@foreach($plans as $p)<option value="{{ $p->slug }}">{{ $p->name }}</option>@endforeach</select>
                <select name="interval" class="input" aria-label="Dönem"><option value="monthly">Aylık</option><option value="yearly">Yıllık</option></select>
                <input name="days" type="number" class="input" placeholder="Gün (deneme)" aria-label="Gün">
                <input name="reference" class="input" placeholder="Ödeme referansı (dekont no)" aria-label="Referans">
                <input name="reason" class="input sm:col-span-2" placeholder="Gerekçe (zorunlu)" required aria-label="Gerekçe">
                <button class="btn-primary">Uygula</button>
            </form>
            <h3 class="pt-3 text-sm font-semibold">Olaylar</h3>
            <ul class="text-xs text-slate-600">@foreach($sub->events->take(10) as $e)<li>{{ $e->created_at->format('d.m.Y H:i') }} · {{ $e->type }} {{ $e->from_status }} → {{ $e->to_status }}</li>@endforeach</ul>
            <h3 class="pt-3 text-sm font-semibold">Faturalar</h3>
            <ul class="text-xs text-slate-600">@forelse($sub->invoices as $i)<li>{{ $i->number }} · {{ $i->amount->format() }} · {{ $i->status }}</li>@empty<li>Fatura yok.</li>@endforelse</ul>
        @else
            <p class="text-sm text-slate-500">Abonelik yok.</p>
        @endif
    </section>
</div>
<section class="card mt-6 p-5"><h2 class="font-semibold">Denetim kaydı</h2>
    <ul class="mt-2 text-sm">@forelse($audit as $l)<li class="border-b border-slate-100 py-1">{{ $l->created_at->format('d.m.Y H:i') }} · {{ $l->action }} · {{ $l->actor_type }}#{{ $l->actor_id }} @if($l->reason)— {{ $l->reason }}@endif</li>@empty<li class="text-slate-500">Kayıt yok.</li>@endforelse</ul>
</section>
@endsection
