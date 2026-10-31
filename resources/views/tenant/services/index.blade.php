@extends('layouts.panel')
@section('title', 'Hizmetler')
@section('content')
@if($canManage)<div class="mb-4 flex justify-end"><a href="{{ route('tenant.services.create') }}" class="btn-primary">+ Hizmet ekle</a></div>@endif
@if($services->isEmpty())
    <x-empty title="Henüz hizmet eklemediniz" :action="$canManage ? route('tenant.services.create') : null" actionLabel="Hizmet ekle">Süre, fiyat ve hazırlık tamponu ile hizmetlerinizi tanımlayın.</x-empty>
@else
<div class="table-wrap"><table class="table">
    <thead><tr><th>Hizmet</th><th>Kategori</th><th>Süre</th><th>Tampon</th><th class="text-right">Fiyat</th><th>Şube</th><th>Durum</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
    @foreach($services as $s)
        <tr><td>@if($canManage)<a href="{{ route('tenant.services.edit', $s) }}" class="font-medium text-brand-700 hover:underline">{{ $s->name }}</a>@else{{ $s->name }}@endif</td>
            <td>{{ $s->category?->name }}</td><td>{{ $s->duration_minutes }} dk</td><td>{{ $s->buffer_minutes }} dk</td><td class="text-right">{{ $s->base_price->format() }}</td>
            <td>{{ $s->branchServices->where('active', true)->count() }}</td><td>{{ $s->active ? 'Aktif' : 'Pasif' }}</td></tr>
    @endforeach
    </tbody></table></div>
@endif
@endsection
