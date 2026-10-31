@extends('layouts.panel')
@section('title', 'Müşteriler')
@section('content')
<form method="GET" class="mb-4 flex gap-2"><label for="c-q" class="sr-only">Ara</label><input id="c-q" name="q" value="{{ $term }}" placeholder="Ad veya telefon" class="input max-w-sm"><button class="btn-secondary">Ara</button></form>
@if($customers->isEmpty())
    <x-empty title="Müşteri bulunamadı">Randevu alan müşteriler burada otomatik oluşur. Müşteri kartları yalnızca sizin işletmenize özeldir.</x-empty>
@else
<div class="table-wrap"><table class="table">
    <thead><tr><th>Ad</th><th>Telefon</th><th>Randevu</th><th>Son ziyaret</th><th>Durum</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
    @foreach($customers as $c)
        <tr><td><a href="{{ route('tenant.customers.show', $c) }}" class="font-medium text-brand-700 hover:underline">{{ $c->full_name }}</a>@if($c->user_id) <span class="badge bg-slate-100 text-slate-600">Üye</span>@endif</td>
            <td>{{ $c->phone_e164 }}</td><td>{{ $c->appointments_count }}</td><td>{{ $c->last_visit_at?->format('d.m.Y') ?? '—' }}</td><td>{{ $c->status->label() }}</td></tr>
    @endforeach
    </tbody></table></div>
{{ $customers->links() }}
@endif
@endsection
