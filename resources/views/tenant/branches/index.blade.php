@extends('layouts.panel')
@section('title', 'Şubeler')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <p class="text-sm text-slate-600">{{ $usage['branches']['used'] }} / {{ $usage['branches']['limit'] }} aktif şube</p>
    @if($canCreate)<a href="{{ route('tenant.branches.create') }}" class="btn-primary">+ Şube ekle</a>@endif
</div>
@if($branches->isEmpty())
    <x-empty title="Henüz şube eklemediniz" :action="$canCreate ? route('tenant.branches.create') : null" actionLabel="İlk şubeyi ekle">Randevu alabilmek için en az bir şube, çalışma saatleri, hizmet ve personel gerekir.</x-empty>
@else
<div class="grid gap-4 sm:grid-cols-2">
    @foreach($branches as $b)
        <div class="card p-5">
            <div class="flex items-start justify-between"><h2 class="font-semibold">{{ $b->name }}</h2><span class="badge {{ $b->status->value === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $b->status->label() }}</span></div>
            <p class="mt-1 text-sm text-slate-600">{{ $b->address }} {{ collect([$b->district?->name, $b->city?->name])->filter()->implode(', ') }}</p>
            <p class="mt-1 text-xs text-slate-500">WhatsApp: @if($b->whatsappConnection && $b->whatsappConnection->status->value === 'connected')Kendi numarası ({{ $b->whatsappConnection->display_phone }})@elseif(\App\Models\WhatsAppConnection::platform()?->isUsable())Platform numarası @else Kapalı @endif</p>
            <div class="mt-3 flex gap-2"><a href="{{ route('tenant.branches.edit', $b) }}" class="btn-secondary btn-sm">Düzenle</a><a href="{{ route('tenant.branches.whatsapp', $b) }}" class="btn-ghost btn-sm">WhatsApp</a><a href="{{ route('tenant.calendar', ['sube' => $b->id]) }}" class="btn-ghost btn-sm">Takvim</a></div>
        </div>
    @endforeach
</div>
@endif
@endsection
