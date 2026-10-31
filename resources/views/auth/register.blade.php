@extends('layouts.public')
@section('title', $business ? 'İşletme hesabı oluştur' : 'Üye ol')
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-lg card p-6">
    <h1 class="text-xl font-bold">{{ $business ? 'İşletmenizi kaydedin' : 'Üye ol' }}</h1>
    @if($business)<p class="mt-1 text-sm text-slate-500">Ücretsiz deneme ile başlayın. Kredi kartı gerekmez.</p>@endif
    <form method="POST" action="{{ route('register') }}" class="mt-4 space-y-4">
        @csrf
        <x-input name="name" label="Ad soyad" autocomplete="name" required />
        <x-input name="email" label="E-posta" type="email" autocomplete="email" required />
        <x-input name="phone" label="Cep telefonu" type="tel" placeholder="05xx xxx xx xx" autocomplete="tel" required />
        <x-input name="password" label="Şifre" type="password" autocomplete="new-password" help="En az 10 karakter, harf ve rakam içermeli." required />
        <x-input name="password_confirmation" label="Şifre (tekrar)" type="password" autocomplete="new-password" required />
        @if($business)
            <x-input name="business_name" label="İşletme adı" required />
            <fieldset><legend class="label">Paket</legend>
                <div class="grid gap-2 sm:grid-cols-3">@foreach($plans as $plan)
                    <label class="card cursor-pointer p-3 text-sm has-[:checked]:ring-2 has-[:checked]:ring-brand-600">
                        <input type="radio" name="plan" value="{{ $plan->slug }}" class="sr-only" @checked(old('plan', 'profesyonel') === $plan->slug)>
                        <span class="block font-semibold">{{ $plan->name }}</span><span class="block">{{ $plan->monthly_price->format() }}/ay</span>
                        <span class="block text-xs text-slate-500">{{ $plan->max_branches }} şube · {{ $plan->max_employees }} personel · WhatsApp {{ $plan->hasFeature('whatsapp') ? '✓' : '✗' }}</span>
                    </label>@endforeach</div>
                <p class="mt-1 text-xs text-slate-500">{{ $plans->first()?->trial_days }} gün ücretsiz deneme. Fiyatlar KDV dahil demo değerlerdir.</p>
            </fieldset>
        @endif
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="kvkk_acknowledged" value="1" class="mt-1" required> <span><a href="{{ route('kvkk') }}" target="_blank" class="text-brand-700 underline">Aydınlatma metnini</a> ve <a href="{{ route('terms') }}" target="_blank" class="text-brand-700 underline">kullanım koşullarını</a> okudum.</span></label>
        <button class="btn-primary w-full">Hesap oluştur</button>
    </form>
</div>
@endsection
