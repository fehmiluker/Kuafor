@extends('layouts.panel')
@section('title', 'Personel')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <p class="text-sm text-slate-600">{{ $usage['employees']['used'] }} / {{ $usage['employees']['limit'] }} aktif personel</p>
    @can('create', \App\Models\Employee::class)<a href="{{ route('tenant.employees.create') }}" class="btn-primary">+ Personel ekle</a>@endcan
</div>
@if($overLimit['employees'])<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-900" role="alert">Paket limitini {{ $overLimit['employees'] }} personel aşıyorsunuz. Lütfen pasifleştirmek istediğiniz personeli seçip durumunu "Pasif" yapın.</div>@endif
@if($employees->isEmpty())
    <x-empty title="Henüz personel yok" :action="route('tenant.employees.create')" actionLabel="Personel ekle">Personel ekleyip hangi hizmetleri verdiğini seçin.</x-empty>
@else
<div class="table-wrap"><table class="table">
    <thead><tr><th>Ad</th><th>Şubeler</th><th>Hizmetler</th><th>Hesap</th><th>Durum</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
    @foreach($employees as $e)
        <tr><td><a href="{{ route('tenant.employees.edit', $e) }}" class="font-medium text-brand-700 hover:underline">{{ $e->display_name }}</a></td>
            <td>{{ $e->branches->pluck('name')->implode(', ') }}</td><td class="max-w-xs">{{ $e->services->pluck('name')->unique()->implode(', ') }}</td>
            <td>{{ $e->user?->email ?? '—' }}</td><td><span class="badge {{ $e->status->value === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $e->status->label() }}</span></td></tr>
    @endforeach
    </tbody></table></div>
@endif
@endsection
