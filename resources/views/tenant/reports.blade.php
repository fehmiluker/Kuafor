@extends('layouts.panel')
@section('title', 'Raporlar')
@section('content')
<form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
    <div><label for="r-from" class="label">Başlangıç</label><input id="r-from" type="date" name="from" value="{{ $fromLocal }}" class="input"></div>
    <div><label for="r-to" class="label">Bitiş</label><input id="r-to" type="date" name="to" value="{{ $toLocal }}" class="input"></div>
    <button class="btn-secondary">Göster</button>
</form>
<div class="grid gap-4 sm:grid-cols-4">
    <div class="card p-5"><p class="text-sm text-slate-500">Randevu</p><p class="text-2xl font-bold">{{ $summary['appointments']['total'] }}</p></div>
    <div class="card p-5"><p class="text-sm text-slate-500">Tamamlanan ciro</p><p class="text-2xl font-bold">{{ $summary['revenue_completed']['formatted'] }}</p></div>
    <div class="card p-5"><p class="text-sm text-slate-500">Gelmeme oranı</p><p class="text-2xl font-bold">%{{ $summary['no_show_rate'] }}</p></div>
    <div class="card p-5"><p class="text-sm text-slate-500">Tahsilat</p>@forelse($summary['collected_by_method'] as $m => $v)<p class="text-sm">{{ \App\Enums\PaymentMethod::from($m)->label() }}: {{ $v['formatted'] }}</p>@empty<p class="text-sm text-slate-500">—</p>@endforelse</div>
</div>
<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <section class="card p-5"><h2 class="font-semibold">Duruma göre</h2>@foreach($summary['appointments']['by_status'] as $s => $c)<p class="mt-1 flex justify-between text-sm"><span>{{ \App\Enums\AppointmentStatus::from($s)->label() }}</span><span>{{ $c }}</span></p>@endforeach</section>
    <section class="card p-5"><h2 class="font-semibold">Personel</h2>@forelse($summary['by_employee'] as $e)<p class="mt-1 flex justify-between text-sm"><span>{{ $e['name'] }} ({{ $e['count'] }})</span><span>{{ $e['revenue']['formatted'] }}</span></p>@empty<p class="text-sm text-slate-500">Veri yok.</p>@endforelse</section>
    <section class="card p-5"><h2 class="font-semibold">En çok tercih edilen hizmetler</h2>@forelse($summary['top_services'] as $s)<p class="mt-1 flex justify-between text-sm"><span>{{ $s['name'] }}</span><span>{{ $s['count'] }}</span></p>@empty<p class="text-sm text-slate-500">Veri yok.</p>@endforelse</section>
</div>
<p class="mt-4 text-xs text-slate-500">{{ $summary['note'] }}</p>
@endsection
