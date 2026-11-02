@extends('layouts.public')
@section('title', ($invite ?? false) ? 'Şifrenizi belirleyin' : 'Yeni şifre')
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-md card p-6">
    <h1 class="text-xl font-bold">{{ ($invite ?? false) ? 'İşletme panelinize hoş geldiniz' : 'Yeni şifre belirleyin' }}</h1>
    @if($invite ?? false)<p class="mt-1 text-sm text-slate-600">Giriş yapabilmek için bir şifre belirleyin.</p>@endif
    <form method="POST" action="{{ route(($invite ?? false) ? 'invite.accept' : 'password.update') }}" class="mt-4 space-y-4">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" label="E-posta" type="email" :value="$email" required />
        <x-input name="password" label="Yeni şifre" type="password" required />
        <x-input name="password_confirmation" label="Yeni şifre (tekrar)" type="password" required />
        <button class="btn-primary w-full">Kaydet</button>
    </form>
</div>
@endsection
