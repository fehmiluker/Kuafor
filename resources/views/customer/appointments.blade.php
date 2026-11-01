@extends('layouts.public')
@section('title', 'Randevularım')
@section('noindex', '1')
@section('content')
@include('customer._nav')
@unless(auth()->user()->phone_verified_at)
    <div class="mb-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">Üye olmadan aldığınız randevuları görmek için <a href="{{ route('account.profile') }}" class="font-semibold underline">telefonunuzu doğrulayın</a>.</div>
@endunless
<h1 class="text-2xl font-extrabold text-violet-950">Yaklaşan randevular</h1>
@forelse($upcoming as $a)
    <div class="card mt-4 flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="font-semibold">{{ $tenants[$a->tenant_id]?->name }} — {{ $a->branch?->name }}</p>
            <p class="text-sm text-slate-600"><x-local-time :at="$a->starts_at_utc" :tz="$a->branch->timezone" format="d F l, H:i" /> · {{ $a->serviceSummary() }} · {{ $a->employee?->display_name }}</p>
        </div>
        <div class="flex items-center gap-2"><x-status :status="$a->status" />
            <form method="POST" action="{{ route('account.appointments.cancel', $a->id) }}" onsubmit="return confirm('İptal edilsin mi?')">@csrf<button class="btn-secondary btn-sm">İptal et</button></form></div>
    </div>
@empty
    <x-empty title="Yaklaşan randevunuz yok" :action="route('search')" actionLabel="Salon bul">Size uygun salonu bulup birkaç adımda randevu alın.</x-empty>
@endforelse

<h2 class="mt-8 text-lg font-semibold">Geçmiş</h2>
<div class="table-wrap mt-3"><table class="table">
    <thead><tr><th>Salon</th><th>Tarih</th><th>Hizmet</th><th>Durum</th><th></th></tr></thead>
    <tbody>
    @forelse($past as $a)
        <tr><td>{{ $tenants[$a->tenant_id]?->name }}</td><td><x-local-time :at="$a->starts_at_utc" :tz="$a->branch->timezone" format="d.m.Y H:i" /></td><td>{{ $a->serviceSummary() }}</td><td><x-status :status="$a->status" /></td>
            <td>@if($a->status->value === 'completed' && !$a->review)<span class="text-xs text-slate-500">Değerlendirme bağlantısı e-posta/WhatsApp ile gönderilir</span>@elseif($a->review)<x-stars :value="$a->review->rating" />@endif</td></tr>
    @empty
        <tr><td colspan="5" class="text-center text-slate-500">Geçmiş randevu yok.</td></tr>
    @endforelse
    </tbody></table></div>
{{ $past->links() }}
@endsection
