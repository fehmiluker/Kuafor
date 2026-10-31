@extends('layouts.public')
@section('title', 'Giriş yap')
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-md card p-6">
    <h1 class="text-xl font-bold">Giriş yap</h1>
    <form method="POST" action="{{ route('login') }}" class="mt-4 space-y-4">
        @csrf
        <x-input name="email" label="E-posta" type="email" autocomplete="email" required />
        <x-input name="password" label="Şifre" type="password" autocomplete="current-password" required />
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1"> Beni hatırla</label>
        <button class="btn-primary w-full">Giriş yap</button>
    </form>
    <div class="mt-4 flex justify-between text-sm"><a href="{{ route('password.request') }}" class="text-brand-700 hover:underline">Şifremi unuttum</a><a href="{{ route('register') }}" class="text-brand-700 hover:underline">Üye ol</a></div>
</div>
@endsection
