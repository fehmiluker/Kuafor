@extends('layouts.panel')
@section('title', 'Randevular')
@section('content')
<div class="mb-5 rounded-3xl bg-gradient-to-r from-brand-100 to-brand-50 p-5 ring-1 ring-slate-200"><h2 class="text-2xl font-extrabold text-slate-900">Randevu yönetimi</h2><p class="mt-1 text-sm text-brand-700">Müşterilerinizi, saatleri ve randevu durumlarını kolayca takip edin.</p></div>
<form method="GET" class="mb-4 grid gap-2 sm:grid-cols-5">
    <label class="sr-only" for="a-q">Ara</label><input id="a-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Müşteri adı veya randevu no" class="input sm:col-span-2">
    <label class="sr-only" for="a-s">Durum</label><select id="a-s" name="durum" class="input"><option value="">Tüm durumlar</option>@foreach(\App\Enums\AppointmentStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['durum'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
    <label class="sr-only" for="a-d">Tarih</label><input id="a-d" type="date" name="tarih" value="{{ $filters['tarih'] ?? '' }}" class="input">
    <div class="flex gap-2"><button class="btn-secondary flex-1">Filtrele</button><a href="{{ route('tenant.appointments.create') }}" class="btn-primary">+ Yeni</a></div>
</form>
@if($appointments->isEmpty())
    <x-empty title="Randevu bulunamadı" :action="route('tenant.appointments.create')" actionLabel="Randevu oluştur">Filtreleri değiştirin veya yeni randevu ekleyin.</x-empty>
@else
<div class="table-wrap"><table class="table">
    <thead><tr><th>Tarih</th><th>Müşteri</th><th>Hizmet</th><th>Personel</th><th>Şube</th><th>Durum</th><th class="text-right">Tutar</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
    @foreach($appointments as $a)
        <tr class="hover:bg-slate-50">
            <td><a href="{{ route('tenant.appointments.show', $a) }}" class="font-medium text-brand-700 hover:underline"><x-local-time :at="$a->starts_at_utc" :tz="$a->branch->timezone" format="d.m.Y H:i" /></a><span class="block text-xs text-slate-400">{{ $a->public_reference }}</span></td>
            <td>{{ $a->customer->full_name }}</td><td>{{ $a->serviceSummary() }}</td><td>{{ $a->employee?->display_name }}</td><td>{{ $a->branch->name }}@if($a->isHomeVisit()) <span class="badge bg-brand-50 text-brand-700">🏠 Adreste</span>@endif</td>
            <td><x-status :status="$a->status" /></td><td class="text-right">{{ $a->total_price->format() }}</td>
        </tr>
    @endforeach
    </tbody></table></div>
{{ $appointments->links() }}
@endif
@endsection
