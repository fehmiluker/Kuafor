@extends('layouts.admin')
@section('title', 'Platform özeti')
@section('content')
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card p-5"><p class="text-sm text-slate-500">İşletme</p><p class="text-3xl font-bold">{{ $m['tenants']['total'] }}</p><p class="text-xs text-slate-500">@foreach($m['tenants']['by_status'] as $s => $c){{ \App\Enums\TenantStatus::from($s)->label() }}: {{ $c }} @endforeach</p></div>
    <div class="card p-5"><p class="text-sm text-slate-500">MRR</p><p class="text-3xl font-bold">{{ $m['mrr']['formatted'] }}</p><p class="text-xs text-slate-500">ARR {{ $m['arr']['formatted'] }}</p></div>
    <div class="card p-5"><p class="text-sm text-slate-500">Randevu (30 gün)</p><p class="text-3xl font-bold">{{ $m['appointments']['last_30d'] }}</p><p class="text-xs text-slate-500">Bugün: {{ $m['appointments']['today'] }}</p></div>
    <div class="card p-5"><p class="text-sm text-slate-500">WhatsApp başarı (7 gün)</p><p class="text-3xl font-bold">{{ $m['whatsapp_7d']['success_rate'] !== null ? '%'.$m['whatsapp_7d']['success_rate'] : '—' }}</p><p class="text-xs text-slate-500">{{ $m['whatsapp_7d']['total'] }} mesaj</p></div>
</div>
<p class="mt-2 text-xs text-slate-500">MRR varsayımları: {{ $m['mrr_assumptions'] }}</p>
<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <section class="card p-5"><h2 class="font-semibold">Abonelikler</h2>@foreach($m['subscriptions'] as $s => $c)<p class="mt-1 flex justify-between text-sm"><span>{{ \App\Enums\SubscriptionStatus::from($s)->label() }}</span><span>{{ $c }}</span></p>@endforeach</section>
    <section class="card p-5"><h2 class="font-semibold">Genel</h2><p class="mt-1 text-sm">Şube: {{ $m['branches'] }}</p><p class="text-sm">Kullanıcı: {{ $m['users'] }}</p></section>
    <section class="card p-5"><h2 class="font-semibold">Uyarılar</h2>
        <p class="mt-1 text-sm">Açık yorum şikâyeti: <a href="{{ route('admin.moderation') }}" class="font-semibold text-brand-700">{{ $m['abuse']['open_review_reports'] }}</a></p>
        <p class="text-sm">Kötüye kullanım bildirimi: {{ $m['abuse']['open_abuse_reports'] }}</p>
        <p class="text-sm">Başarısız webhook: {{ $m['abuse']['failed_webhooks'] }} · Başarısız iş: {{ $m['abuse']['failed_jobs'] }}</p>
    </section>
</div>
@endsection
