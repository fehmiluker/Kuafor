@extends('layouts.public')
@section('title', 'Alternatif saat teklifi · '.$tenant->name)
@section('noindex', '1')
@section('content')
@php $tz = $a->branch->timezone; $open = $p->status->value === 'open' && $p->expires_at_utc->isFuture(); @endphp
<div class="mx-auto max-w-xl card p-6">
    <h1 class="text-xl font-bold">{{ $tenant->name }} size yeni bir saat önerdi</h1>
    <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
        
        <dt class="text-slate-500">Önerilen</dt><dd class="col-span-2 text-lg font-semibold"><x-local-time :at="$p->proposed_start_utc" :tz="$tz" format="d F Y l, H:i" /></dd>
        <dt class="text-slate-500">Personel</dt><dd class="col-span-2">{{ $p->employee?->display_name }}</dd>
        <dt class="text-slate-500">Hizmet</dt><dd class="col-span-2">{{ $a->serviceSummary() }}</dd>
        <dt class="text-slate-500">Geçerlilik</dt><dd class="col-span-2"><x-local-time :at="$p->expires_at_utc" :tz="$tz" format="d F H:i" /> tarihine kadar</dd>
    </dl>
    @if($p->note)<p class="mt-3 rounded bg-slate-50 p-3 text-sm">Salonun notu: {{ $p->note }}</p>@endif
    @if($open)
        <div class="mt-6 grid grid-cols-2 gap-3">
            <form method="POST" action="{{ route('proposal.respond', [$p->id, 'kabul']) }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><button class="btn-primary w-full">Kabul et</button></form>
            <form method="POST" action="{{ route('proposal.respond', [$p->id, 'red']) }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><button class="btn-secondary w-full">Reddet</button></form>
        </div>
    @else
        <p class="mt-6 rounded-lg bg-slate-100 p-3 text-sm">Bu teklif {{ $p->status->label() }}. @if($a->status->value === 'confirmed') Randevunuz onaylı: <x-local-time :at="$a->starts_at_utc" :tz="$tz" format="d F l H:i" />@endif</p>
    @endif
</div>
@endsection
