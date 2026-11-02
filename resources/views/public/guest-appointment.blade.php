@extends('layouts.public')
@section('title', 'Randevunuz · '.$tenant->name)
@section('noindex', '1')
@section('content')
@php $tz = $a->branch->timezone; @endphp
<div class="mx-auto max-w-xl space-y-4">
    <div class="card p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Randevu no: {{ $a->public_reference }}</p>
                <h1 class="text-xl font-bold">{{ $tenant->name }} — {{ $a->branch->name }}</h1>
            </div>
            <x-status :status="$a->status" />
        </div>
        <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
            <dt class="text-slate-500">Tarih</dt><dd class="col-span-2 font-medium"><x-local-time :at="$a->starts_at_utc" :tz="$tz" format="d F Y l, H:i" /></dd>
            <dt class="text-slate-500">Hizmet</dt><dd class="col-span-2">{{ $a->serviceSummary() }}</dd>
            <dt class="text-slate-500">Personel</dt><dd class="col-span-2">{{ $a->employee?->display_name }}</dd>
            <dt class="text-slate-500">Tutar</dt><dd class="col-span-2">{{ $a->total_price->format() }} <span class="text-xs text-slate-500">(salonda ödenir)</span></dd>
            <dt class="text-slate-500">Adres</dt><dd class="col-span-2">{{ $a->branch->address }} @if($a->branch->mapUrl())<a href="{{ $a->branch->mapUrl() }}" target="_blank" rel="noopener" class="text-brand-700 underline">Harita</a>@endif</dd>
        </dl>
        @if($a->status->value === 'pending')<p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Talebiniz salon onayı bekliyor. Onaylandığında size bildirim gönderilecek.</p>@endif
        @if($a->status->value === 'alternative_proposed' && $a->openProposal)
            <div class="mt-4 rounded-lg bg-brand-50 p-3 text-sm text-brand-900">
                Salon size <strong><x-local-time :at="$a->openProposal->proposed_start_utc" :tz="$tz" format="d F l H:i" /></strong> saatini önerdi.
                Size gönderilen WhatsApp/e-posta bağlantısından kabul veya reddedebilirsiniz.
            </div>
        @endif
    </div>
    @if($canCancel)
        <form method="POST" action="{{ route('guest.cancel', $a->public_reference) }}" class="card space-y-3 p-5" onsubmit="return confirm('Randevuyu iptal etmek istediğinize emin misiniz?')">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <h2 class="font-semibold">Randevuyu iptal et</h2>
            <p class="text-sm text-slate-500">Onaylı randevular başlangıçtan {{ $cutoff }} dakika öncesine kadar online iptal edilebilir.</p>
            <x-input name="reason" label="İptal nedeni (isteğe bağlı)" />
            <button class="btn-danger">İptal et</button>
        </form>
    @endif
    <p class="text-center text-xs text-slate-500">Bu sayfanın bağlantısı size özeldir. Lütfen paylaşmayın.</p>
</div>
@endsection
