@extends('layouts.admin')
@section('title', 'Salon başvuruları')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <nav class="flex flex-wrap gap-1 text-sm" aria-label="Duruma göre">
        @foreach(\App\Enums\SalonApplicationStatus::cases() as $s)
            <a href="{{ route('admin.onboarding.applications', ['durum' => $s->value]) }}" class="rounded-full px-3 py-1.5 {{ $status === $s ? 'bg-brand-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200' }}" @if($status === $s) aria-current="page" @endif>{{ $s->label() }} ({{ $counts[$s->value] ?? 0 }})</a>
        @endforeach
        <a href="{{ route('admin.onboarding.applications', ['durum' => 'tumu']) }}" class="rounded-full px-3 py-1.5 {{ $status === null ? 'bg-brand-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200' }}">Tümü</a>
    </nav>
    <form method="POST" action="{{ route('admin.onboarding.toggle') }}" class="flex items-center gap-3 text-sm">@csrf
        <span>Başvuru formu (<a href="{{ route('salon-application.create') }}" class="text-brand-700 underline" target="_blank">/salonunu-ekle</a>):
            <strong class="{{ $enabled ? 'text-emerald-700' : 'text-slate-500' }}">{{ $enabled ? 'Açık' : 'Kapalı' }}</strong></span>
        <input type="hidden" name="enabled" value="{{ $enabled ? 0 : 1 }}">
        <button class="btn-secondary btn-sm">{{ $enabled ? 'Kapat' : 'Aç' }}</button>
    </form>
</div>
@if($applications->isEmpty())
    <x-empty title="Bu durumda başvuru yok" />
@else
<div class="table-wrap"><table class="table">
    <thead><tr><th>İşletme</th><th>Yetkili</th><th>Yer</th><th>Kategoriler</th><th>Tarih</th><th>Durum</th></tr></thead>
    <tbody>
    @foreach($applications as $a)
        <tr>
            <td><a href="{{ route('admin.onboarding.application', $a->id) }}" class="font-medium text-brand-700 hover:underline">{{ $a->business_name }}</a></td>
            <td class="text-sm">{{ $a->contact_name }}<span class="block text-xs text-slate-500">{{ $a->email }}</span></td>
            <td class="text-sm">{{ collect([$a->district_name, $a->city?->name])->filter()->implode(', ') }}</td>
            <td class="text-xs">{{ count($a->categories ?? []) }}</td>
            <td class="text-xs">{{ $a->created_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }}</td>
            <td class="text-xs">{{ $a->status->label() }}</td>
        </tr>
    @endforeach
    </tbody></table></div>
{{ $applications->links() }}
@endif
@endsection
