@extends('layouts.panel')
@section('title', 'Özet')
@section('content')
@if($subscription?->status->value === 'trialing')
    <div class="mb-4 rounded-lg bg-brand-50 p-4 text-sm text-brand-900 ring-1 ring-brand-100">Deneme süreniz <x-local-time :at="$subscription->trial_ends_at" format="d F Y" /> tarihinde bitiyor. <a href="{{ route('tenant.subscription') }}" class="font-semibold underline">Paketleri incele</a></div>
@endif
@if($overLimit['branches'] || $overLimit['employees'])
    <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-900 ring-1 ring-red-200" role="alert">Paket limitinizi aşıyorsunuz: {{ $overLimit['branches'] }} şube ve {{ $overLimit['employees'] }} personeli pasifleştirmeniz gerekiyor. Verileriniz silinmez.</div>
@endif
<div class="grid gap-4 sm:grid-cols-3">
    <div class="card p-5"><p class="text-sm text-slate-500">Bugünkü randevular</p><p class="mt-1 text-3xl font-bold">{{ $counts['today'] }}</p></div>
    <div class="card p-5"><p class="text-sm text-slate-500">Onay bekleyen</p><p class="mt-1 text-3xl font-bold text-amber-600">{{ $counts['pending'] }}</p></div>
    <div class="card p-5"><p class="text-sm text-slate-500">Önümüzdeki 7 gün</p><p class="mt-1 text-3xl font-bold">{{ $counts['week'] }}</p></div>
</div>
<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="card p-5" aria-labelledby="pend-h">
        <div class="flex items-center justify-between"><h2 id="pend-h" class="font-semibold">Onay bekleyen talepler</h2><a href="{{ route('tenant.appointments.index', ['durum' => 'pending']) }}" class="text-sm text-brand-700 hover:underline">Tümü</a></div>
        @forelse($pending as $a)
            <a href="{{ route('tenant.appointments.show', $a) }}" class="mt-3 flex items-center justify-between rounded-lg p-2 hover:bg-slate-50">
                <span><span class="font-medium">{{ $a->customer->full_name }}</span><span class="block text-xs text-slate-500"><x-local-time :at="$a->starts_at_utc" :tz="$a->branch->timezone" format="d M D H:i" /> · {{ $a->serviceSummary() }}</span></span>
                <x-status :status="$a->status" />
            </a>
        @empty
            <p class="mt-3 text-sm text-slate-500">Bekleyen talep yok. 🎉</p>
        @endforelse
    </section>
    <section class="card p-5" aria-labelledby="today-h">
        <div class="flex items-center justify-between"><h2 id="today-h" class="font-semibold">Bugün</h2><a href="{{ route('tenant.calendar') }}" class="text-sm text-brand-700 hover:underline">Takvim</a></div>
        @forelse($today as $a)
            <a href="{{ route('tenant.appointments.show', $a) }}" class="mt-3 flex items-center justify-between rounded-lg p-2 hover:bg-slate-50">
                <span><span class="font-medium"><x-local-time :at="$a->starts_at_utc" :tz="$a->branch->timezone" format="H:i" /> {{ $a->customer->full_name }}</span><span class="block text-xs text-slate-500">{{ $a->serviceSummary() }} · {{ $a->employee?->display_name }}</span></span>
                <x-status :status="$a->status" />
            </a>
        @empty
            <p class="mt-3 text-sm text-slate-500">Bugün randevu yok.</p>
        @endforelse
    </section>
</div>
<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="card p-5"><h2 class="font-semibold">Paket kullanımı</h2>
        <p class="mt-2 text-sm">Şube: {{ $usage['branches']['used'] }} / {{ $usage['branches']['limit'] }} · Personel: {{ $usage['employees']['used'] }} / {{ $usage['employees']['limit'] }}</p>
        <p class="mt-1 text-sm">WhatsApp: {{ ($usage['features']['whatsapp'] ?? false) ? 'Açık' : 'Paketinizde yok' }}</p>
    </section>
    <section class="card p-5"><h2 class="font-semibold">Son bildirimler</h2>
        @forelse($notifications as $n)<p class="mt-2 text-sm"><span class="font-medium">{{ $n->title }}</span> — {{ \Illuminate\Support\Str::limit($n->body, 90) }}</p>@empty<p class="mt-2 text-sm text-slate-500">Yeni bildirim yok.</p>@endforelse
    </section>
</div>
@endsection
