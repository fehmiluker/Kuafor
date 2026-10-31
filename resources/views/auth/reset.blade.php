@extends('layouts.public')
@section('title', 'Yeni şifre')
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-md card p-6">
    <h1 class="text-xl font-bold">Yeni şifre belirleyin</h1>
    <form method="POST" action="{{ route('password.update') }}" class="mt-4 space-y-4">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" label="E-posta" type="email" :value="$email" required />
        <x-input name="password" label="Yeni şifre" type="password" required />
        <x-input name="password_confirmation" label="Yeni şifre (tekrar)" type="password" required />
        <button class="btn-primary w-full">Kaydet</button>
    </form>
</div>
@endsection
