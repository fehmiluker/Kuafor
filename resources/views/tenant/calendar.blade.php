@extends('layouts.panel')
@section('title', 'Takvim')
@section('content')
<div class="mb-4 flex flex-wrap items-end gap-3">
    <form method="GET" class="flex items-end gap-2">
        <div><label for="c-branch" class="label">Şube</label><select id="c-branch" name="sube" class="input" onchange="this.form.submit()">@foreach($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branchId)>{{ $b->name }}</option>@endforeach</select></div>
    </form>
    <div><label for="employee-filter" class="label">Personel</label><select id="employee-filter" class="input"><option value="">Tümü</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->display_name }}</option>@endforeach</select></div>
    <a href="{{ route('tenant.appointments.create', ['sube' => $branchId]) }}" class="btn-primary ml-auto">+ Randevu</a>
</div>
<div class="mb-3 flex flex-wrap gap-3 text-xs" aria-label="Renk açıklaması">
    @foreach([\App\Enums\AppointmentStatus::Pending, \App\Enums\AppointmentStatus::Confirmed, \App\Enums\AppointmentStatus::AlternativeProposed, \App\Enums\AppointmentStatus::Completed, \App\Enums\AppointmentStatus::NoShow, \App\Enums\AppointmentStatus::Cancelled] as $s)
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded" style="background: {{ $s->color() }}"></span>{{ $s->label() }}</span>
    @endforeach
</div>
<p id="calendar-status" class="mb-2 text-sm text-slate-600" role="status" aria-live="polite">@if($canMove)Onaylı randevuları sürükleyerek taşıyabilirsiniz; müsaitlik yeniden kontrol edilir.@endif Saatler {{ $timezone }} saat dilimindedir.</p>
@if($branches->isEmpty())
    <x-empty title="Henüz şube yok" :action="route('tenant.branches.create')" actionLabel="Şube ekle">Takvimi kullanmak için önce bir şube oluşturun.</x-empty>
@else
    <div class="card p-2 sm:p-4"><div id="calendar" data-feed="{{ route('tenant.calendar.events') }}" data-branch="{{ $branchId }}" data-can-move="{{ $canMove ? 1 : 0 }}" data-drag-url="{{ route('tenant.appointments.drag', '__ID__') }}" aria-label="Randevu takvimi"></div></div>
@endif
@endsection
